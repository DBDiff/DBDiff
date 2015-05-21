<?php namespace DBDiff\SQLGen;


class MigrationGenerator {

    public static function generate($diffs, $method) {
        $sql = "";
        foreach ($diffs as $diff) {
            $reflection = new \ReflectionClass($diff);
            $sqlGenClass = __NAMESPACE__."\\DiffToSQL\\".$reflection->getShortName()."SQL";
            $gen = new $sqlGenClass($diff);
            $sql .= $gen->$method()."\n";
        }
        return $sql;
    }

}
