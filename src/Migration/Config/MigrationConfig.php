<?php namespace DBDiff\Migration\Config;

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

    // ── Migrations ───────────────────────────────────────────────────────────

    public string $migrationsDir   = './migrations';
    public string $historyTable    = '_dbdiff_migrations';
    public bool   $outOfOrder      = false;

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param string|null $configFile  Explicit path to a YAML config file.
     *                                 Pass null to auto-detect dbdiff.yml in cwd.
     * @param array       $overrides   Key-value pairs that override file settings.
     *                                 Keys mirror the YAML structure flattened:
     *                                 'driver', 'host', 'port', 'name', 'path',
     *                                 'user', 'password', 'migrations_dir',
     *                                 'history_table', 'out_of_order'
     */
    public function __construct(?string $configFile = null, array $overrides = [])
    {
        $file = $configFile ?? $this->detect();

        if ($file !== null) {
            $this->loadFile($file);
        }

        $this->applyOverrides($overrides);
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
            throw new \RuntimeException("Config file not found: {$path}");
        }

        try {
            $this->raw = Yaml::parseFile($path) ?? [];
        } catch (ParseException $e) {
            throw new \RuntimeException("Failed to parse config file '{$path}': " . $e->getMessage());
        }

        // Populate database section
        $db = $this->raw['database'] ?? [];
        $this->driver   = $db['driver']   ?? $this->driver;
        $this->host     = $db['host']     ?? $this->host;
        $this->port     = (int) ($db['port'] ?? $this->port);
        $this->dbName   = $db['name']     ?? $this->dbName;
        $this->dbPath   = $db['path']     ?? $this->dbPath;
        $this->user     = $db['user']     ?? $this->user;
        $this->password = $db['password'] ?? $this->password;
        $this->sslMode  = $db['sslmode']  ?? $this->sslMode;

        // Populate migrations section
        $m = $this->raw['migrations'] ?? [];
        $this->migrationsDir = $m['dir']           ?? $this->migrationsDir;
        $this->historyTable  = $m['history_table'] ?? $this->historyTable;
        $this->outOfOrder    = (bool) ($m['out_of_order'] ?? $this->outOfOrder);
    }

    private function applyOverrides(array $overrides): void
    {
        $map = [
            'driver'          => 'driver',
            'host'            => 'host',
            'port'            => 'port',
            'name'            => 'dbName',
            'path'            => 'dbPath',
            'user'            => 'user',
            'password'        => 'password',
            'sslmode'         => 'sslMode',
            'migrations_dir'  => 'migrationsDir',
            'history_table'   => 'historyTable',
            'out_of_order'    => 'outOfOrder',
        ];

        foreach ($overrides as $key => $value) {
            if (isset($map[$key]) && $value !== null) {
                $prop = $map[$key];
                $this->$prop = $value;
            }
        }
    }
}
