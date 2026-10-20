<?php namespace DBDiff\DB\Schema;

use DBDiff\DB\Support\MySQLColumns;

use Diff\Differ\MapDiffer;
use Diff\Differ\ListDiffer;

use DBDiff\Diff\AlterTableEngine;
use DBDiff\Diff\AlterTablePersistence;
use DBDiff\Diff\AlterTableOptions;
use DBDiff\Diff\AlterTableReplicaIdentity;
use DBDiff\Diff\AlterTableColumnStatistics;
use DBDiff\Diff\AlterTableColumnStorage;
use DBDiff\Diff\AlterTableCollation;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\DB\Support\PostgresColumnDefinition;
use DBDiff\DB\Support\PostgresSchemaHelper;
use DBDiff\DB\Schema\GeneratedColumnPlan;

use DBDiff\Diff\AlterTableAddColumn;
use DBDiff\Diff\AlterTableRenameColumn;
use DBDiff\Diff\AlterTableRenameKey;
use DBDiff\Diff\AlterTableRenameConstraint;
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

    /**
     * Tables the migration renames, new name => old — see TableRenames. A
     * key or constraint here that names one by its old name follows the
     * rename by itself.
     *
     * @var array<string, string>
     */
    public array $tableRenames = [];

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

    public function getDiff(string $table, ?array $sourceSchema = null, ?array $targetSchema = null): array {
        Logger::info("Now calculating schema diff for table `$table`");

        $diffSequence = [];
        $sourceSchema = $sourceSchema ?? $this->getSchema('source', $table);
        $targetSchema = $targetSchema ?? $this->getSchema('target', $table);

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

        if ($driver === 'pgsql') {
            $diffSequence = array_merge(
                $diffSequence,
                self::tablePropertyDiffs($table, $sourceSchema, $targetSchema)
            );
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
        $diffs = $driver === 'mysql'
            ? self::comparedAs([MySQLColumns::class, 'comparable'], $differ, $targetColumns, $sourceColumns)
            : $differ->doDiff($targetColumns, $sourceColumns);

        // A column renamed, not dropped and added: its data stays.
        $columnRenames = [];
        $keptNotNull = [];
        if ($driver === 'pgsql' && ($params->renames ?? 'auto') !== 'off') {
            [$diffs, $columnRenames, $renameDiffs, $keptNotNull] = $this->columnRenameDiffs($table, $diffs, $sourceSchema, $targetSchema);
            $diffSequence = array_merge($diffSequence, $renameDiffs);
        }

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

        // Stored generated columns are dropped and re-added rather than
        // altered — see GeneratedColumnPlan (issue #233).
        $generatedPlan = $driver === 'pgsql'
            ? GeneratedColumnPlan::plan($diffs)
            : ['attach' => [], 'regenerate' => [], 'dropFirst' => []];
        /** @var array<string, AlterTableChangeColumn> $changes */
        $changes = [];

        foreach ($diffs as $column => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                if (!isset($cascadedColumns[$column])) {
                    $dropCol = new AlterTableDropColumn($table, $column, $diff);
                    // Ahead of the change to a column it reads, which PostgreSQL
                    // refuses while it is there (issue #233).
                    $dropCol->isGeneratedDep = isset($generatedPlan['dropFirst'][$column]);
                    $diffSequence[] = $dropCol;
                }
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                if (isset($generatedPlan['attach'][$column])) {
                    // Regenerated inside the change of the column it reads;
                    // attached below, once that change exists.
                    continue;
                }
                $changeCol = new AlterTableChangeColumn($table, $column, $diff);
                $oldDef = $diff->getOldValue();
                if (preg_match('/GENERATED\s+ALWAYS\s+AS\s+\(.+\)\s+STORED/i', $oldDef)
                    || preg_match('/GENERATED\s+.*AS\s+IDENTITY/i', $oldDef)) {
                    $changeCol->isGenerated = true;
                }
                if ($driver === 'pgsql' && PostgresColumnDefinition::needsSerialSequence($oldDef, $diff->getNewValue())) {
                    $this->attachSerialSequence($changeCol, $table, $column);
                }
                // Read from the target: that is the database the migration
                // runs against, and its views, policies and triggers are the
                // ones in the way of a column type change (issue #226). Asked
                // only when the type does change — nothing else is blocked.
                //
                // A column the table inherits — a partition's, or a child's
                // under INHERITS — takes its type from the parent, whose own
                // change carries it here and whose dependants include this
                // table's (issue #232).
                $inherited = in_array($column, $targetSchema['inheritedColumns'] ?? [], true);
                $changeCol->typeInherited = $inherited;
                $regenerate = isset($generatedPlan['regenerate'][$column]);
                if ($driver === 'pgsql' && !$inherited
                    && ($regenerate || PostgresDialect::changesColumnType($oldDef, $diff->getNewValue()))) {
                    $changeCol->regenerated = $regenerate;
                    $changeCol->dependants = self::withUpDefinition(
                        $this->manager->getColumnDependants('target', $table, $column, $regenerate),
                        $regenerate ? [$column => $diff->getNewValue()] : []
                    );
                }
                $changes[$column] = $changeCol;
                $diffSequence[] = $changeCol;
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

        $this->attachGeneratedColumns($table, $diffs, $generatedPlan, $changes, $diffSequence);

        if ($driver === 'pgsql') {
            $diffSequence = array_merge(
                $diffSequence,
                self::storageDiffs($table, $sourceSchema, $targetSchema, $diffs, array_keys($sourceColumns))
            );
        }

        return array_merge(
            $diffSequence,
            $this->keyDiffs($table, $sourceSchema['keys'], $targetSchema['keys'], $columnRenames,
                $driver === 'mysql' ? [$sourceSchema['constraints'] ?? [], $targetSchema['constraints'] ?? []] : null),
            $this->constraintDiffs($table, $sourceSchema['constraints'], $targetSchema['constraints'], $columnRenames, $keptNotNull)
        );
    }

    /**
     * The diff of two column maps compared through `$comparable`, carrying
     * each column's definition as its server wrote it.
     */
    private static function comparedAs(callable $comparable, MapDiffer $differ, array $target, array $source): array {
        $diffs = $differ->doDiff(array_map($comparable, $target), array_map($comparable, $source));
        foreach ($diffs as $column => $diff) {
            $diffs[$column] = match (true) {
                $diff instanceof \Diff\DiffOp\DiffOpChange => new \Diff\DiffOp\DiffOpChange($target[$column], $source[$column]),
                $diff instanceof \Diff\DiffOp\DiffOpAdd    => new \Diff\DiffOp\DiffOpAdd($source[$column]),
                $diff instanceof \Diff\DiffOp\DiffOpRemove => new \Diff\DiffOp\DiffOpRemove($target[$column]),
                default => $diff,
            };
        }
        return $diffs;
    }

    /**
     * The renames among a table's column diffs, as RENAME COLUMNs — with,
     * from PostgreSQL 18, the NOT NULL constraint named after the column,
     * whose default name follows it — and the diffs that are left.
     *
     * @return array{0: array, 1: array<string, string>, 2: list<object>, 3: list<string>} the NOT NULLs a rename keeps as they are last
     */
    private function columnRenameDiffs(string $table, array $diffs, array $sourceSchema, array $targetSchema): array {
        [$diffs, $columnRenames] = ColumnRenames::detect($table, $diffs, [
            'source' => ColumnRenames::namedNotNullColumns($sourceSchema['constraints'] ?? []),
            'target' => ColumnRenames::namedNotNullColumns($targetSchema['constraints'] ?? []),
        ]);
        $out = [];
        $kept = [];
        foreach ($columnRenames as $to => $from) {
            $out[] = new AlterTableRenameColumn($table, $to, $from);
            $notNull = ColumnRenames::defaultNotNull($this->manager->getDB('target'), $table, $from, $to);
            if ($notNull === null) {
                continue;
            }
            // A column renamed on the source kept its NOT NULL's old name,
            // and the rename gives the target's the same: it stays as it is.
            if (isset($sourceSchema['constraints'][$notNull[0]])) {
                $kept[] = $notNull[0];
            } elseif (!isset($sourceSchema['constraints'][$notNull[1]])) {
                $out[] = new AlterTableRenameConstraint($table, $notNull[1], $notNull[0]);
            }
        }
        return [$diffs, $columnRenames, $out, $kept];
    }

    /**
     * Index and key diffs; one a rename carried along is renamed or left alone.
     *
     * MySQL keeps an index for every foreign key and refuses to drop the last
     * one a foreign key can use. So with `$foreignKeys` — each side's
     * constraints — a key whose leading columns are a foreign key's is marked:
     * one dropped is dropped once the keys added have been, and the DOWN of
     * one added likewise (see DiffSorter).
     */
    private function keyDiffs(string $table, array $sourceKeys, array $targetKeys, array $columnRenames, ?array $foreignKeys = null): array {
        $out = [];
        [$kept, $renamed] = ColumnRenames::carried($columnRenames, $targetKeys, $sourceKeys);
        $kept += ColumnRenames::carried($this->tableRenames, $targetKeys, $sourceKeys)[0];
        foreach ($renamed as $from => $to) {
            $out[] = new AlterTableRenameKey($table, $to, $from);
            unset($targetKeys[$from], $sourceKeys[$to]);
        }
        $diffs = array_diff_key((new MapDiffer())->doDiff($targetKeys, $sourceKeys), $kept);
        foreach ($diffs as $key => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                $drop = new AlterTableDropKey($table, $key, $diff);
                $drop->forForeignKey = $foreignKeys !== null && self::servesForeignKey((string) $diff->getOldValue(), $foreignKeys[0]);
                $out[] = $drop;
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                $out[] = new AlterTableChangeKey($table, $key, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpAdd) {
                $add = new AlterTableAddKey($table, $key, $diff);
                $add->forForeignKey = $foreignKeys !== null && self::servesForeignKey((string) $diff->getNewValue(), $foreignKeys[1]);
                $out[] = $add;
            }
        }
        return $out;
    }

    /** Whether a MySQL key definition's leading columns are those of one of the foreign keys among `$constraints`. */
    private static function servesForeignKey(string $keyDefinition, array $constraints): bool {
        $columns = fn(string $list): array => preg_match_all('/`((?:[^`]|``)+)`/', $list, $m) ? $m[1] : [];
        if (!preg_match('/\(([^)]*)\)/', $keyDefinition, $keyList)) {
            return false;
        }
        $keyColumns = $columns($keyList[1]);
        foreach ($constraints as $constraint) {
            if (preg_match('/\bFOREIGN KEY\s*\(([^)]*)\)/i', (string) $constraint, $fk)) {
                $fkColumns = $columns($fk[1]);
                if ($fkColumns !== [] && array_slice($keyColumns, 0, count($fkColumns)) === $fkColumns) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Constraint diffs; one a rename carried along is renamed or left alone. */
    private function constraintDiffs(string $table, array $sourceConstraints, array $targetConstraints, array $columnRenames, array $keptNotNull = []): array {
        $out = [];
        [$kept, $renamed] = ColumnRenames::carried($columnRenames, $targetConstraints, $sourceConstraints);
        $kept += array_fill_keys($keptNotNull, true);
        $kept += ColumnRenames::carried($this->tableRenames, $targetConstraints, $sourceConstraints)[0];
        foreach ($renamed as $from => $to) {
            $out[] = new AlterTableRenameConstraint($table, $to, $from);
            unset($targetConstraints[$from], $sourceConstraints[$to]);
        }
        $diffs = array_diff_key((new MapDiffer())->doDiff($targetConstraints, $sourceConstraints), $kept);
        foreach ($diffs as $name => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                $out[] = new AlterTableDropConstraint($table, $name, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                $out[] = new AlterTableChangeConstraint($table, $name, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpAdd) {
                $out[] = new AlterTableAddConstraint($table, $name, $diff);
            }
        }
        return $out;
    }


    /**
     * Settle the generated columns each column change drops and re-adds.
     *
     * A retyped column's dependant lookup finds, from the target's catalog,
     * the generated columns reading it. Of those, one the diff also changes is
     * re-added from the source's definition on the way up (the plan's
     * `attach`); one the diff removes or regenerates on its own is left to its
     * own statements. An `attach` column the lookup did not find — it reads
     * the retyped column only in the source — is regenerated on its own
     * instead.
     *
     * @param array<string, object>                $diffs
     * @param array<string, AlterTableChangeColumn> $changes
     */
    private function attachGeneratedColumns(
        string $table,
        array $diffs,
        array $plan,
        array $changes,
        array &$diffSequence
    ): void {
        $attached = [];
        foreach ($changes as $change) {
            if (empty($change->dependants['generated']) || $change->regenerated) {
                continue;
            }
            $kept = [];
            foreach ($change->dependants['generated'] as $generated) {
                $name = $generated['name'];
                // Kept under every retyped column it reads, so those changes
                // are linked into one bracket below; re-added once, there.
                if (isset($plan['attach'][$name])) {
                    $generated['upDefinition'] = $diffs[$name]->getNewValue();
                    $attached[$name] = true;
                } elseif (isset($diffs[$name])) {
                    continue;
                }
                $kept[] = $generated;
            }
            $change->dependants['generated'] = $kept;
        }

        self::groupLinkedChanges($changes);

        foreach ($plan['attach'] as $name => $_) {
            if (isset($attached[$name])) {
                continue;
            }
            $change = new AlterTableChangeColumn($table, $name, $diffs[$name]);
            $change->isGenerated = true;
            $change->regenerated = true;
            $change->dependants = self::withUpDefinition(
                $this->manager->getColumnDependants('target', $table, $name, true),
                [$name => $diffs[$name]->getNewValue()]
            );
            $diffSequence[] = $change;
        }
    }

    /**
     * Make column changes linked through a shared generated dependant one bracket.
     *
     * `x GENERATED ALWAYS AS (a + b)` with both `a` and `b` retyped: in two
     * brackets, `x` came back after `a` was retyped and before `b` was, and
     * `b`'s change was refused. Changes are grouped by the generated columns
     * they share (transitively), and the first of each group, by column name,
     * carries the rest.
     *
     * @param array<string, AlterTableChangeColumn> $changes
     */
    private static function groupLinkedChanges(array $changes): void {
        $parent = [];
        $find = function (string $c) use (&$parent, &$find): string {
            return ($parent[$c] ?? $c) === $c ? $c : ($parent[$c] = $find($parent[$c]));
        };
        $readers = [];
        foreach ($changes as $column => $change) {
            foreach ($change->dependants['generated'] ?? [] as $generated) {
                $readers[$generated['name']][] = $column;
            }
        }
        foreach ($readers as $columns) {
            foreach (array_slice($columns, 1) as $other) {
                $parent[$find($other)] = $find($columns[0]);
            }
        }

        $groups = [];
        foreach (array_keys($changes) as $column) {
            $groups[$find($column)][] = $column;
        }
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            sort($members);
            $carrier = $changes[array_shift($members)];
            foreach ($members as $column) {
                $carried = $changes[$column];
                $carrier->dependants = ColumnDependantPlan::mergeDependants($carrier->dependants, $carried->dependants);
                $carrier->upSkip += $carried->upSkip;
                $carrier->coChanges[] = $carried;
                $carried->carriedBy = $carrier;
            }
        }
    }

    /**
     * Mark the generated columns re-added from the source's definition on the
     * way up: `$definitions` maps a column name to that definition.
     */
    private static function withUpDefinition(?array $dependants, array $definitions): ?array {
        if ($dependants === null || $definitions === []) {
            return $dependants;
        }
        foreach ($dependants['generated'] ?? [] as $i => $generated) {
            if (isset($definitions[$generated['name']])) {
                $dependants['generated'][$i]['upDefinition'] = $definitions[$generated['name']];
            }
        }
        return $dependants;
    }

    /**
     * The serial sequence a column change needs by name — read from whichever
     * side is serial — and, when both are and their sequences' types differ,
     * the type each direction leaves it with (issue #239).
     */
    private function attachSerialSequence(AlterTableChangeColumn $change, string $table, string $column): void {
        $read = fn(string $side, string $def) => PostgresColumnDefinition::parse($def)->serial
            ? PostgresSchemaHelper::serialSequence($this->manager->getDB($side), $table, $column)
            : null;
        $atTarget = $read('target', $change->diff->getOldValue());
        $atSource = $read('source', $change->diff->getNewValue());

        $change->serialSequence = ($atTarget ?? $atSource)['name'] ?? null;
        // The side that is not serial may still own the sequence, left behind
        // by DROP DEFAULT. An identity column owns one too, but its own: the
        // serial one goes.
        $owns = fn(string $side, string $def) => !PostgresColumnDefinition::parse($def)->isIdentity()
            && PostgresSchemaHelper::serialSequence($this->manager->getDB($side), $table, $column) !== null;
        if (($atTarget === null) !== ($atSource === null)) {
            $change->keepsSerialSequence = $atSource === null
                ? ['up' => $owns('source', $change->diff->getNewValue())]
                : ['down' => $owns('target', $change->diff->getOldValue())];
        }
        if ($atTarget !== null && $atSource !== null && $atTarget['type'] !== $atSource['type']) {
            $change->serialSequenceTypes = ['up' => $atSource['type'], 'down' => $atTarget['type']];
        }
    }

    /**
     * Column storage strategies — PostgreSQL only (issue #225).
     *
     * For a column on both sides, its storage is set when it differs — and
     * also whenever its type changes and the source's storage is not its
     * type's default, because `ALTER COLUMN ... TYPE` resets storage. For a
     * column the migration adds, it is set when it is not the default.
     *
     * @param array<string, object> $columnDiffs the column diffs, target → source
     * @param string[]              $columns     the source's compared columns
     * @return AlterTableColumnStorage[]
     */
    private static function storageDiffs(
        string $table,
        array $sourceSchema,
        array $targetSchema,
        array $columnDiffs,
        array $columns
    ): array {
        $diffs = [];
        foreach ($columns as $column) {
            $source = $sourceSchema['storage'][$column] ?? null;
            if ($source === null) {
                continue;
            }
            $target = $targetSchema['storage'][$column] ?? null;
            $diff   = $columnDiffs[$column] ?? null;
            if ($target === null) {
                if ($source['actual'] !== $source['default']) {
                    $diffs[] = new AlterTableColumnStorage($table, $column, $source['actual'], $source['default'], true);
                }
                continue;
            }
            $retyped = $diff instanceof \Diff\DiffOp\DiffOpChange
                && PostgresDialect::changesColumnType($diff->getOldValue(), $diff->getNewValue());
            if ($source['actual'] !== $target['actual'] || ($retyped && $source['actual'] !== $source['default'])) {
                $diffs[] = new AlterTableColumnStorage($table, $column, $source['actual'], $target['actual']);
            }
        }
        return $diffs;
    }

    /**
     * Durability and storage parameters — PostgreSQL only.
     *
     * Both were read for rendering a new table and never compared for one that
     * exists on both sides, so switching a table between LOGGED and UNLOGGED,
     * or changing its fillfactor, was reported as no difference at all
     * (issue #229). UNLOGGED is not decoration: an unlogged table is not
     * crash-safe and is emptied on recovery.
     *
     * @return array<int, object>
     */
    private static function tablePropertyDiffs(string $table, array $sourceSchema, array $targetSchema): array
    {
        $diffs = [];

        $sourceUnlogged = (bool) ($sourceSchema['unlogged'] ?? false);
        $targetUnlogged = (bool) ($targetSchema['unlogged'] ?? false);
        if ($sourceUnlogged !== $targetUnlogged) {
            $diffs[] = new AlterTablePersistence($table, $sourceUnlogged, $targetUnlogged);
        }

        $sourceOptions = $sourceSchema['reloptions'] ?? null;
        $targetOptions = $targetSchema['reloptions'] ?? null;
        if ($sourceOptions !== $targetOptions) {
            $diffs[] = new AlterTableOptions($table, $sourceOptions, $targetOptions);
        }

        // What logical replication sends of an old row: Supabase Realtime
        // gets a deleted or updated row's previous values only under FULL.
        $sourceIdentity = $sourceSchema['replicaIdentity'] ?? 'DEFAULT';
        $targetIdentity = $targetSchema['replicaIdentity'] ?? 'DEFAULT';
        if ($sourceIdentity !== $targetIdentity) {
            $diffs[] = new AlterTableReplicaIdentity($table, $sourceIdentity, $targetIdentity);
        }

        // Statistics targets, for the columns on the source.
        $sourceStats = $sourceSchema['statistics'] ?? [];
        $targetStats = $targetSchema['statistics'] ?? [];
        $targetColumns = $targetSchema['columns'] ?? [];
        foreach (array_keys(($sourceSchema['columns'] ?? []) + $targetStats) as $column) {
            if (!isset($sourceSchema['columns'][$column])) {
                continue;
            }
            $want = $sourceStats[$column] ?? -1;
            $have = $targetStats[$column] ?? -1;
            if ($want !== $have) {
                $diffs[] = new AlterTableColumnStatistics($table, $column, $want, $have, !isset($targetColumns[$column]));
            }
        }

        return $diffs;
    }
}
