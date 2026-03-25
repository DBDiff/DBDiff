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
        if (isset($params->fieldsToIgnore[$table])) {
            foreach ($params->fieldsToIgnore[$table] as $fieldToIgnore) {
                unset($sourceColumns[$fieldToIgnore]);
                unset($targetColumns[$fieldToIgnore]);
            }
        }

        $differ = new MapDiffer();
        $diffs = $differ->doDiff($targetColumns, $sourceColumns);
        foreach ($diffs as $column => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                $diffSequence[] = new AlterTableDropColumn($table, $column, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                $diffSequence[] = new AlterTableChangeColumn($table, $column, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpAdd) {
                $diffSequence[] = new AlterTableAddColumn($table, $column, $diff);
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
