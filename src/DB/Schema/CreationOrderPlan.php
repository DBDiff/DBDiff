<?php namespace DBDiff\DB\Schema;

use DBDiff\Diff\AddTable;
use DBDiff\Diff\CreateRoutine;
use DBDiff\Diff\DropRoutine;
use DBDiff\Diff\DropTable;
use Illuminate\Database\Connection;
use DBDiff\DB\Support\SchemaScope;

/**
 * Routines that a table's definition calls are created before the tables, in
 * whichever direction creates them (issue #238).
 *
 * Routines are created with the programmable objects, after the tables,
 * because a routine's body may read a table the same migration creates. A
 * column default, a generated column, a CHECK constraint or an expression
 * index that *calls* a routine needs the opposite:
 *
 *     ALTER TABLE "t" ADD COLUMN "id" integer DEFAULT f();
 *     ERROR:  function f() does not exist
 *
 * The UP creates the source's new routines (CreateRoutine); the DOWN recreates
 * the target's, which the source dropped (DropRoutine). Which of them tables
 * call is read from that side's own dependency records, not guessed from
 * text. A routine that is needed early but whose definition names a table the
 * same direction creates is left where it is — the cycle cannot be broken by
 * ordering, and its body would fail to validate either way.
 */
final class CreationOrderPlan {

    /** @param array<int, object> $diffs */
    public static function apply(array $diffs, Connection $source, Connection $target): void {
        self::markEarly(
            array_filter($diffs, fn($d) => $d instanceof CreateRoutine),
            $source,
            self::tables($diffs, AddTable::class)
        );
        self::markEarly(
            array_filter($diffs, fn($d) => $d instanceof DropRoutine),
            $target,
            self::tables($diffs, DropTable::class)
        );
    }

    /**
     * @param array<int, CreateRoutine|DropRoutine> $routines  created by one direction
     * @param Connection                            $side      the database that has them
     * @param string[]                              $newTables the tables that direction creates
     */
    private static function markEarly(array $routines, Connection $side, array $newTables): void {
        if ($routines === []) {
            return;
        }
        $calledByTables = array_flip(array_column($side->select(
            "SELECT DISTINCT p.oid::regprocedure::text AS name
               FROM pg_depend d
               JOIN pg_proc p ON p.oid = d.refobjid
               JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE d.refclassid = 'pg_proc'::regclass
                AND n.nspname = " . SchemaScope::literal($side) . "
                AND (d.classid IN ('pg_attrdef'::regclass, 'pg_constraint'::regclass)
                     OR (d.classid = 'pg_class'::regclass
                         AND (SELECT relkind FROM pg_class WHERE oid = d.objid) IN ('i', 'I')))"
        ), 'name'));

        foreach ($routines as $routine) {
            if (isset($calledByTables[$routine->name]) && !self::readsAny($routine->definition, $newTables)) {
                $routine->early = true;
            }
        }
    }

    /** @return string[] the tables of the diffs of one class */
    private static function tables(array $diffs, string $class): array {
        return array_values(array_map(
            fn($d) => $d->table,
            array_filter($diffs, fn($d) => $d instanceof $class)
        ));
    }

    /** Whether a routine's definition names any of these tables. */
    private static function readsAny(string $definition, array $tables): bool {
        foreach ($tables as $table) {
            if (preg_match('/(?<![\w$])"?' . preg_quote($table, '/') . '"?(?![\w$])/i', $definition)) {
                return true;
            }
        }
        return false;
    }
}
