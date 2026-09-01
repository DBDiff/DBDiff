<?php

declare(strict_types=1);

namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * Renders object DDL with PostgreSQL's own tooling instead of by hand.
 *
 * Reconstructing DDL from the catalog is a large surface, and the hand-written
 * renderer reproduces 52 of 90 cases in the shared conformance corpus. pg_dump
 * reproduces 90 of 90, in both directions, because it is the reference
 * implementation and is maintained in lockstep with the server.
 *
 * The awkward part of using it is that pg_dump emits a whole schema, while a
 * diff needs one object at a time — and splitting SQL by hand goes wrong on
 * dollar-quoted bodies, semicolons inside string literals, and comments. That
 * is avoided entirely here:
 *
 *   pg_dump -Fc          writes an archive
 *   pg_restore -l        lists one entry per object
 *   pg_restore -L file   emits SQL for exactly the entries you select
 *
 * So the splitting is pg_dump's own, and no SQL is ever parsed. Two rules
 * apply, both learned by getting them wrong:
 *
 *   --no-owner belongs on pg_restore. On pg_dump -Fc it is accepted and
 *   silently ignored, and the ownership statements come out anyway.
 *
 *   pg_restore -L honours the order of the list it is given, not dependency
 *   order. The listing from -l is already correct, so it is filtered and never
 *   re-sorted.
 *
 * Availability is never assumed. pg_dump is not bundled with DBDiff and cannot
 * be — the binaries are static PHP. When it is missing, or older than the
 * server it is pointed at, the caller falls back to the hand-written renderer.
 */
final class PgDumpRenderer
{
    /** Cached archive path per connection, so one run dumps each database once. */
    private static array $archives = [];

    /** Cached table of contents per archive. */
    private static array $listings = [];

    /** Why this renderer is unavailable, per connection. Null means usable. */
    private static array $unavailable = [];

    /** Whether any DDL in this process actually came from pg_dump. */
    private static bool $used = false;

    /** Reset all caches. For tests, and for a process that reconnects. */
    public static function reset(): void
    {
        foreach (self::$archives as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        self::$archives = self::$listings = self::$unavailable = [];
        self::$used = false;
    }

    /**
     * The DDL for one table, or null when this renderer cannot produce it.
     *
     * Null is not an error: it means the caller should use its own renderer.
     */
    public static function tableDDL(Connection $connection, string $table): ?string
    {
        // Because this renderer is used whenever pg_dump happens to be present,
        // the same DBDiff version can emit different SQL on different machines.
        // DBDIFF_PG_DUMP_RENDERER=off pins a run to the built-in renderer, which
        // is what the fixture-based tests rely on.
        if (strtolower((string) getenv('DBDIFF_PG_DUMP_RENDERER')) === 'off') {
            return null;
        }

        $archive = self::archiveFor($connection);
        if ($archive === null) {
            return null;
        }

        // A partition's DDL is spread over TABLE, TABLE ATTACH, CONSTRAINT and
        // INDEX ATTACH entries whose replay interacts with the constraints
        // DBDiff already emits for the parent — reproducing them here produced
        // "multiple primary keys for table". The existing renderer handles
        // partitions correctly, so they are left to it.
        if (PostgresSchemaHelper::partitionMeta($connection, $table)['is_partition']) {
            return null;
        }

        // Every entry naming this table: the table itself plus its indexes,
        // constraints, comments and anything else pg_dump attributes to it.
        $entries = self::entriesFor($connection, $table);
        if ($entries === []) {
            return null;
        }

        $listFile = tempnam(sys_get_temp_dir(), 'dbdiff_toc_');
        file_put_contents($listFile, implode("\n", $entries) . "\n");

        try {
            $result = self::run([
                self::binary('pg_restore'), '--no-owner', '--no-privileges',
                '-L', $listFile, '-f', '-', $archive,
            ], self::environment($connection));
        } finally {
            @unlink($listFile);
        }

        if ($result['status'] !== 0) {
            return null;
        }

        $sql = self::clean($result['stdout']);
        if (trim($sql) === '') {
            return null;
        }
        self::$used = true;

        return $sql;
    }

    /**
     * Whether this renderer produced any DDL in the current process.
     *
     * Recorded in the migration header, because the renderer is chosen from
     * what happens to be installed: the same DBDiff version emits different
     * SQL on a machine with pg_dump and one without, and a reader comparing
     * two migrations deserves to know which they are looking at.
     */
    public static function wasUsed(): bool
    {
        return self::$used;
    }

    /** Human-readable reason this renderer is not in use, if it is not. */
    public static function unavailableReason(Connection $connection): ?string
    {
        self::archiveFor($connection);

        return self::$unavailable[self::key($connection)] ?? null;
    }

    /** Whether the pg_dump path is what produced DDL for this connection. */
    public static function isActive(Connection $connection): bool
    {
        return self::archiveFor($connection) !== null;
    }

    // ── internals ───────────────────────────────────────────────────────────

    private static function key(Connection $connection): string
    {
        return implode('|', [
            (string) $connection->getConfig('host'),
            (string) $connection->getConfig('port'),
            (string) $connection->getConfig('database'),
        ]);
    }

    /**
     * Dump the database once and cache the archive, or record why we cannot.
     */
    private static function archiveFor(Connection $connection): ?string
    {
        $key = self::key($connection);

        if (isset(self::$archives[$key])) {
            return self::$archives[$key];
        }
        if (array_key_exists($key, self::$unavailable)) {
            return null;
        }

        $reason = self::checkUsable($connection);
        if ($reason !== null) {
            self::$unavailable[$key] = $reason;
            return null;
        }

        $archive = tempnam(sys_get_temp_dir(), 'dbdiff_dump_');
        $result = self::run([
            self::binary('pg_dump'), '--schema-only', '--format=custom',
            '--file=' . $archive, '--dbname=' . self::dsn($connection),
        ], self::environment($connection));

        if ($result['status'] !== 0 || !is_file($archive) || filesize($archive) === 0) {
            @unlink($archive);
            self::$unavailable[$key] = 'pg_dump failed: ' . self::firstLine($result['stderr']);
            return null;
        }

        return self::$archives[$key] = $archive;
    }

    /**
     * Whether pg_dump is present and new enough.
     *
     * pg_dump refuses to read a server newer than itself, so the check is that
     * its major version is at least the server's. A newer pg_dump against an
     * older server is supported and normal.
     */
    private static function checkUsable(Connection $connection): ?string
    {
        $version = self::run([self::binary('pg_dump'), '--version'], []);
        if ($version['status'] !== 0) {
            return 'pg_dump is not available on PATH';
        }
        if (self::run([self::binary('pg_restore'), '--version'], [])['status'] !== 0) {
            return 'pg_restore is not available on PATH';
        }

        if (!preg_match('/(\d+)/', $version['stdout'], $m)) {
            return 'could not read the pg_dump version';
        }
        $toolMajor = (int) $m[1];

        $rows = $connection->select('SHOW server_version_num');
        $serverNum = (int) (((array) ($rows[0] ?? []))['server_version_num'] ?? 0);
        if ($serverNum === 0) {
            return 'could not read the server version';
        }
        $serverMajor = intdiv($serverNum, 10000);

        if ($toolMajor < $serverMajor) {
            return sprintf(
                'pg_dump %d is older than the server (%d); it cannot read this database',
                $toolMajor,
                $serverMajor
            );
        }

        return null;
    }

    /**
     * Table-of-contents entries belonging to one table.
     *
     * A table's DDL is spread across several entries under names that are not
     * the table's own — an identity column's ALTER TABLE ... ADD GENERATED
     * arrives under a SEQUENCE entry named after the sequence, and indexes
     * under their own names:
     *
     *   218;  1259 19929 TABLE    public h
     *   217;  1259 19928 SEQUENCE public h_id_seq   <- the identity clause
     *   3277; 1259 19937 INDEX    public h_i
     *
     * Matching on the table name alone therefore emits a CREATE TABLE with its
     * identity and indexes silently missing. The set of related names comes
     * from the catalog instead of being guessed, and a line matches when any
     * of its fields names something in that set — which covers every entry
     * layout pg_restore uses (TYPE schema name, TYPE schema table name, and
     * COMMENT schema OBJTYPE name) without parsing each one differently.
     *
     * Order is preserved from the listing: pg_restore replays entries in the
     * order given, and the listing is already in dependency order.
     *
     * @return list<string>
     */
    private static function entriesFor(Connection $connection, string $table): array
    {
        $listing     = self::listingFor($connection);
        $schema      = (string) ($connection->getConfig('schema') ?: 'public');
        $names       = self::relatedNames($connection, $table);

        $wanted = [];
        foreach ($listing as $line) {
            if (!preg_match('/^\s*\d+;\s+\d+\s+\d+\s+(.*)$/', $line, $m)) {
                continue;
            }
            $fields = preg_split('/\s+/', trim($m[1])) ?: [];

            // The schema must appear, so an identically named object in another
            // schema cannot be pulled in.
            $schemaIndex = array_search($schema, $fields, true);
            if ($schemaIndex === false) {
                continue;
            }

            // Everything before the schema is the entry type, which can be more
            // than one word ("FK CONSTRAINT", "MATERIALIZED VIEW").
            if (!self::wantsType(implode(' ', array_slice($fields, 0, (int) $schemaIndex)))) {
                continue;
            }

            foreach ($fields as $field) {
                if (isset($names[$field])) {
                    $wanted[] = $line;
                    break;
                }
            }
        }

        return $wanted;
    }

    /**
     * Which table-of-contents entry types belong in a CREATE TABLE.
     *
     * Not everything pg_dump attributes to a table is this renderer's to emit.
     *
     * Triggers are excluded because DBDiff generates those from its own diff of
     * programmable objects. Emitting them here as well put a trigger ahead of
     * the function it executes, and the migration failed with "function
     * public.h_f() does not exist".
     *
     * TABLE ATTACH and INDEX ATTACH are excluded for the same reason
     * partitions are skipped entirely — see tableDDL.
     */
    private static function wantsType(string $type): bool
    {
        return in_array(
            $type,
            ['TABLE', 'SEQUENCE', 'INDEX', 'CONSTRAINT', 'FK CONSTRAINT', 'DEFAULT', 'COMMENT'],
            true
        );
    }

    /**
     * Every object name whose DDL belongs with this table.
     *
     * @return array<string, true>
     */
    private static function relatedNames(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            "SELECT c.relname AS name
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public' AND c.relname = ?
             UNION
             SELECT i.relname
               FROM pg_index x
               JOIN pg_class i ON i.oid = x.indexrelid
               JOIN pg_class t ON t.oid = x.indrelid
               JOIN pg_namespace n ON n.oid = t.relnamespace
              WHERE n.nspname = 'public' AND t.relname = ?
             UNION
             -- Sequences owned by a column carry that column's identity clause.
             SELECT s.relname
               FROM pg_depend d
               JOIN pg_class s ON s.oid = d.objid AND s.relkind = 'S'
               JOIN pg_class t ON t.oid = d.refobjid
               JOIN pg_namespace n ON n.oid = t.relnamespace
              WHERE n.nspname = 'public' AND t.relname = ? AND d.deptype IN ('a','i')
             UNION
             SELECT con.conname
               FROM pg_constraint con
               JOIN pg_class t ON t.oid = con.conrelid
               JOIN pg_namespace n ON n.oid = t.relnamespace
              WHERE n.nspname = 'public' AND t.relname = ?
             UNION
             SELECT tg.tgname
               FROM pg_trigger tg
               JOIN pg_class t ON t.oid = tg.tgrelid
               JOIN pg_namespace n ON n.oid = t.relnamespace
              WHERE n.nspname = 'public' AND t.relname = ?
                AND NOT tg.tgisinternal",
            [$table, $table, $table, $table, $table]
        );

        $names = [];
        foreach ($rows as $row) {
            $name = ((array) $row)['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /** @return list<string> */
    private static function listingFor(Connection $connection): array
    {
        $key = self::key($connection);
        if (isset(self::$listings[$key])) {
            return self::$listings[$key];
        }

        $archive = self::$archives[$key] ?? null;
        if ($archive === null) {
            return [];
        }

        $result = self::run([self::binary('pg_restore'), '-l', $archive], self::environment($connection));
        if ($result['status'] !== 0) {
            return self::$listings[$key] = [];
        }

        $lines = [];
        foreach (explode("\n", $result['stdout']) as $line) {
            if (trim($line) !== '' && !str_starts_with(ltrim($line), ';')) {
                $lines[] = $line;
            }
        }

        return self::$listings[$key] = $lines;
    }

    /**
     * Strip what pg_restore emits around the DDL.
     *
     * psql meta-commands (\restrict on recent versions), session SET
     * statements and ownership changes are not part of a migration.
     */
    private static function clean(string $sql): string
    {
        $keep = [];
        foreach (explode("\n", $sql) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '\\')
                || str_starts_with($trimmed, '--')
                || preg_match('/^SET\s/', $trimmed)
                || preg_match('/^SELECT pg_catalog\.set_config/', $trimmed)
                || preg_match('/^ALTER .* OWNER TO /', $trimmed)) {
                continue;
            }
            $keep[] = $line;
        }

        // Callers append their own terminator (AddTableSQL does `. ';'`), so a
        // trailing one here produced `;;`.
        return rtrim(trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $keep)) ?? ''), "; \t\n");
    }

    private static function binary(string $name): string
    {
        $override = getenv('DBDIFF_' . strtoupper($name));

        return $override !== false && $override !== '' ? $override : $name;
    }

    private static function dsn(Connection $connection): string
    {
        return sprintf(
            'postgresql://%s@%s:%s/%s',
            rawurlencode((string) $connection->getConfig('username')),
            (string) $connection->getConfig('host'),
            (string) $connection->getConfig('port'),
            rawurlencode((string) $connection->getConfig('database'))
        );
    }

    /**
     * The password travels in the environment, never on the command line,
     * where it would be visible to anyone who can list processes.
     *
     * @return array<string, string>
     */
    private static function environment(Connection $connection): array
    {
        $env = ['PGPASSWORD' => (string) $connection->getConfig('password')];

        $sslmode = $connection->getConfig('sslmode');
        if (is_string($sslmode) && $sslmode !== '') {
            $env['PGSSLMODE'] = $sslmode;
        }

        return $env;
    }

    /**
     * Run a command without a shell, so no argument needs quoting.
     *
     * @param  list<string>          $command
     * @param  array<string, string> $env
     * @return array{status:int, stdout:string, stderr:string}
     */
    private static function run(array $command, array $env): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, null, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);

        if (!is_resource($process)) {
            return ['status' => 127, 'stdout' => '', 'stderr' => 'could not start ' . ($command[0] ?? '?')];
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function firstLine(string $text): string
    {
        $line = strtok(trim($text), "\n");

        return $line === false ? '' : $line;
    }
}
