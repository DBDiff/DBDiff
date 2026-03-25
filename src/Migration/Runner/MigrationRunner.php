<?php namespace DBDiff\Migration\Runner;

use DBDiff\Migration\Config\MigrationConfig;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;

/**
 * MigrationRunner — the core Phase 1.5 engine.
 *
 * Responsibilities:
 *   up()       — apply all pending migrations (or up to a target version)
 *   down()     — roll back the last N applied migrations
 *   status()   — return a status report array
 *   validate() — verify on-disk checksums match history table
 *   repair()   — remove failed entries so they can be retried
 *   baseline() — mark a version as the starting baseline
 *
 * Safety model (mirrors Flyway):
 *   1. Wrap each migration in a transaction
 *   2. On success → write to history, commit
 *   3. On failure → rollback that migration, mark it failed, HALT
 *   4. `repair` clears the failed row so it can be retried
 *
 * All output is returned as structured data; callers (commands) handle display.
 */
class MigrationRunner
{
    private MigrationConfig  $config;
    private Connection       $connection;
    private MigrationHistory $history;

    public function __construct(MigrationConfig $config)
    {
        $this->config     = $config;
        $this->connection = $this->buildConnection($config);
        $this->history    = new MigrationHistory($this->connection, $config->historyTable);
        $this->history->ensureTable();
    }

    // ── Core operations ───────────────────────────────────────────────────────

    /**
     * Apply pending migrations.
     *
     * @param  string|null $target  Stop after applying this version (inclusive).
     *                              Pass null to apply all pending.
     * @return array  Each entry: ['version' => '...', 'description' => '...', 'status' => 'applied'|'skipped'|'failed', 'ms' => int, 'error' => string|null]
     */
    public function up(?string $target = null): array
    {
        $dir     = $this->config->resolveMigrationsDir();
        $all     = MigrationFile::scanDir($dir);
        $applied = $this->history->getAppliedVersions();
        $results = [];

        foreach ($all as $file) {
            // Stop once we've passed the target version
            if ($target !== null && $file->version > $target) {
                break;
            }

            // Skip already-applied migrations
            // When out_of_order is false (default), also skip if an older version
            // appears AFTER a newer applied one — leave that edge to the caller to report.
            if (in_array($file->version, $applied, true)) {
                continue;
            }

            $t0 = microtime(true);

            try {
                $sql = $file->getUpSql();
                $this->executeInTransaction($sql);
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $this->history->recordSuccess($file, $ms);
                $results[] = [
                    'version'     => $file->version,
                    'description' => $file->description,
                    'status'      => 'applied',
                    'ms'          => $ms,
                    'error'       => null,
                ];
            } catch (\Throwable $e) {
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $this->history->recordFailure($file, $ms);
                $results[] = [
                    'version'     => $file->version,
                    'description' => $file->description,
                    'status'      => 'failed',
                    'ms'          => $ms,
                    'error'       => $e->getMessage(),
                ];
                // HALT on first failure
                break;
            }
        }

        return $results;
    }

    /**
     * Roll back the last $count successfully applied migrations.
     *
     * @param  int         $count  Number of migrations to roll back (default: 1)
     * @param  string|null $target Roll back to (but not including) this version
     * @return array  Same shape as up() results but status is 'rolled_back'|'no_down'|'failed'
     */
    public function down(int $count = 1, ?string $target = null): array
    {
        $applied = array_reverse($this->history->getApplied()); // newest first
        $dir     = $this->config->resolveMigrationsDir();
        $results = [];
        $done    = 0;

        foreach ($applied as $record) {
            if ($target !== null && $record->version <= $target) {
                break;
            }

            if ($count > 0 && $done >= $count) {
                break;
            }

            // Find the file on disk
            $file = $this->findFile($dir, $record->version);

            if ($file === null || !$file->hasDown()) {
                $results[] = [
                    'version'     => $record->version,
                    'description' => $record->description,
                    'status'      => 'no_down',
                    'ms'          => 0,
                    'error'       => 'No .down.sql file found — cannot roll back.',
                ];
                // Treat missing DOWN file as a soft halt so the caller can decide
                break;
            }

            $t0 = microtime(true);

            try {
                $sql = $file->getDownSql();
                $this->executeInTransaction($sql);
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $this->history->remove($record->version);
                $results[] = [
                    'version'     => $record->version,
                    'description' => $record->description,
                    'status'      => 'rolled_back',
                    'ms'          => $ms,
                    'error'       => null,
                ];
                $done++;
            } catch (\Throwable $e) {
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $results[] = [
                    'version'     => $record->version,
                    'description' => $record->description,
                    'status'      => 'failed',
                    'ms'          => $ms,
                    'error'       => $e->getMessage(),
                ];
                break; // HALT on failure
            }
        }

        return $results;
    }

    /**
     * Return a status report combining on-disk files and history table data.
     *
     * @return array  Each entry:
     *   ['version', 'description', 'state' => 'applied'|'pending'|'failed'|'checksum_mismatch', 'applied_on', 'checksum_ok']
     */
    public function status(): array
    {
        $dir     = $this->config->resolveMigrationsDir();
        $files   = MigrationFile::scanDir($dir);
        $applied = [];

        foreach ($this->history->getApplied() as $row) {
            $applied[(string) $row->version] = $row;
        }

        $failed = [];
        foreach ($this->history->getFailed() as $row) {
            $failed[(string) $row->version] = $row;
        }

        $report = [];
        $seen   = [];

        foreach ($files as $file) {
            $seen[$file->version] = true;

            if (isset($applied[$file->version])) {
                $record   = $applied[$file->version];
                $diskHash = $file->getChecksum();
                $ok       = $record->checksum === $diskHash;

                $report[] = [
                    'version'      => $file->version,
                    'description'  => $file->description,
                    'state'        => $ok ? 'applied' : 'checksum_mismatch',
                    'applied_on'   => $record->applied_on,
                    'checksum_ok'  => $ok,
                    'has_down'     => $file->hasDown(),
                    'execution_ms' => $record->execution_ms ?? null,
                ];
            } elseif (isset($failed[$file->version])) {
                $report[] = [
                    'version'      => $file->version,
                    'description'  => $file->description,
                    'state'        => 'failed',
                    'applied_on'   => $failed[$file->version]->applied_on ?? null,
                    'checksum_ok'  => null,
                    'has_down'     => $file->hasDown(),
                    'execution_ms' => null,
                ];
            } else {
                $report[] = [
                    'version'      => $file->version,
                    'description'  => $file->description,
                    'state'        => 'pending',
                    'applied_on'   => null,
                    'checksum_ok'  => null,
                    'has_down'     => $file->hasDown(),
                    'execution_ms' => null,
                ];
            }
        }

        // Also report applied migrations whose files are no longer on disk
        foreach ($applied as $version => $record) {
            if (!isset($seen[$version])) {
                $report[] = [
                    'version'      => $version,
                    'description'  => $record->description,
                    'state'        => 'missing_file',
                    'applied_on'   => $record->applied_on,
                    'checksum_ok'  => false,
                    'has_down'     => false,
                    'execution_ms' => $record->execution_ms ?? null,
                ];
            }
        }

        // Sort by version ascending
        usort($report, fn($a, $b) => strcmp($a['version'], $b['version']));

        return $report;
    }

    /**
     * Verify that on-disk checksums match what was recorded when migrations ran.
     *
     * @return array  Entries only for migrations where the checksum does NOT match
     *                (or the file is missing).  Empty array means everything is clean.
     */
    public function validate(): array
    {
        $dir    = $this->config->resolveMigrationsDir();
        $files  = MigrationFile::scanDir($dir);
        $index  = [];
        foreach ($files as $f) {
            $index[$f->version] = $f;
        }

        $mismatches = [];

        foreach ($this->history->getApplied() as $record) {
            $version = (string) $record->version;

            if (!isset($index[$version])) {
                $mismatches[] = [
                    'version'     => $version,
                    'description' => $record->description,
                    'issue'       => 'file_missing',
                    'expected'    => $record->checksum,
                    'actual'      => null,
                ];
                continue;
            }

            $diskHash = $index[$version]->getChecksum();
            if ($record->checksum !== $diskHash) {
                $mismatches[] = [
                    'version'     => $version,
                    'description' => $record->description,
                    'issue'       => 'checksum_mismatch',
                    'expected'    => $record->checksum,
                    'actual'      => $diskHash,
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Clear all failed migration entries from the history table.
     * Returns the number of rows removed.
     */
    public function repair(): int
    {
        return $this->history->repairFailed();
    }

    /**
     * Return the set of migration base names tracked by Supabase's own
     * supabase_migrations.schema_migrations table.
     *
     * Delegates directly to MigrationHistory::getSupabaseAppliedVersions().
     * Returns an empty array when the table is unreachable or absent.
     *
     * @return string[]  e.g. ['20260101000000_init', '20260303120000_create_users']
     */
    public function getSupabaseAppliedVersions(): array
    {
        return $this->history->getSupabaseAppliedVersions();
    }

    /**
     * Record the current state as the baseline.
     * All migrations at or before $version will be treated as already applied.
     *
     * @param string $version  A 14-digit timestamp version, or 'now' to use the
     *                         current timestamp.
     */
    public function baseline(string $version = 'now', string $description = 'baseline'): void
    {
        if ($version === 'now') {
            $version = date('YmdHis');
        }

        $this->history->recordBaseline($version, $description);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Execute multi-statement SQL inside a single transaction.
     * Splits on semicolons, ignoring empty statements and comment-only lines.
     */
    private function executeInTransaction(string $sql): void
    {
        $this->connection->beginTransaction();

        try {
            foreach ($this->splitStatements($sql) as $statement) {
                // Skip pure-comment statements
                $stripped = preg_replace('/--[^\n]*/', '', $statement);
                if (trim($stripped) === '') {
                    continue;
                }

                $this->connection->statement($statement);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Naively split SQL on semicolons.
     * Complex cases (e.g. stored procedures with BEGIN…END) may need the
     * --delimiter override that more advanced runners support — keep it simple
     * for now since DBDiff targets schema migrations, not stored routines.
     *
     * @return string[]
     */
    private function splitStatements(string $sql): array
    {
        return array_filter(
            array_map('trim', explode(';', $sql)),
            fn(string $s) => $s !== ''
        );
    }

    private function findFile(string $dir, string $version): ?MigrationFile
    {
        foreach (MigrationFile::scanDir($dir) as $file) {
            if ($file->version === $version) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Build an Illuminate Connection from config, without requiring the full
     * DBManager (which is designed for two-connection diff use-cases).
     */
    private function buildConnection(MigrationConfig $config): Connection
    {
        $capsule    = new Capsule;
        $dispatcher = new Dispatcher;
        $dispatcher->listen(StatementPrepared::class, function ($event) {
            $event->statement->setFetchMode(\PDO::FETCH_OBJ);
        });
        $capsule->setEventDispatcher($dispatcher);
        $capsule->addConnection($config->toConnectionConfig(), 'migration');

        return $capsule->getConnection('migration');
    }
}
