<?php namespace DBDiff\Migration\Config;

/**
 * DsnParser — parse a database connection URL into its component parts.
 *
 * Supported URL schemes:
 *   mysql://user:pass@host:3306/dbname
 *   pgsql://user:pass@host:5432/dbname
 *   postgres://user:pass@host:5432/dbname     (alias for pgsql)
 *   postgresql://user:pass@host:5432/dbname   (alias for pgsql)
 *   sqlite:///absolute/path/to/db.sqlite
 *   sqlite://./relative/path/to/db.sqlite
 *
 * Query-string parameters are parsed for extras:
 *   ?sslmode=require
 *   ?pgbouncer=true      (Supabase session-mode pooler)
 *
 * Supabase URL formats supported:
 *   Direct connection  : postgres://postgres.PROJREF:PASSWORD@aws-0-REGION.pooler.supabase.com:5432/postgres
 *   Session pooler     : postgres://postgres.PROJREF:PASSWORD@aws-0-REGION.pooler.supabase.com:6543/postgres
 *   Legacy direct      : postgres://postgres:PASSWORD@db.PROJREF.supabase.co:5432/postgres
 *
 * Returns an associative array with keys:
 *   driver, host, port, name (db), path (SQLite only), user, password, sslmode, pgbouncer
 */
class DsnParser
{
    private static array $SCHEME_MAP = [
        'mysql'      => 'mysql',
        'pgsql'      => 'pgsql',
        'postgres'   => 'pgsql',
        'postgresql' => 'pgsql',
        'sqlite'     => 'sqlite',
    ];

    /**
     * Parse a database URL into a flat configuration array.
     *
     * @param  string $url  Full connection URL.
     * @return array{driver:string, host:string, port:int, name:string, path:string, user:string, password:string, sslmode:string, pgbouncer:bool}
     * @throws \InvalidArgumentException on unsupported scheme or malformed URL.
     */
    public static function parse(string $url): array
    {
        // Illuminate accepts DATABASE_URL env vars with the full URL form;
        // strip a leading "env(" wrapper if someone passes the literal config value.
        $url = trim($url);

        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['scheme'])) {
            throw new \InvalidArgumentException("Cannot parse database URL: {$url}");
        }

        $scheme = strtolower($parsed['scheme']);

        if (!isset(self::$SCHEME_MAP[$scheme])) {
            throw new \InvalidArgumentException(
                "Unsupported scheme '{$scheme}'. Supported: " . implode(', ', array_keys(self::$SCHEME_MAP))
            );
        }

        $driver = self::$SCHEME_MAP[$scheme];

        // ── SQLite ────────────────────────────────────────────────────────────
        if ($driver === 'sqlite') {
            // parse_url gives us 'path' for sqlite:///abs/path or sqlite://./rel
            $rawPath = ($parsed['host'] ?? '') . ($parsed['path'] ?? '');

            // sqlite:///absolute  → /absolute
            // sqlite://./relative → ./relative
            if (str_starts_with($rawPath, '/')) {
                $filePath = $rawPath;
            } elseif (str_starts_with($rawPath, '.')) {
                $filePath = $rawPath;
            } else {
                $filePath = '/' . $rawPath;
            }

            return [
                'driver'    => 'sqlite',
                'host'      => '',
                'port'      => 0,
                'name'      => '',
                'path'      => $filePath,
                'user'      => '',
                'password'  => '',
                'sslmode'   => '',
                'pgbouncer' => false,
            ];
        }

        // ── MySQL / Postgres ──────────────────────────────────────────────────
        $host     = $parsed['host']     ?? 'localhost';
        $user     = isset($parsed['user'])     ? urldecode($parsed['user'])     : '';
        $password = isset($parsed['pass'])     ? urldecode($parsed['pass'])     : '';
        $dbName   = ltrim($parsed['path'] ?? '', '/');

        // Default ports
        $defaultPort = $driver === 'mysql' ? 3306 : 5432;
        $port        = isset($parsed['port']) ? (int) $parsed['port'] : $defaultPort;

        // Query-string extras (?sslmode=require&pgbouncer=true)
        $query    = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        $sslMode   = $query['sslmode']   ?? '';
        $pgbouncer = filter_var($query['pgbouncer'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Supabase heuristics — apply sensible defaults when we detect a Supabase host
        if (self::isSupabaseHost($host)) {
            if (empty($sslMode)) {
                $sslMode = 'require';
            }

            // Port 6543 is the session-mode connection pooler (pgbouncer)
            if ($port === 6543) {
                $pgbouncer = true;
            }
        }

        return [
            'driver'    => $driver,
            'host'      => $host,
            'port'      => $port,
            'name'      => $dbName,
            'path'      => '',
            'user'      => $user,
            'password'  => $password,
            'sslmode'   => $sslMode,
            'pgbouncer' => $pgbouncer,
        ];
    }

    /**
     * Detect whether a host looks like a Supabase-managed endpoint.
     * Covers both the legacy `db.PROJ.supabase.co` and the newer
     * `aws-0-REGION.pooler.supabase.com` formats.
     */
    public static function isSupabaseHost(string $host): bool
    {
        return str_contains($host, '.supabase.co')
            || str_contains($host, '.supabase.com');
    }

    /**
     * Convert a DsnParser result array into a CLIGetter-style server array
     * (keys: user, password, host, port) and a database name.
     *
     * Useful for bridging DsnParser output into the existing diff pipeline.
     *
     * @return array{server: array, db: string, driver: string, sslmode: string}
     */
    public static function toServerAndDb(array $parsed): array
    {
        return [
            'driver'  => $parsed['driver'],
            'sslmode' => $parsed['sslmode'],
            'db'      => $parsed['driver'] === 'sqlite' ? $parsed['path'] : $parsed['name'],
            'server'  => [
                'user'     => $parsed['user'],
                'password' => $parsed['password'],
                'host'     => $parsed['host'],
                'port'     => (string) $parsed['port'],
            ],
        ];
    }
}
