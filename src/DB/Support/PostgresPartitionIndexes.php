<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * A partition's indexes that are attached to its parent's: the name and the
 * storage options each one has.
 *
 * PostgreSQL makes one for every partition when the parent's index is
 * created, or when a partition is created after it, and names it after the
 * partition and its columns (`isd_00_issue_id_idx`), with the parent index's
 * options. A schema whose partitions were indexed first and attached later —
 * as GitLab's are — has its own names and options on them instead
 * (`custom_name_for_01`, `WITH (fastupdate='false')`), which a rebuilt copy
 * lost.
 *
 * The name and options are compared for a partition on both sides, keyed by
 * the parent index each one is attached to, and set for a new partition by a
 * step that works whichever runs first, the partition's or the parent index's
 * creation: see newPartitionStatements().
 */
final class PostgresPartitionIndexes {

    /** PostgreSQL's NAMEDATALEN: names are at most 63 bytes. */
    private const NAMEDATALEN = 64;

    /**
     * The attached indexes of these tables, those that are partitions:
     * table => parent index (schema-qualified) => [name, options].
     *
     * @param  string[] $tables
     * @return array<string, array<string, array{name: string, options: ?string}>>
     */
    public static function forTables(Connection $connection, array $tables): array {
        if ($tables === []) {
            return [];
        }
        $out = [];
        foreach (self::rows($connection, $tables) as $row) {
            $out[$row['table_name']][$row['parent_index']] = [
                'name'    => $row['index_name'],
                'options' => ($row['options'] ?? '') !== '' ? $row['options'] : null,
            ];
        }
        foreach ($out as &$byParent) {
            ksort($byParent);
        }
        return $out;
    }

    /**
     * A CTE body giving each partition's attached indexes as one string, for
     * the pre-scan's signature: a partition that differs only in them must
     * not be skipped.
     */
    public static function signatureSql(Connection $connection): string {
        return "SELECT t.relname AS table_name,
                       string_agg(pn.nspname || '.' || pc.relname || '>' || c.relname || '|' ||
                                  COALESCE(" . PostgresSchemaHelper::canonicalReloptions('c.reloptions', ',') . ", ''),
                                  ';' ORDER BY pn.nspname, pc.relname) AS pidx_str
                  FROM pg_index x
                  JOIN pg_class c ON c.oid = x.indexrelid
                  JOIN pg_class t ON t.oid = x.indrelid
                  JOIN pg_inherits h ON h.inhrelid = c.oid
                  JOIN pg_class pc ON pc.oid = h.inhparent
                  JOIN pg_namespace pn ON pn.oid = pc.relnamespace
                 WHERE t.relnamespace = " . SchemaScope::oid($connection) . "
                 GROUP BY t.relname";
    }

    /**
     * For a partition being created: one statement for each attached index
     * whose name or options are not the ones PostgreSQL would give it.
     *
     * The partition's index may not exist yet when it runs — the parent's is
     * created after the tables when a consumer groups statements by kind — or
     * may already, made by PostgreSQL when the partition was created under an
     * existing parent index. So the step looks for it through pg_inherits:
     * found, it is renamed and given its options; not found, it is created as
     * the source has it, and the parent's index attaches it when created.
     *
     * @return list<string>
     */
    public static function newPartitionStatements(Connection $connection, string $table): array {
        $schema = SchemaScope::of($connection);
        $out = [];
        foreach (self::rows($connection, [$table]) as $row) {
            $expected = self::generatedName($table, self::columnNames($row), $row['label']);
            $options  = ($row['options'] ?? '') !== '' ? $row['options'] : null;
            $parentOptions = ($row['parent_options'] ?? '') !== '' ? $row['parent_options'] : null;
            if ($row['index_name'] === $expected && $options === $parentOptions) {
                continue;
            }
            $out[] = self::ensureStatement($schema, $table, $row, $options);
        }
        return array_merge($out, self::deferredParentIndexes($connection, $table));
    }

    /** Whether this table of the schema has partitions. */
    public static function hasPartitions(Connection $connection, string $table): bool {
        $row = $connection->selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_inherits h JOIN pg_class p ON p.oid = h.inhparent
                             WHERE p.relnamespace = " . SchemaScope::oid($connection) . " AND p.relname = ?) AS e",
            [$table]
        );
        return filter_var(((array) $row)['e'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The table's indexes to create with it. An invalid ON ONLY index of a
     * parent with partitions is left out: it comes after the last of them
     * (deferredParentIndexes()).
     *
     * @param  array<string, mixed> $partition
     * @param  string[] $keys
     * @return string[]
     */
    public static function toCreateWithTable(Connection $connection, string $table, array $partition, array $keys): array {
        if ($partition['partition_by'] === null || !self::hasPartitions($connection, $table)) {
            return $keys;
        }
        return array_filter($keys, fn($idxDef) => !str_contains($idxDef, ' ON ONLY '));
    }

    /**
     * The parent's invalid indexes, for its last partition to create.
     *
     * An index created ON ONLY a partitioned table stays invalid until every
     * partition has one attached, as a schema building an index partition by
     * partition has it for a while. But PostgreSQL gives a partition created
     * after it an index of its own, attached, so created with the parent it
     * came out valid, with an index on every partition the source does not
     * have. Created after the last partition it matches. That holds while the
     * partitions are created in the order DBDiff writes them, by name; one
     * created later still gets an index.
     *
     * @return list<string>
     */
    public static function deferredParentIndexes(Connection $connection, string $table): array {
        $rows = $connection->select(
            "WITH me AS (SELECT c.oid, h.inhparent FROM pg_class c JOIN pg_inherits h ON h.inhrelid = c.oid
                          WHERE c.relnamespace = " . SchemaScope::oid($connection) . " AND c.relname = ?),
                  last AS (SELECT c.oid FROM pg_inherits h JOIN pg_class c ON c.oid = h.inhrelid JOIN me ON h.inhparent = me.inhparent
                            WHERE c.relkind IN ('r', 'p') ORDER BY c.relname DESC LIMIT 1)
             SELECT pg_get_indexdef(x.indexrelid) AS def
               FROM me JOIN last ON last.oid = me.oid
               JOIN pg_index x ON x.indrelid = me.inhparent
               JOIN pg_class i ON i.oid = x.indexrelid
              WHERE NOT x.indisvalid AND i.relkind = 'I'
              ORDER BY i.relname",
            [$table]
        );
        return array_map(fn($r) => ((array) $r)['def'], $rows);
    }

    /**
     * The name PostgreSQL gives an index it makes for a partition: its
     * makeObjectName() over the partition's name, the index's column names
     * (`expr` for an expression) and the label, shortened to fit in 63
     * bytes. A name already taken gets a number, which is not predicted here:
     * that partition then gets a statement it may not have needed.
     *
     * @param list<string> $columns
     */
    public static function generatedName(string $table, array $columns, string $label): string {
        $name2 = $label !== 'pkey' ? self::nameAddition($columns) : null;
        $overhead = ($name2 !== null ? 1 : 0) + strlen($label) + 1;
        $avail = self::NAMEDATALEN - 1 - $overhead;
        $n1 = strlen($table);
        $n2 = $name2 !== null ? strlen($name2) : 0;
        while ($n1 + $n2 > $avail) {
            if ($n1 > $n2) {
                $n1--;
            } else {
                $n2--;
            }
        }
        $result = mb_strcut($table, 0, $n1, 'UTF-8');
        if ($name2 !== null) {
            $result .= '_' . mb_strcut($name2, 0, $n2, 'UTF-8');
        }
        return $result . '_' . $label;
    }

    /**
     * ChooseIndexNameAddition(): the column names joined with _, stopping
     * once NAMEDATALEN is reached.
     *
     * @param list<string> $columns
     */
    private static function nameAddition(array $columns): string {
        $name = '';
        foreach ($columns as $column) {
            if ($name !== '') {
                $name .= '_';
            }
            $name .= substr($column, 0, self::NAMEDATALEN);
            if (strlen($name) >= self::NAMEDATALEN) {
                break;
            }
        }
        return $name;
    }

    /** @param array<string, mixed> $row */
    private static function ensureStatement(string $schema, string $table, array $row, ?string $options): string {
        $lit = fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";
        $name = $row['index_name'];
        $qualifiedIndex = self::ident($schema) . '.' . self::ident($name);
        $setOptions = $options !== null ? "\n  ALTER INDEX $qualifiedIndex SET ($options);" : '';
        // An index behind a constraint (a primary key) comes only from the
        // parent's; there is nothing to create on its own.
        $create = $row['is_constraint']
            ? ''
            : "\n  IF made IS NULL THEN\n    {$row['definition']};\n  END IF;";
        return "DO \$dbdiff\$\nDECLARE made name;\nBEGIN"
            . "\n  SELECT c.relname INTO made FROM pg_inherits h JOIN pg_class c ON c.oid = h.inhrelid"
            . " JOIN pg_index x ON x.indexrelid = c.oid"
            . "\n   WHERE h.inhparent = to_regclass(" . $lit($row['parent_index_quoted']) . ")"
            . " AND x.indrelid = " . $lit(self::ident($schema) . '.' . self::ident($table)) . "::regclass;"
            . $create
            . "\n  IF made IS NOT NULL AND made <> " . $lit($name) . " THEN"
            . "\n    EXECUTE format('ALTER INDEX %I.%I RENAME TO %I', " . $lit($schema) . ", made, " . $lit($name) . ");"
            . "\n  END IF;"
            . ($setOptions !== '' ? "\n  IF made IS NOT NULL THEN" . str_replace("\n  ", "\n    ", $setOptions) . "\n  END IF;" : '')
            . "\nEND \$dbdiff\$";
    }

    /** @param array<string, mixed> $row @return list<string> */
    private static function columnNames(array $row): array {
        $names = json_decode((string) $row['columns'], true);
        return is_array($names) ? array_map('strval', $names) : [];
    }

    private static function ident(string $name): string {
        return preg_match('/^[a-z_][a-z0-9_$]*$/', $name) ? $name : '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * @param  string[] $tables
     * @return list<array<string, mixed>>
     */
    private static function rows(Connection $connection, array $tables): array {
        $ph = implode(', ', array_fill(0, count($tables), '?'));
        $rows = $connection->select(
            "SELECT t.relname AS table_name,
                    pn.nspname || '.' || pc.relname AS parent_index,
                    quote_ident(pn.nspname) || '.' || quote_ident(pc.relname) AS parent_index_quoted,
                    c.relname AS index_name,
                    pg_get_indexdef(c.oid) AS definition,
                    " . PostgresSchemaHelper::canonicalReloptions('c.reloptions', ', ') . " AS options,
                    " . PostgresSchemaHelper::canonicalReloptions('pc.reloptions', ', ') . " AS parent_options,
                    COALESCE((SELECT json_agg(CASE WHEN k.attnum = 0 THEN 'expr' ELSE a.attname END ORDER BY k.ord)
                                FROM unnest(x.indkey::int2[]) WITH ORDINALITY AS k(attnum, ord)
                                LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum), '[]')::text AS columns,
                    CASE pk.contype WHEN 'p' THEN 'pkey' WHEN 'u' THEN 'key' WHEN 'x' THEN 'excl' ELSE 'idx' END AS label,
                    pk.contype IS NOT NULL AS is_constraint
               FROM pg_index x
               JOIN pg_class c ON c.oid = x.indexrelid
               JOIN pg_class t ON t.oid = x.indrelid
               JOIN pg_inherits h ON h.inhrelid = c.oid
               JOIN pg_class pc ON pc.oid = h.inhparent
               JOIN pg_namespace pn ON pn.oid = pc.relnamespace
               LEFT JOIN pg_constraint pk ON pk.conindid = pc.oid AND pk.contype IN ('p', 'u', 'x')
              WHERE t.relnamespace = " . SchemaScope::oid($connection) . " AND t.relname IN ($ph)
              ORDER BY t.relname, pn.nspname, pc.relname",
            $tables
        );
        return array_map(function ($row) {
            $row = (array) $row;
            $row['is_constraint'] = filter_var($row['is_constraint'], FILTER_VALIDATE_BOOLEAN);
            return $row;
        }, $rows);
    }
}
