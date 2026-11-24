<?php

declare(strict_types=1);

namespace DBDiff\DB\Support;

/**
 * A server's TLS settings, from its URL, onto the connection config.
 *
 * PostgreSQL takes libpq's own names: `sslmode`, and the certificate files
 * `sslrootcert` (the CA to verify the server against), `sslcert` and
 * `sslkey` (a client certificate). Only `sslmode` used to reach the
 * connection, so `sslmode=verify-full&sslrootcert=ca.crt` verified against
 * `~/.postgresql/root.crt` instead, and against a server whose CA is its
 * own, such as a hosted Supabase project's, could not connect at all.
 *
 * MySQL takes the mysql client's: `ssl-mode` (DISABLED, PREFERRED, REQUIRED,
 * VERIFY_CA, VERIFY_IDENTITY) and `ssl-ca`, `ssl-cert`, `ssl-key`. The libpq
 * spellings are accepted for MySQL too, `sslmode=verify-full` reading as
 * VERIFY_IDENTITY, so one URL shape serves both.
 */
final class TlsConfig
{
    /** libpq's certificate parameters, each a file path. */
    public const PG_FILES = ['sslrootcert', 'sslcert', 'sslkey'];

    /** The mysql client's names for the same files, and their libpq equivalents. */
    private const MYSQL_ALIASES = ['ssl-ca' => 'sslrootcert', 'ssl_ca' => 'sslrootcert', 'ssl-cert' => 'sslcert',
        'ssl_cert' => 'sslcert', 'ssl-key' => 'sslkey', 'ssl_key' => 'sslkey'];

    /** libpq's modes, as the mysql client names them. */
    private const MYSQL_MODES = ['disable' => 'DISABLED', 'allow' => 'PREFERRED', 'prefer' => 'PREFERRED',
        'require' => 'REQUIRED', 'verify-ca' => 'VERIFY_CA', 'verify-full' => 'VERIFY_IDENTITY'];

    /**
     * The TLS settings a URL's query string names, under libpq's keys:
     * `sslmode`, `sslrootcert`, `sslcert`, `sslkey`. Empty ones are left out.
     *
     * @param  array<string, mixed> $query
     * @return array<string, string>
     */
    public static function fromQuery(array $query, string $driver): array
    {
        $out = [];
        $mode = (string) ($query['sslmode'] ?? '');
        if ($driver === 'mysql') {
            $mode = (string) ($query['ssl-mode'] ?? $query['ssl_mode'] ?? $mode);
        }
        if ($mode !== '') {
            $out['sslmode'] = $mode;
        }
        foreach (self::PG_FILES as $key) {
            $value = (string) ($query[$key] ?? '');
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        if ($driver === 'mysql') {
            foreach (self::MYSQL_ALIASES as $alias => $key) {
                $value = (string) ($query[$alias] ?? '');
                if ($value !== '' && !isset($out[$key])) {
                    $out[$key] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * A PostgreSQL connection config with the server's certificate files.
     * `sslmode` is already there: buildConnectionConfig sets it.
     *
     * @param  array<string, mixed> $config
     * @param  array<string, mixed> $server
     * @return array<string, mixed>
     */
    public static function applyPostgres(array $config, array $server): array
    {
        foreach (self::PG_FILES as $key) {
            $value = (string) ($server[$key] ?? '');
            if ($value !== '') {
                $config[$key] = $value;
            }
        }
        return $config;
    }

    /**
     * The mysql client's mode for a server, upper case, or '' when none is set.
     */
    public static function mysqlMode(array $server): string
    {
        $mode = trim((string) ($server['sslmode'] ?? ''));
        if ($mode === '') {
            return (string) ($server['sslrootcert'] ?? '') !== '' ? 'VERIFY_CA' : '';
        }
        return self::MYSQL_MODES[strtolower($mode)] ?? strtoupper(str_replace('-', '_', $mode));
    }

    /**
     * PDO options for a MySQL server's TLS settings.
     *
     * PDO's MySQL driver turns TLS on when any of its SSL attributes is set,
     * and verifies the server's certificate only when told to. REQUIRED
     * without a CA encrypts without verifying, as the mysql client does; a
     * VERIFY_* mode without a CA is refused rather than run unverified.
     * PHP's driver checks the server's name whenever it verifies, so
     * VERIFY_CA here is as strict as VERIFY_IDENTITY: the certificate must
     * name the host the URL connects to.
     *
     * @param  array<string, mixed> $server
     * @return array<int, mixed>
     */
    public static function mysqlOptions(array $server): array
    {
        $mode = self::mysqlMode($server);
        if ($mode === '' || $mode === 'DISABLED' || $mode === 'PREFERRED') {
            return [];
        }
        if (!in_array($mode, ['REQUIRED', 'VERIFY_CA', 'VERIFY_IDENTITY'], true)) {
            throw new \InvalidArgumentException(
                "Unknown MySQL ssl-mode '{$mode}'. Use DISABLED, PREFERRED, REQUIRED, VERIFY_CA or VERIFY_IDENTITY."
            );
        }
        $ca = (string) ($server['sslrootcert'] ?? '');
        $verify = $mode !== 'REQUIRED';
        if ($verify && $ca === '') {
            throw new \InvalidArgumentException(
                "ssl-mode={$mode} needs the CA to verify the server against: add ssl-ca=<file> to the URL."
            );
        }
        $options = [self::pdoMysql('ATTR_SSL_VERIFY_SERVER_CERT') => $verify];
        // With no file to name, the cipher list is what switches TLS on.
        $options[$ca !== '' ? self::pdoMysql('ATTR_SSL_CA') : self::pdoMysql('ATTR_SSL_CIPHER')] = $ca !== '' ? $ca : 'DEFAULT';
        foreach (['sslcert' => 'ATTR_SSL_CERT', 'sslkey' => 'ATTR_SSL_KEY'] as $key => $attr) {
            $value = (string) ($server[$key] ?? '');
            if ($value !== '') {
                $options[self::pdoMysql($attr)] = $value;
            }
        }
        return $options;
    }

    /**
     * libpq's environment for pg_dump and psql: PGSSLROOTCERT and the rest.
     *
     * @param  callable(string): mixed $config A connection's getConfig().
     * @return array<string, string>
     */
    public static function libpqEnvironment(callable $config): array
    {
        $env = [];
        foreach (['sslmode' => 'PGSSLMODE', 'sslrootcert' => 'PGSSLROOTCERT', 'sslcert' => 'PGSSLCERT', 'sslkey' => 'PGSSLKEY'] as $key => $var) {
            $value = $config($key);
            if (is_string($value) && $value !== '') {
                $env[$var] = $value;
            }
        }
        return $env;
    }

    /**
     * PHP 8.4 moved the PDO_MYSQL constants onto `Pdo\Mysql` and deprecated
     * the `PDO::MYSQL_*` spellings; both carry the same value.
     */
    private static function pdoMysql(string $name): int
    {
        return defined("Pdo\\Mysql::{$name}") ? constant("Pdo\\Mysql::{$name}") : constant("PDO::MYSQL_{$name}");
    }
}
