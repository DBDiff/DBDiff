<?php namespace DBDiff\SQLGen\Dialect;

use DBDiff\Exceptions\DBException;


/**
 * Global registry for the active SQL dialect.
 *
 * Set once during bootstrapping (DiffCalculator) and read by every DiffToSQL
 * class so that generated SQL is always appropriate for the configured driver.
 */
class DialectRegistry {

    private static ?SQLDialectInterface $dialect = null;

    public static function set(SQLDialectInterface $dialect): void {
        self::$dialect = $dialect;
    }

    public static function get(): SQLDialectInterface {
        if (self::$dialect === null) {
            // Default to MySQL for backwards compatibility.
            self::$dialect = new MySQLDialect();
        }
        return self::$dialect;
    }

    public static function createForDriver(string $driver): SQLDialectInterface {
        switch (strtolower($driver)) {
            case 'mysql':
                return new MySQLDialect();
            case 'pgsql':
            case 'postgres':
            case 'postgresql':
                return new PostgresDialect();
            case 'sqlite':
                return new SQLiteDialect();
            default:
                throw new DBException("No SQL dialect registered for driver \"$driver\".");
        }
    }

    public static function setForDriver(string $driver): void {
        self::set(self::createForDriver($driver));
    }
}
