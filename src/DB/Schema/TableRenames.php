<?php namespace DBDiff\DB\Schema;

use DBDiff\DB\Adapters\BulkSchemaAdapterInterface;
use DBDiff\DB\Support\SchemaScope;
use DBDiff\Diff\AlterSequenceRename;
use DBDiff\Diff\AlterTableRename;
use DBDiff\Diff\AlterTableRenameConstraint;
use DBDiff\Diff\AlterTableRenameKey;
use DBDiff\Logger;
use Illuminate\Database\Connection;

/**
 * A table renamed on the source reads as one table dropped and another
 * created — and applied that way, its rows are gone.
 *
 * A table only the target has and one only the source has, the same in
 * every column, key, constraint and setting once names are set aside, are a
 * rename when they are the only such pair. Never guessed: two or more alike
 * on either side are left as drops and creates, and said so. What is named
 * after the table — its primary key and indexes, a serial column's sequence
 * — is renamed with it, where the source names it after the new name.
 */
final class TableRenames {

    /**
     * @param string[] $added   tables only the source has
     * @param string[] $deleted tables only the target has
     * @return array{0: array<string, string>, 1: array<int, object>} renames new => old, and their diffs
     */
    public static function plan(array $added, array $deleted, BulkSchemaAdapterInterface $adapter, Connection $source, Connection $target): array {
        if ($added === [] || $deleted === []) {
            return [[], []];
        }
        $sourceSchemas = $adapter->getBulkTableSchema($source, $added);
        $targetSchemas = $adapter->getBulkTableSchema($target, $deleted);

        $from = [];
        $to   = [];
        foreach ($deleted as $table) {
            if (($shape = self::shape($table, $targetSchemas[$table] ?? null)) !== null) {
                $from[$shape][] = $table;
            }
        }
        foreach ($added as $table) {
            if (($shape = self::shape($table, $sourceSchemas[$table] ?? null)) !== null) {
                $to[$shape][] = $table;
            }
        }

        $renames = [];
        $diffs   = [];
        foreach ($from as $shape => $olds) {
            $news = $to[$shape] ?? [];
            if ($news === []) {
                continue;
            }
            if (count($olds) !== 1 || count($news) !== 1) {
                Logger::warning(sprintf(
                    'Tables %s go and %s come with the same structure — not taken for renames, so their rows are not carried over',
                    implode(', ', $olds), implode(', ', $news)
                ));
                continue;
            }
            [$old, $new] = [$olds[0], $news[0]];
            $renames[$new] = $old;
            Logger::info("Table `$old` is renamed `$new`, keeping its rows (--renames=off to drop and create)");
            $diffs[] = new AlterTableRename($new, $old);
            array_push($diffs, ...self::carried($old, $new, $targetSchemas[$old], $sourceSchemas[$new]));
            array_push($diffs, ...self::carriedFromCatalog($old, $new, $source, $target));
        }
        return [$renames, $diffs];
    }

    /** What is named after the table, renamed with it. */
    private static function carried(string $old, string $new, array $target, array $source): array {
        $diffs = [];
        [, $keys] = ColumnRenames::carried([$new => $old], $target['keys'] ?? [], $source['keys'] ?? []);
        foreach ($keys as $from => $to) {
            $diffs[] = new AlterTableRenameKey($new, $to, $from);
        }
        [, $constraints] = ColumnRenames::carried([$new => $old], $target['constraints'] ?? [], $source['constraints'] ?? []);
        foreach ($constraints as $from => $to) {
            $diffs[] = new AlterTableRenameConstraint($new, $to, $from);
        }
        // A key's constraint name is a key's index name too: renaming the
        // constraint renames its index, so the index is not renamed twice.
        $constraintNames = array_flip(array_keys($constraints));
        $diffs = array_values(array_filter($diffs, fn($d) => !($d instanceof AlterTableRenameKey) || !isset($constraintNames[$d->from])));
        return $diffs;
    }

    /**
     * Names the schema read leaves implicit: PostgreSQL 18's NOT NULL
     * constraints under their default names (`t_col_not_null`), and the
     * sequences the table's serial columns own (`t_col_seq`). Renamed where
     * the source has the new table's version of the same name.
     */
    private static function carriedFromCatalog(string $old, string $new, Connection $source, Connection $target): array {
        $named = fn(Connection $c, string $table) => $c->select(
            "SELECT 'n' AS kind, conname AS name FROM pg_constraint WHERE conrelid = to_regclass(?) AND contype = 'n'
             UNION ALL
             SELECT 's', s.relname FROM pg_depend d JOIN pg_class s ON s.oid = d.objid
              WHERE d.classid = 'pg_class'::regclass AND d.refobjid = to_regclass(?) AND d.deptype = 'a' AND s.relkind = 'S'",
            [\DBDiff\DB\Support\SchemaScope::name($c, $table), \DBDiff\DB\Support\SchemaScope::name($c, $table)]
        );
        $have = [];
        foreach ($named($source, $new) as $row) {
            $row = (array) $row;
            $have[$row['kind'] . '|' . $row['name']] = true;
        }
        $diffs = [];
        foreach ($named($target, $old) as $row) {
            $row = (array) $row;
            if (!str_starts_with($row['name'], $old . '_')) {
                continue;
            }
            $renamed = $new . substr($row['name'], strlen($old));
            if (!isset($have[$row['kind'] . '|' . $renamed])) {
                continue;
            }
            $diffs[] = $row['kind'] === 'n'
                ? new AlterTableRenameConstraint($new, $renamed, $row['name'])
                : new AlterSequenceRename($renamed, $row['name']);
        }
        return $diffs;
    }

    /**
     * The table with its own name, and the names derived from it, set
     * aside; null for one that is not matched (a partition, or a child under
     * INHERITS, whose place depends on its name).
     */
    private static function shape(string $table, ?array $schema): ?string {
        if ($schema === null || ($schema['inheritedColumns'] ?? []) !== []) {
            return null;
        }
        $neutral = fn(string $sql) => preg_replace(
            ['/(?<![\w$"])"?' . preg_quote($table, '/') . '"?(?![\w$"])/', "/nextval\\('\"?" . preg_quote($table, '/') . '_/'],
            ['§', "nextval('§_"],
            $sql
        ) ?? $sql;
        $unnamed = fn(string $sql) => preg_replace(['/^CONSTRAINT\s+(?:"(?:[^"]|"")*"|\S+)\s+/', '/INDEX\s+(?:"(?:[^"]|"")*"|\S+)\s+ON\s/'], ['', 'INDEX ON '], $sql) ?? $sql;
        $columns = array_map($neutral, $schema['columns'] ?? []);
        $keys = array_map(fn($k) => $unnamed($neutral($k)), array_values($schema['keys'] ?? []));
        $constraints = array_map(fn($c) => $unnamed($neutral($c)), array_values($schema['constraints'] ?? []));
        sort($keys);
        sort($constraints);
        return json_encode([
            $columns, $keys, $constraints,
            $schema['unlogged'] ?? false, $schema['reloptions'] ?? null,
            $schema['replicaIdentity'] ?? 'DEFAULT', $schema['statistics'] ?? [], $schema['storage'] ?? [],
        ]);
    }
}
