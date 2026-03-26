<?php namespace DBDiff\Migration\Config;

use DBDiff\Migration\Exceptions\ConfigException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Loads and exposes configuration for the DBDiff migration module.
 *
 * Configuration is read from a YAML file (default: dbdiff.yml in the current
 * working directory) and may be partially overridden by runtime options passed
 * as an array to the constructor.
 *
 * Minimal dbdiff.yml example:
 * ─────────────────────────────
 * database:
 *   driver: mysql          # mysql | pgsql | sqlite
 *   host: 127.0.0.1
 *   port: 3306
 *   name: mydb
 *   user: root
 *   password: secret
 *
 * migrations:
 *   dir: ./migrations                   # where .up.sql / .down.sql files live
 *   history_table: _dbdiff_migrations   # tracking table name
 *   out_of_order: false                 # allow applying older versions after newer
 * ─────────────────────────────
 *
 * You may also supply a full DSN URL instead of individual fields:
 *   database:
 *     url: postgres://user:pass@db.xyz.supabase.co:5432/postgres
 *
 * For Supabase, use either the direct connection URL or the session-mode
 * pooler URL (port 6543).  SSL is enabled automatically when a Supabase host
 * is detected.
 *
 * For SQLite, omit host/port/user/password and use `path` instead of `name`:
 *   database:
 *     driver: sqlite
 *     path: ./database.sqlite
 */
class MigrationConfig
{
    private array $raw = [];

    // ── Database ─────────────────────────────────────────────────────────────

    public string $driver        = 'mysql';
    public string $host          = '127.0.0.1';
    public int    $port          = 3306;
    public string $dbName        = '';
    public string $dbPath        = '';   // SQLite file path
    public string $user          = '';
    public string $password      = '';
    public string $sslMode       = '';

    /**
     * Whether to configure the connection for pgbouncer / connection-pooler mode.
     * Automatically set to true when a Supabase pooler URL (port 6543) is used.
     * In pooler mode, server-side prepared statements are disabled.
     */
    public bool   $pgbouncer     = false;

    // ── Migrations ───────────────────────────────────────────────────────────

    public string $migrationsDir   = './migrations';
    public string $historyTable    = '_dbdiff_migrations';
    public bool   $outOfOrder      = false;

    /**
     * Migration file format: 'native' (default) or 'supabase'.
     *   native   — {version}_{desc}.up.sql + optional .down.sql
     *   supabase — {version}_{desc}.sql (UP-only, no DOWN concept)
     *
     * Auto-set to 'supabase' when a Supabase project is detected and no
     * explicit format was configured.  Commands use this as their default
     * when no --format flag is supplied.
     */
    public string $migrationFormat  = 'native';

    // ── Supabase project detection (Phase 1 + 4) ─────────────────────────────

    /** Whether a supabase/config.toml was auto-detected in cwd or a parent. */
    public bool   $isSupabaseProject  = false;

    /** Absolute path to the directory containing supabase/config.toml. */
    public string $supabaseProjectRoot = '';

    // ─────────────────────────────────────────────────────────────────────────

    /** Tracks whether migrationsDir was set from config file or CLI override. */
    private bool $migrationsDirExplicit = false;

    /** Tracks whether migrationFormat was set from config file or CLI override. */
    private bool $migrationFormatExplicit = false;

    /**
     * @param string|null $configFile  Explicit path to a YAML config file.
     *                                 Pass null to auto-detect dbdiff.yml in cwd.
     * @param array       $overrides   Key-value pairs that override file settings.
     *                                 Keys mirror the YAML structure flattened:
     *                                 'db_url'          — full DSN URL (highest precedence)
     *                                 'driver', 'host', 'port', 'name', 'path',
     *                                 'user', 'password', 'sslmode', 'pgbouncer',
     *                                 'migrations_dir', 'history_table', 'out_of_order',
     *                                 'migration_format' — 'native' or 'supabase'
     */
    public function __construct(?string $configFile = null, array $overrides = [])
    {
        $file = $configFile ?? $this->detect();

        if ($file !== null) {
            $this->loadFile($file);
        }

        $this->applyOverrides($overrides);
        $this->detectSupabaseProject();
    }

    // ── Public helpers ────────────────────────────────────────────────────────

    /**
     * Build an Illuminate-compatible connection config array ready for
     * Capsule::addConnection().
     */
    public function toConnectionConfig(): array
    {
        switch ($this->driver) {
            case 'sqlite':
                return [
                    'driver'   => 'sqlite',
                    'database' => $this->dbPath,
                    'prefix'   => '',
                ];

            case 'pgsql':
                $cfg = [
                    'driver'   => 'pgsql',
                    'host'     => $this->host,
                    'port'     => $this->port,
                    'database' => $this->dbName,
                    'username' => $this->user,
                    'password' => $this->password,
                    'charset'  => 'utf8',
                    'prefix'   => '',
                    'schema'   => 'public',
                ];
                if ($this->sslMode) {
                    $cfg['sslmode'] = $this->sslMode;
                }
                // pgbouncer (transaction-mode pooler) requires server-side
                // prepared statements to be disabled.  Session-mode pooler
                // (Supabase port 6543) works fine without this, but it's
                // harmless to set in either case.
                if ($this->pgbouncer) {
                    $cfg['options'] = [
                        \PDO::ATTR_EMULATE_PREPARES => true,
                    ];
                }
                return $cfg;

            default: // mysql
                return [
                    'driver'    => 'mysql',
                    'host'      => $this->host,
                    'port'      => $this->port,
                    'database'  => $this->dbName,
                    'username'  => $this->user,
                    'password'  => $this->password,
                    'charset'   => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix'    => '',
                ];
        }
    }

    /** Returns the resolved absolute path to the migrations directory. */
    public function resolveMigrationsDir(): string
    {
        $dir = $this->migrationsDir;

        if (!str_starts_with($dir, '/')) {
            $dir = getcwd() . '/' . ltrim($dir, './');
        }

        return rtrim($dir, '/');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Auto-detect a Supabase project by walking up from cwd.
     * Only runs when neither migrationsDir nor migrationFormat was explicitly set.
     * Sets $isSupabaseProject, $supabaseProjectRoot, $migrationsDir, $migrationFormat.
     */
    private function detectSupabaseProject(): void
    {
        $root = SupabaseProjectDetector::find();

        if ($root === null) {
            return;
        }

        $this->isSupabaseProject  = true;
        $this->supabaseProjectRoot = $root;

        if (!$this->migrationsDirExplicit) {
            $this->migrationsDir = SupabaseProjectDetector::migrationsDir($root);
        }

        if (!$this->migrationFormatExplicit) {
            $this->migrationFormat = 'supabase';
        }
    }

    private function detect(): ?string
    {
        $candidates = [
            getcwd() . '/dbdiff.yml',
            getcwd() . '/dbdiff.yaml',
            getcwd() . '/.dbdiff.yml',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function loadFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new ConfigException("Config file not found: {$path}");
        }

        try {
            $this->raw = Yaml::parseFile($path) ?? [];
        } catch (ParseException $e) {
            throw new ConfigException("Failed to parse config file '{$path}': " . $e->getMessage());
        }

        // Populate database section
        $db = $this->raw['database'] ?? [];

        // A `url` key takes precedence over individual fields
        if (!empty($db['url'])) {
            $this->applyDsn($db['url']);
        }

        // Individual fields override URL-parsed values if both are present
        $this->driver     = $db['driver']   ?? $this->driver;
        $this->host       = $db['host']     ?? $this->host;
        $this->port       = (int) ($db['port'] ?? $this->port);
        $this->dbName     = $db['name']     ?? $this->dbName;
        $this->dbPath     = $db['path']     ?? $this->dbPath;
        $this->user       = $db['user']     ?? $this->user;
        $this->password   = $db['password'] ?? $this->password;
        $this->sslMode    = $db['sslmode']  ?? $this->sslMode;
        $this->pgbouncer  = (bool) ($db['pgbouncer'] ?? $this->pgbouncer);

        // Populate migrations section
        $m = $this->raw['migrations'] ?? [];

        if (isset($m['dir'])) {
            $this->migrationsDir       = $m['dir'];
            $this->migrationsDirExplicit = true;
        }

        if (isset($m['format'])) {
            $this->migrationFormat       = $m['format'];
            $this->migrationFormatExplicit = true;
        }

        $this->historyTable = $m['history_table'] ?? $this->historyTable;
        $this->outOfOrder   = (bool) ($m['out_of_order'] ?? $this->outOfOrder);
    }

    /**
     * Parse a DSN URL and populate connection properties from it.
     * Calls DsnParser::parse() internally — see that class for supported URL formats.
     */
    private function applyDsn(string $url): void
    {
        $parsed         = DsnParser::parse($url);
        $this->driver   = $parsed['driver'];
        $this->host     = $parsed['host'];
        $this->port     = $parsed['port'];
        $this->dbName   = $parsed['name'];
        $this->dbPath   = $parsed['path'];
        $this->user     = $parsed['user'];
        $this->password = $parsed['password'];

        if (!empty($parsed['sslmode'])) {
            $this->sslMode = $parsed['sslmode'];
        }

        if ($parsed['pgbouncer']) {
            $this->pgbouncer = true;
        }
    }

    private function applyOverrides(array $overrides): void
    {
        // A db_url in overrides (e.g. from --db-url CLI flag) wins over everything
        if (!empty($overrides['db_url'])) {
            $this->applyDsn($overrides['db_url']);
            unset($overrides['db_url']);
        }

        $map = [
            'driver'           => 'driver',
            'host'             => 'host',
            'port'             => 'port',
            'name'             => 'dbName',
            'path'             => 'dbPath',
            'user'             => 'user',
            'password'         => 'password',
            'sslmode'          => 'sslMode',
            'pgbouncer'        => 'pgbouncer',
            'migrations_dir'   => 'migrationsDir',
            'history_table'    => 'historyTable',
            'out_of_order'     => 'outOfOrder',
            'migration_format' => 'migrationFormat',
        ];

        foreach ($overrides as $key => $value) {
            if (isset($map[$key]) && $value !== null) {
                $prop = $map[$key];
                $this->$prop = $value;

                // Track explicit overrides so Supabase auto-detection doesn't clobber them
                if ($key === 'migrations_dir') {
                    $this->migrationsDirExplicit = true;
                } elseif ($key === 'migration_format') {
                    $this->migrationFormatExplicit = true;
                }
            }
        }
    }
}
