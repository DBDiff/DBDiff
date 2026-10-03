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
     * Used by the sequence kind and the pg_dump renderer, which must agree on
     * which sequences a table's DDL creates.
     */
    public static function serialShaped(string $seq, string $oid): string {
        return "EXISTS (
                    SELECT 1 FROM pg_depend own
                     WHERE own.objid = $oid
                       AND own.classid = 'pg_class'::regclass
                       AND own.refclassid = 'pg_class'::regclass
                       AND own.refobjsubid > 0
                       AND own.deptype = 'a')
                AND $seq.seqstart = 1 AND $seq.seqincrement = 1 AND $seq.seqmin = 1
                AND $seq.seqcache = 1 AND NOT $seq.seqcycle
                AND $seq.seqmax = CASE $seq.seqtypid
                      WHEN 'int2'::regtype THEN 32767
                      WHEN 'int4'::regtype THEN 2147483647
                      ELSE 9223372036854775807 END
                AND (SELECT count(*) FROM pg_depend uses
                      WHERE uses.refobjid = $oid
                        AND uses.refclassid = 'pg_class'::regclass
                        AND uses.classid = 'pg_attrdef'::regclass) <= 1";
    }

    /**
     * Sequences owned by a column of this table that are not the column's own
     * serial — see serialShaped().
     *
     * @return array<string, true>
     */
    public static function standaloneOf(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            "SELECT s.relname AS name
               FROM pg_depend d
               JOIN pg_class s ON s.oid = d.objid AND s.relkind = 'S'
               JOIN pg_sequence sq ON sq.seqrelid = s.oid
               JOIN pg_class t ON t.oid = d.refobjid
               JOIN pg_namespace n ON n.oid = t.relnamespace
              WHERE n.nspname = 'public' AND t.relname = ? AND d.deptype = 'a'
                AND NOT (" . self::serialShaped('sq', 's.oid') . ")",
            [$table]
        );
        $names = [];
        foreach ($rows as $row) {
            $names[((array) $row)['name']] = true;
        }
        return $names;
    }
}
