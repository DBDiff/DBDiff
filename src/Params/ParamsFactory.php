<?php namespace DBDiff\Params;

use DBDiff\Exceptions\CLIException;


class ParamsFactory {
    
    public static function get() {
        
        $params = new DefaultParams;

        $cli = new CLIGetter;
        $paramsCLI = $cli->getParams();

        if (!isset($paramsCLI->debug)) {
            error_reporting(E_ERROR);
        }

        $fs = new FSGetter($paramsCLI);
        $paramsFS = $fs->getParams();
        $params = self::merge($params, $paramsFS);

        $params = self::merge($params, $paramsCLI);
        
        // SQLite is file-based: the DB path is embedded in the input argument,
        // so no --server1 connection block is required or meaningful.
        $driver = $params->driver ?? 'mysql';
        if ($driver !== 'sqlite' && empty($params->server1)) {
            throw new CLIException("A server is required");
        }
        return $params;

    }

    protected static function merge($obj1, $obj2) {
        return (object) array_merge((array) $obj1, (array) $obj2);
    }
}
