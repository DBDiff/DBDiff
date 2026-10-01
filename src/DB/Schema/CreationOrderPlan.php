<?php namespace DBDiff\DB\Schema;

use DBDiff\Diff\AddTable;
use DBDiff\Diff\CreateRoutine;
use Illuminate\Database\Connection;

/**
 * Routines that a table's definition calls are created before the tables
 * (issue #238).
 *
 * New routines are created with the programmable objects, after the tables,
 * because a routine's body may read a table the same migration creates. A
 * column default, a generated column, a CHECK constraint or an expression
 * index that *calls* a new routine needs the opposite:
 *
 *     ALTER TABLE "t" ADD COLUMN "id" integer DEFAULT f();
 *     ERROR:  function f() does not exist
 *
 * Which routines those are is read from the source's own dependency records,
 * not guessed from text. A routine that is needed early but whose definition
 * names a table the migration creates is left where it is — the cycle cannot
 * be broken by ordering, and its body would fail to validate either way.
 */
final class CreationOrderPlan {

    /** @param array<int, object> $diffs */
    public static function apply(array $diffs, Connection $source): void {
        $routines = array_filter($diffs, fn($d) => $d instanceof CreateRoutine);
        if ($routines === []) {
            return;
        }

        $calledByTables = array_flip(array_column($source->select(
            "SELECT DISTINCT p.oid::regprocedure::text AS name
               FROM pg_depend d
               JOIN pg_proc p ON p.oid = d.refobjid
               JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE d.refclassid = 'pg_proc'::regclass
                AND n.nspname = 'public'
                AND (d.classid IN ('pg_attrdef'::regclass, 'pg_constraint'::regclass)
                     OR (d.classid = 'pg_class'::regclass
                         AND (SELECT relkind FROM pg_class WHERE oid = d.objid) = 'i'))"
        ), 'name'));

        $newTables = array_map(
            fn(AddTable $t) => $t->table,
            array_filter($diffs, fn($d) => $d instanceof AddTable)
        );

        foreach ($routines as $routine) {
            if (isset($calledByTables[$routine->name]) && !self::readsAny($routine->definition, $newTables)) {
                $routine->early = true;
            }
        }
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
