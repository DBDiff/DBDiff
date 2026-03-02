<?php namespace DBDiff\DB\Adapters;

use DBDiff\Exceptions\DBException;


class AdapterFactory {

    public static function create(string $driver): DBAdapterInterface {
        switch (strtolower($driver)) {
            case 'mysql':
                return new MySQLAdapter();
            case 'pgsql':
            case 'postgres':
            case 'postgresql':
                return new PostgresAdapter();
            case 'sqlite':
                return new SQLiteAdapter();
            default:
                throw new DBException("Unsupported driver \"$driver\". Supported: mysql, pgsql, sqlite.");
        }
    }
}
