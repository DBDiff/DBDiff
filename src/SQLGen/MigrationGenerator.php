<?php namespace DBDiff\SQLGen;

use DBDiff\SQLGen\Dialect\DialectRegistry;


class MigrationGenerator {

    public static function generate($diffs, $method) {
        $dialect = DialectRegistry::get();
        $sql = "";
        foreach ($diffs as $diff) {
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
