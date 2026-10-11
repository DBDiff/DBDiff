<?php namespace DBDiff\DB\Support;

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
}
