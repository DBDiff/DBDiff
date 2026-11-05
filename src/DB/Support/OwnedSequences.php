<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * Which sequences owned by a column are that column's own serial, and which
 * are sequences in their own right. The sequence kind and the pg_dump renderer
 * must agree on it, or a table's DDL and the sequence diff both create one.
 */
final class OwnedSequences
{
    /**
     * SQL true when the sequence `$seq` (a pg_sequence alias, its relation's
     * oid in `$oid`) is a serial column's own: owned by its column, every
     * option at what `serial` gives it, and feeding no other default.
     *
     * A sequence owned by a column but with options of its own, or shared by
     * several defaults, is a sequence in its own right: modelled standalone and
     * created with all its options, its ownership set once the column exists.
     * Used by the sequence kind and by both table renderers, which must agree
     * on which sequences a table's DDL creates: when the built-in renderer
     * decided on its own, a column whose sequence the sequence kind had already
     * created (START 1000, say) was still written bigserial, and the target got
     * the original unowned beside an owned `_seq1` counting from 1.
     */
    public static function serialShaped(string $seq, string $oid): string {
        return "EXISTS (
                    SELECT 1 FROM pg_depend own
                      JOIN pg_class owner ON owner.oid = own.refobjid
                      JOIN pg_attribute owncol ON owncol.attrelid = own.refobjid
                                              AND owncol.attnum = own.refobjsubid
                     WHERE own.objid = $oid
                       AND own.classid = 'pg_class'::regclass
                       AND own.refclassid = 'pg_class'::regclass
                       AND own.refobjsubid > 0
                       AND own.deptype = 'a'
                       -- Named as `serial` would name it. GitLab's p_ci_build_needs
                       -- owns ci_build_needs_id_seq, kept from before the table
                       -- was renamed; written bigserial, the target's sequence
                       -- came out as p_ci_build_needs_id_seq. A name past 63
                       -- bytes never matches, and is created explicitly.
                       AND (SELECT seqrel.relname FROM pg_class seqrel WHERE seqrel.oid = $oid)
                           = owner.relname || '_' || owncol.attname || '_seq')
                AND $seq.seqstart = 1 AND $seq.seqincrement = 1 AND $seq.seqmin = 1
                AND $seq.seqcache = 1 AND NOT $seq.seqcycle
                AND $seq.seqmax = CASE $seq.seqtypid
                      WHEN 'int2'::regtype THEN 32767
                      WHEN 'int4'::regtype THEN 2147483647
                      ELSE 9223372036854775807 END
                AND (SELECT count(*) FROM pg_depend uses
                       JOIN pg_attrdef ad ON ad.oid = uses.objid
                       JOIN pg_attribute col ON col.attrelid = ad.adrelid AND col.attnum = ad.adnum
                      WHERE uses.refobjid = $oid
                        AND uses.refclassid = 'pg_class'::regclass
                        AND uses.classid = 'pg_attrdef'::regclass
                        -- A partition's copy of its parent's default is not a
                        -- second use: counted, a partitioned table's own serial
                        -- was modelled standalone while its column was still
                        -- written bigserial, and the target got two sequences.
                        AND col.attinhcount = 0) <= 1";
    }

    /**
     * Sequences owned by a column of this table that are not the column's own
     * serial — see serialShaped().
     *
     * @return array<string, true>
     */
    public static function standaloneOf(Connection $connection, string $table): array
    {
        return array_map(fn() => true, self::standaloneColumns($connection, $table));
    }

    /**
     * `ALTER SEQUENCE ... OWNED BY` for each sequence of this table that is
     * not its column's serial. The built-in renderer writes such a column with
     * its explicit default, the sequence kind creates the sequence beforehand,
     * and nothing else records which column owns it; without this, the
     * sequence outlived a dropped column and read as unowned on every re-diff.
     *
     * @return list<string>
     */
    public static function ownershipStatements(Connection $connection, string $table): array
    {
        $statements = [];
        foreach (self::standaloneColumns($connection, $table) as $sequence => $column) {
            $statements[] = 'ALTER SEQUENCE ' . SchemaScope::name($connection, $sequence)
                . ' OWNED BY ' . SchemaScope::name($connection, $table) . '.' . PostgresIdent::quote($column);
        }
        return $statements;
    }

    /**
     * For a column on both sides, the `OWNED BY` each direction needs when
     * the column owns a standalone sequence on one side and not the other.
     * A serial column's change carries its own; a standalone sequence's
     * ownership was otherwise set only with a new table, and an existing
     * table's column gaining a default from it left the sequence unowned.
     * Named as the target reads them, where the migration runs.
     *
     * @return array{up?: string, down?: string}
     */
    public static function ownershipChange(Connection $source, Connection $target, string $table, string $column): array
    {
        $owned = fn(Connection $side) => array_keys(self::standaloneColumns($side, $table), $column, true);
        $atSource = $owned($source);
        $atTarget = $owned($target);
        $owner = SchemaScope::name($target, $table) . '.' . PostgresIdent::quote($column);
        $set = fn(array $sequences, string $to) => implode("\n", array_map(
            fn(string $sequence) => 'ALTER SEQUENCE ' . SchemaScope::name($target, $sequence) . " OWNED BY $to;",
            $sequences
        ));

        $gained = array_diff($atSource, $atTarget);
        $lost = array_diff($atTarget, $atSource);
        return array_filter([
            'up' => implode("\n", array_filter([$set($gained, $owner), $set($lost, 'NONE')])),
            'down' => implode("\n", array_filter([$set($lost, $owner), $set($gained, 'NONE')])),
        ]);
    }

    /** @return array<string, string> sequence name => owning column */
    private static function standaloneColumns(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            "SELECT s.relname AS name, a.attname AS col
               FROM pg_depend d
               JOIN pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
               JOIN pg_class s ON s.oid = d.objid AND s.relkind = 'S'
               JOIN pg_sequence sq ON sq.seqrelid = s.oid
               JOIN pg_class t ON t.oid = d.refobjid
               JOIN pg_namespace n ON n.oid = t.relnamespace
              WHERE n.nspname = " . SchemaScope::literal($connection) . " AND t.relname = ? AND d.deptype = 'a'
                AND NOT (" . self::serialShaped('sq', 's.oid') . ")",
            [$table]
        );
        $columns = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $columns[$row['name']] = $row['col'];
        }
        ksort($columns);
        return $columns;
    }
}
