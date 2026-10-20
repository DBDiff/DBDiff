<?php namespace DBDiff\DB\Support;

use DBDiff\DB\Adapters\DBAdapterInterface;
use DBDiff\Exceptions\DBException;

final class ServerSettings
{
    /**
     * A server named in the input but never given: say so, rather than connect
     * with an empty configuration and a PHP warning about a missing host.
     */
    public static function requireFor(mixed $server, string $name): void
    {
        if (!is_array($server) || $server === []) {
            throw new DBException(
                "No connection settings for {$name}. Pass --{$name}=user:password@host:port, "
                . "use --server1-url / --server2-url, or set {$name} in your .dbdiff config."
            );
        }
    }

    /**
     * The connection config for one side of the input: an SQLite file's path
     * is the db itself; a server's settings, with the sslmode --supabase or
     * --sslmode supply where its own URL names none.
     */
    public static function connectionConfig(DBAdapterInterface $adapter, string $driver, object $params, array $input): array
    {
        if ($driver === 'sqlite') {
            return $adapter->buildConnectionConfig([], $input['db']);
        }
        $server = $params->{$input['server']};
        self::requireFor($server, $input['server']);
        if (isset($params->sslmode) && empty($server['sslmode'])) {
            $server['sslmode'] = $params->sslmode;
        }
        return $adapter->buildConnectionConfig($server, $input['db']);
    }
}
