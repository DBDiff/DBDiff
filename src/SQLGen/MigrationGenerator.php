<?php namespace DBDiff\SQLGen;


class MigrationGenerator {

    public static function generate($diffs, $method, $type) {
        $type = ucfirst($type);
        $sql = "";
        foreach ($diffs as $diff) {
            $reflection = new \ReflectionClass($diff);
            $sqlGenClass = __NAMESPACE__."\\$type\\".$reflection->getShortName()."SQL";
            $gen = new $sqlGenClass($diff);
            $sql .= $gen->$method()."\n";
        }
        return $sql;
    }

}
