<?php namespace DBDiff\Params;

use DBDiff\Exceptions\CLIException;


class ParamsFactory {

    /** @var object|null  Cached params instance (set by DiffCommand or the first get() call). */
    private static ?object $instance = null;

    /**
     * Pre-populate the shared params instance.
     *
     * DiffCommand calls this before running the diff pipeline so that
     * internal code which calls get() (DBSchema, TableSchema, etc.)
     * receives the same params without re-parsing $GLOBALS['argv'].
     */
    public static function set(object $params): void
    {
        self::$instance = $params;
    }

    public static function get() {

        if (self::$instance !== null) {
            return self::$instance;
        }
        
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

        self::$instance = $params;
        return $params;

    }

    /**
     * Clear the cached instance (useful in tests).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    protected static function merge($obj1, $obj2) {
        return (object) array_merge((array) $obj1, (array) $obj2);
    }
}
