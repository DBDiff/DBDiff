<?php

declare(strict_types=1);

namespace DBDiff\DB\Support;

/**
 * Connecting through a Unix-domain socket instead of TCP (issue #44).
 *
 * MySQL names the socket file itself: PDO takes it as unix_socket and then
 * ignores host and port. PostgreSQL names the directory the socket lives in,
 * given where a host would go, and the port picks the file in it
 * (.s.PGSQL.<port>) — so a path to that file is read as its directory and port,
 * which is what people copy out of a server's configuration.
 */
final class SocketConfig
{
    /**
     * @param array<string, mixed> $config an Illuminate connection config
     * @return array<string, mixed>
     */
    public static function apply(array $config, string $driver, ?string $socket): array
    {
        $socket = trim((string) $socket);
        if ($socket === '') {
            return $config;
        }

        if ($driver === 'mysql') {
            $config['unix_socket'] = $socket;
            return $config;
        }

        if ($driver === 'pgsql') {
            if (preg_match('#^(.*)/\.s\.PGSQL\.(\d+)$#', $socket, $m)) {
                $config['host'] = $m[1] === '' ? '/' : $m[1];
                $config['port'] = $m[2];
            } else {
                $config['host'] = rtrim($socket, '/') ?: '/';
            }
        }

        return $config;
    }
}
