<?php namespace DBDiff\SQLGen;

use DBDiff\SQLGen\Dialect\DialectRegistry;


class MigrationGenerator {

    public static function generate($diffs, $method) {
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
                $sql .= $statement."\n";
            }
        }
        return $sql;
    }

}
