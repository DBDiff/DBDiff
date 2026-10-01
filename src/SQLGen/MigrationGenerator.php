<?php namespace DBDiff\SQLGen;

use DBDiff\SQLGen\Dialect\DialectRegistry;


class MigrationGenerator {

    /**
     * Each diff's statements between `-- dbdiff:unit <Kind> <object>` and
     * `-- dbdiff:end` lines, when asked for with `--units`.
     *
     * Some changes take several statements that only work together and in
     * order — a column type change with the views reading it stood aside, an
     * enum label swap, a generated column re-added, a serial column turned
     * into an identity. A tool that applies or reviews changes one at a time
     * would otherwise have to guess where one ends from the SQL; the markers
     * say so instead. They are SQL comments, so the migration runs the same
     * with or without them.
     */
    public const UNIT_BEGIN = '-- dbdiff:unit';
    public const UNIT_END   = '-- dbdiff:end';

    public static function generate($diffs, $method, bool $units = false) {
        $dialect = DialectRegistry::get();
        $sql = "";
        foreach ($diffs as $diff) {
            // A dependant of a column type change is put back by that
            // change's DOWN, after the type is reverted — see
            // ColumnDependantPlan. Its own DOWN would run first, against the
            // column's new type.
            if ($method === 'getDown' && !empty($diff->downHandledElsewhere)) {
                continue;
            }
            $reflection  = new \ReflectionClass($diff);
            $sqlGenClass = __NAMESPACE__."\\DiffToSQL\\".$reflection->getShortName()."SQL";
            $gen         = new $sqlGenClass($diff, $dialect);
            $statement   = $gen->$method();
            if ($statement !== '') {
                $sql .= $units ? self::unit($diff, $statement) : $statement."\n";
            }
        }
        return $sql;
    }

    private static function unit(object $diff, string $statement): string {
        $kind   = (new \ReflectionClass($diff))->getShortName();
        $object = self::unitObject($diff);
        return self::UNIT_BEGIN . " $kind" . ($object !== '' ? " $object" : '') . "\n"
            . $statement . "\n" . self::UNIT_END . "\n";
    }

    /** `table`, `table.column`, `table.name` or `name` — what the diff is about. */
    private static function unitObject(object $diff): string {
        $table = $diff->table ?? null;
        $part  = $diff->column ?? $diff->key ?? $diff->name ?? null;
        if (is_string($table) && $table !== '') {
            return is_string($part) && $part !== '' && $part !== $table ? "$table.$part" : $table;
        }
        return is_string($part) ? $part : '';
    }
}
