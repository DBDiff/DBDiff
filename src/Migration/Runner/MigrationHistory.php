<?php namespace DBDiff\Migration\Runner;

use Illuminate\Database\Connection;

/**
 * Manages the _dbdiff_migrations history table.
 *
 * All state about which migrations have been applied (and whether they
 * succeeded) is stored here.  Each applied migration includes:
 *   - version        — 14-digit timestamp
 *   - description    — human-readable slug
 *   - checksum       — SHA-256 of the UP file at application time
 *   - applied_on     — wall-clock time of application
 *   - execution_ms   — how long the migration took
 *   - success        — boolean; false means it failed and left partial state
 *
 * Use repair() to clear failed entries so they can be retried.
 */
class MigrationHistory
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private Connection $connection;
    private string     $table;

    public function __construct(Connection $connection, string $table = '_dbdiff_migrations')
    {
        $this->connection = $connection;
        $this->table      = $table;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    /**
     * Create the history table if it does not already exist.
     * Uses schema-agnostic DDL compatible with MySQL, PostgreSQL, and SQLite.
     */
    public function ensureTable(): void
    {
        $driver = $this->connection->getDriverName();
        $table  = $this->table;

        if ($driver === 'pgsql') {
            $this->connection->statement(<<<SQL
                CREATE TABLE IF NOT EXISTS "{$table}" (
                    id            SERIAL PRIMARY KEY,
                    version       VARCHAR(50)  NOT NULL,
                    description   TEXT         NOT NULL,
                    checksum      VARCHAR(64),
                    applied_on    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                    execution_ms  INTEGER,
                    applied_by    VARCHAR(150),
                    success       BOOLEAN      NOT NULL DEFAULT TRUE,
                    CONSTRAINT {$table}_version_unique UNIQUE (version)
                )
            SQL);
        } elseif ($driver === 'sqlite') {
            $this->connection->statement(<<<SQL
                CREATE TABLE IF NOT EXISTS "{$table}" (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    version       TEXT    NOT NULL UNIQUE,
                    description   TEXT    NOT NULL,
                    checksum      TEXT,
                    applied_on    TEXT    NOT NULL DEFAULT (datetime('now')),
                    execution_ms  INTEGER,
                    applied_by    TEXT,
                    success       INTEGER NOT NULL DEFAULT 1
                )
            SQL);
        } else {
            // MySQL / MariaDB
            $this->connection->statement(<<<SQL
                CREATE TABLE IF NOT EXISTS `{$table}` (
                    id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    version       VARCHAR(50)  NOT NULL UNIQUE,
                    description   TEXT         NOT NULL,
                    checksum      VARCHAR(64),
                    applied_on    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    execution_ms  INT,
                    applied_by    VARCHAR(150),
                    success       TINYINT(1)   NOT NULL DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
        }
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Returns all successfully applied migration records, ordered oldest first.
     *
     * @return array<int, object>
     */
    public function getApplied(): array
    {
        return $this->connection
            ->table($this->table)
            ->where('success', true)
            ->orderBy('version')
            ->get()
            ->toArray();
    }

    /**
     * Returns all applied version strings (including baseline entries).
     *
     * @return string[]
     */
    public function getAppliedVersions(): array
    {
        return $this->connection
            ->table($this->table)
            ->where('success', true)
            ->orderBy('version')
            ->pluck('version')
            ->toArray();
    }

    /**
     * Returns failed (success = false) records that have not been repaired.
     *
     * @return array<int, object>
     */
    public function getFailed(): array
    {
        return $this->connection
            ->table($this->table)
            ->where('success', false)
            ->orderBy('version')
            ->get()
            ->toArray();
    }

    public function has(string $version): bool
    {
        return $this->connection
            ->table($this->table)
            ->where('version', $version)
            ->exists();
    }

    public function getRecord(string $version): ?object
    {
        $row = $this->connection
            ->table($this->table)
            ->where('version', $version)
            ->first();

        return $row ? (object) $row : null;
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    public function recordSuccess(MigrationFile $file, int $executionMs): void
    {
        $this->connection->table($this->table)->insert([
            'version'      => $file->version,
            'description'  => $file->description,
            'checksum'     => $file->getChecksum(),
            'applied_on'   => date(self::DATE_FORMAT),
            'execution_ms' => $executionMs,
            'applied_by'   => get_current_user(),
            'success'      => true,
        ]);
    }

    public function recordFailure(MigrationFile $file, int $executionMs): void
    {
        // Upsert: if there is already a failed entry, update it.
        $existing = $this->connection
            ->table($this->table)
            ->where('version', $file->version)
            ->first();

        if ($existing) {
            $this->connection->table($this->table)
                ->where('version', $file->version)
                ->update([
                    'applied_on'   => date(self::DATE_FORMAT),
                    'execution_ms' => $executionMs,
                    'success'      => false,
                ]);
        } else {
            $this->connection->table($this->table)->insert([
                'version'      => $file->version,
                'description'  => $file->description,
                'checksum'     => $file->getChecksum(),
                'applied_on'   => date(self::DATE_FORMAT),
                'execution_ms' => $executionMs,
                'applied_by'   => get_current_user(),
                'success'      => false,
            ]);
        }
    }

    public function remove(string $version): void
    {
        $this->connection->table($this->table)->where('version', $version)->delete();
    }

    /**
     * Remove all failed (success = false) entries so they can be retried.
     * Returns the number of rows deleted.
     */
    public function repairFailed(): int
    {
        return $this->connection->table($this->table)->where('success', false)->delete();
    }

    /**
     * Insert a baseline marker entry.  This is used to tell the runner that
     * all migrations up to this version are already applied (e.g. on an
     * existing database that predates DBDiff).
     */
    public function recordBaseline(string $version, string $description = 'baseline'): void
    {
        if ($this->has($version)) {
            return; // idempotent
        }

        $this->connection->table($this->table)->insert([
            'version'      => $version,
            'description'  => $description,
            'checksum'     => null,
            'applied_on'   => date(self::DATE_FORMAT),
            'execution_ms' => 0,
            'applied_by'   => get_current_user(),
            'success'      => true,
        ]);
    }

    // ── Supabase integration (Phase 4) ────────────────────────────────────────

    /**
     * Return the set of migration base names ('{version}_{description}') that
     * Supabase's own tracking table reports as applied.
     *
     * Reads from supabase_migrations.schema_migrations, which Supabase populates
     * when you run `supabase db push` or `supabase migration up`.  Each row's
     * `version` column stores the full migration filename without the `.sql`
     * extension, e.g. '20260303120000_create_users'.
     *
     * Returns an empty array (without throwing) when:
     *   - the supabase_migrations schema does not exist (non-Supabase DB)
     *   - the current role lacks SELECT on the table
     *   - any other query error
     *
     * @return string[]  e.g. ['20260101000000_init', '20260303120000_create_users']
     */
    public function getSupabaseAppliedVersions(): array
    {
        try {
            return $this->connection
                ->table('supabase_migrations.schema_migrations')
                ->orderBy('version')
                ->pluck('version')
                ->map(fn ($v) => (string) $v)
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
