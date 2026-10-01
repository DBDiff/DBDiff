<?php namespace DBDiff\SQLGen\DiffToSQL;

/**
 * `DROP INDEX` / `DROP CONSTRAINT` made tolerant of a missing object, for a
 * diff whose object an enum swap has already dropped and could not put back
 * (see EnumSwapPlan). Every other DROP stays strict.
 */
final class DropIfExists {

    public static function apply(object $diff, string $sql): string {
        if (empty($diff->dropIfExists)) {
            return $sql;
        }
        return preg_replace('/\bDROP (INDEX|CONSTRAINT) (?!IF EXISTS\b)/', 'DROP $1 IF EXISTS ', $sql, 1);
    }
}
