<?php namespace DBDiff\DB\Schema;

use Diff\Differ\MapDiffer;
use Diff\Differ\ListDiffer;

use DBDiff\Diff\AlterTableEngine;
use DBDiff\Diff\AlterTableCollation;

use DBDiff\Diff\AlterTableAddColumn;
use DBDiff\Diff\AlterTableChangeColumn;
use DBDiff\Diff\AlterTableDropColumn;

use DBDiff\Diff\AlterTableAddKey;
use DBDiff\Diff\AlterTableChangeKey;
use DBDiff\Diff\AlterTableDropKey;

use DBDiff\Diff\AlterTableAddConstraint;
use DBDiff\Diff\AlterTableChangeConstraint;
use DBDiff\Diff\AlterTableDropConstraint;

use DBDiff\Logger;


class TableSchema {

    protected $manager;

    function __construct($manager) {
        $this->manager = $manager;
    }

    /**
     * Returns a normalised schema map for $table on the named connection.
     * Delegates to the active DBAdapter so that MySQL, Postgres and SQLite
     * all return the same structure shape.
     */
    public function getSchema(string $connection, string $table): array {
        return $this->manager->getTableSchema($connection, $table);
    }

    public function getDiff($table) {
        Logger::info("Now calculating schema diff for table `$table`");

        $diffSequence = [];
        $sourceSchema = $this->getSchema('source', $table);
        $targetSchema = $this->getSchema('target', $table);

        $driver = $this->manager->getDriver();

        // Engine — MySQL only
        if ($driver === 'mysql') {
            $sourceEngine = $sourceSchema['engine'];
            $targetEngine = $targetSchema['engine'];
            if ($sourceEngine != $targetEngine && !empty($sourceEngine) && !empty($targetEngine)) {
                $diffSequence[] = new AlterTableEngine($table, $sourceEngine, $targetEngine);
            }
        }

        // Collation — MySQL only
        if ($driver === 'mysql') {
            $sourceCollation = $sourceSchema['collation'];
            $targetCollation = $targetSchema['collation'];
            if ($sourceCollation != $targetCollation) {
                $diffSequence[] = new AlterTableCollation($table, $sourceCollation, $targetCollation);
            }
        }

        // Columns
        $sourceColumns = $sourceSchema['columns'];
        $targetColumns = $targetSchema['columns'];
        
        // Filter out ignored fields
        $params = \DBDiff\Params\ParamsFactory::get();
        $ignoredFields = \DBDiff\Params\TableFilter::getFieldsToIgnore($table, $params);
        foreach ($ignoredFields as $fieldToIgnore) {
            unset($sourceColumns[$fieldToIgnore]);
            unset($targetColumns[$fieldToIgnore]);
        }

        $differ = new MapDiffer();
        $diffs = $differ->doDiff($targetColumns, $sourceColumns);

        // Collect columns being dropped and detect generated column cascades
        $droppedColumns = [];
        foreach ($diffs as $column => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                $droppedColumns[$column] = $targetColumns[$column] ?? '';
            }
        }
        // Skip generated columns whose dependency is also being dropped (CASCADE handles them)
        $cascadedColumns = [];
        if ($driver === 'pgsql' && count($droppedColumns) > 1) {
            foreach ($droppedColumns as $col => $def) {
                if (preg_match('/GENERATED\s+ALWAYS\s+AS\s+\((.+)\)\s+STORED/i', $def, $m)) {
                    $expr = $m[1];
                    foreach ($droppedColumns as $otherCol => $otherDef) {
                        if ($otherCol !== $col && !preg_match('/GENERATED\s+ALWAYS\s+AS\s+/i', $otherDef)
                            && preg_match('/\b' . preg_quote($otherCol, '/') . '\b/', $expr)) {
                            $cascadedColumns[$col] = true;
                            break;
                        }
                    }
                }
            }
        }

        // Build ordinal map from source column order (for correct ADD COLUMN ordering)
        $sourceOrdinal = array_flip(array_keys($sourceColumns));

        // Detect generated columns that reference other changing columns (Postgres only).
        // These must be split into DROP + ADD to avoid dependency conflicts.
        $splitToDropAdd = [];
        if ($driver === 'pgsql') {
            $changingCols = [];
            foreach ($diffs as $column => $diff) {
                if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                    $changingCols[$column] = true;
                }
            }
            foreach ($diffs as $column => $diff) {
                if (!($diff instanceof \Diff\DiffOp\DiffOpChange)) {
                    continue;
                }
                $oldDef = $diff->getOldValue();
                if (preg_match('/GENERATED\s+ALWAYS\s+AS\s+\((.+)\)\s+STORED/i', $oldDef, $m)) {
                    $expr = $m[1];
                    foreach ($changingCols as $otherCol => $_) {
                        if ($otherCol !== $column
                            && preg_match('/\b' . preg_quote($otherCol, '/') . '\b/', $expr)) {
                            $splitToDropAdd[$column] = true;
                            break;
                        }
                    }
                }
            }
        }

        foreach ($diffs as $column => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                if (!isset($cascadedColumns[$column])) {
                    $diffSequence[] = new AlterTableDropColumn($table, $column, $diff);
                }
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                if (isset($splitToDropAdd[$column])) {
                    // Split into DROP (using old def) + ADD (using new def)
                    $dropDiff = new \Diff\DiffOp\DiffOpRemove($diff->getOldValue());
                    $dropCol = new AlterTableDropColumn($table, $column, $dropDiff);
                    $dropCol->isGeneratedDep = true;
                    $diffSequence[] = $dropCol;
                    $addDiff = new \Diff\DiffOp\DiffOpAdd($diff->getNewValue());
                    $addCol = new AlterTableAddColumn($table, $column, $addDiff);
                    $addCol->ordinal = $sourceOrdinal[$column] ?? PHP_INT_MAX;
                    $addCol->isGenerated = true;
                    $diffSequence[] = $addCol;
                } else {
                    $changeCol = new AlterTableChangeColumn($table, $column, $diff);
                    $oldDef = $diff->getOldValue();
                    if (preg_match('/GENERATED\s+ALWAYS\s+AS\s+\(.+\)\s+STORED/i', $oldDef)
                        || preg_match('/GENERATED\s+.*AS\s+IDENTITY/i', $oldDef)) {
                        $changeCol->isGenerated = true;
                    }
                    $diffSequence[] = $changeCol;
                }
            } else if ($diff instanceof \Diff\DiffOp\DiffOpAdd) {
                $addCol = new AlterTableAddColumn($table, $column, $diff);
                $addCol->ordinal = $sourceOrdinal[$column] ?? null;
                $newDef = $diff->getNewValue();
                if (preg_match('/GENERATED\s+ALWAYS\s+AS\s+\(.+\)\s+STORED/i', $newDef)) {
                    $addCol->isGenerated = true;
                }
                $diffSequence[] = $addCol;
            }
        }

        // Keys
        $sourceKeys = $sourceSchema['keys'];
        $targetKeys = $targetSchema['keys'];
        $differ = new MapDiffer();
        $diffs = $differ->doDiff($targetKeys, $sourceKeys);
        foreach ($diffs as $key => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                $diffSequence[] = new AlterTableDropKey($table, $key, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                $diffSequence[] = new AlterTableChangeKey($table, $key, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpAdd) {
                $diffSequence[] = new AlterTableAddKey($table, $key, $diff);
            }
        }

        // Constraints
        $sourceConstraints = $sourceSchema['constraints'];
        $targetConstraints = $targetSchema['constraints'];
        $differ = new MapDiffer();
        $diffs = $differ->doDiff($targetConstraints, $sourceConstraints);
        foreach ($diffs as $name => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                $diffSequence[] = new AlterTableDropConstraint($table, $name, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                $diffSequence[] = new AlterTableChangeConstraint($table, $name, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpAdd) {
                $diffSequence[] = new AlterTableAddConstraint($table, $name, $diff);
            }
        }

        return $diffSequence;
    }

}
