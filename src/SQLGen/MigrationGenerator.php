<?php namespace DBDiff\SQLGen;

use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\Diff\CreateSchema;
use DBDiff\Diff\DropSchema;
use DBDiff\Diff\CreateRoutine;
use DBDiff\Diff\DropRoutine;
use DBDiff\Diff\AlterRoutine;


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
            $gen         = new $sqlGenClass($diff, $dialect->inSchema($diff->schema ?? null));
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
            $table = self::inSchema($diff, $table);
            return is_string($part) && $part !== '' && $part !== $diff->table ? "$table.$part" : $table;
        }
        return is_string($part) ? self::inSchema($diff, $part) : '';
    }

    /**
     * An object outside `public` named with its schema. A routine's name is
     * its signature as PostgreSQL prints it, qualified already, and a schema
     * is named by itself.
     */
    private static function inSchema(object $diff, string $name): string {
        $schema = $diff->schema ?? null;
        $named = [CreateSchema::class, DropSchema::class, CreateRoutine::class, DropRoutine::class, AlterRoutine::class];
        if ($schema === null || $schema === 'public' || in_array($diff::class, $named, true)) {
            return $name;
        }
        return "$schema.$name";
    }
}
