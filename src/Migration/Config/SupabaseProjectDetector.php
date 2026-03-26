<?php namespace DBDiff\Migration\Config;

/**
 * Locates a Supabase project root by walking up from a given directory.
 *
 * A Supabase project root is identified by the presence of supabase/config.toml.
 *
 * Used by MigrationConfig to:
 *   1. Auto-set the migrations directory to supabase/migrations/ (Phase 1)
 *   2. Enable Supabase-aware migration:status output (Phase 4)
 *
 * Used by DiffCommand to:
 *   3. Attempt auto-fill of --server1-url via `supabase status` (Phase 4)
 *
 * DSN resolution order (first non-null wins):
 *   1. SUPABASE_DB_URL env var
 *   2. DATABASE_URL env var
 *   3. DIRECT_URL env var
 *   4. `supabase status --output json` (local stack)
 *   5. config.toml [db] section (local stack credentials)
 */
class SupabaseProjectDetector
{
    /**
     * Walk up from $startDir (defaults to cwd) and return the first ancestor
     * directory that contains supabase/config.toml.
     *
     * Returns null if no Supabase project root is found before the filesystem root.
     */
    public static function find(string $startDir = ''): ?string
    {
        $dir = rtrim($startDir ?: (string) getcwd(), '/');

        while ($dir !== '') {
            if (file_exists($dir . '/supabase/config.toml')) {
                return $dir;
            }

            $parent = dirname($dir);

            // dirname('/') === '/' — we've hit the filesystem root
            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }

    /**
     * Return the canonical migrations directory path for a detected project root.
     */
    public static function migrationsDir(string $projectRoot): string
    {
        return $projectRoot . '/supabase/migrations';
    }

    // ── DSN resolution chain ─────────────────────────────────────────────────

    /**
     * Check well-known environment variables for a database URL.
     *
     * Checks in order: SUPABASE_DB_URL → DATABASE_URL → DIRECT_URL.
     * Returns the first non-empty value, or null.
     */
    public static function envDbUrl(): ?string
    {
        foreach (['SUPABASE_DB_URL', 'DATABASE_URL', 'DIRECT_URL'] as $var) {
            $value = getenv($var);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Try to get the local Supabase stack DB URL by shelling out to
     * `supabase status --output json`.
     *
     * Returns the DB_URL string on success, null if the Supabase CLI is not
     * installed, the local stack is not running, or the output is unreadable.
     *
     * Only called when a supabase/config.toml is already confirmed present —
     * never called speculatively.
     *
     * @param string|null $projectRoot  Working directory to run the command from
     *                                  (defaults to the detected project root).
     */
    public static function localDbUrl(?string $projectRoot = null): ?string
    {
        $cwd = $projectRoot ?? self::find() ?? getcwd();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            'supabase status --output json',
            $descriptors,
            $pipes,
            $cwd
        );

        $url = null;

        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout   = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode === 0 && $stdout) {
                $data = json_decode($stdout, true);
                // Supabase CLI outputs DB_URL in the status JSON
                $url  = is_array($data) ? ($data['DB_URL'] ?? $data['db_url'] ?? null) : null;
            }
        }

        return $url;
    }

    // ── config.toml parsing ──────────────────────────────────────────────────

    /**
     * Parse the [db] section from supabase/config.toml and build a local DSN.
     *
     * Extracts `port` (default 54322) and `password` (default 'postgres') from
     * the [db] section.  Constructs:
     *   postgresql://postgres:{password}@127.0.0.1:{port}/postgres
     *
     * Returns null if the config file doesn't exist or has no [db] section.
     *
     * @param string $projectRoot  The Supabase project root directory.
     */
    public static function configTomlDbUrl(string $projectRoot): ?string
    {
        $path = $projectRoot . '/supabase/config.toml';

        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $port     = 54322;    // Supabase CLI default
        $password = 'postgres'; // Supabase CLI default

        // Extract the [db] section and parse key = value pairs.
        // We use a simple regex approach to avoid requiring a full TOML library.
        if (preg_match('/^\[db\]\s*$/m', $contents, $m, PREG_OFFSET_CAPTURE)) {
            $sectionStart = $m[0][1] + strlen($m[0][0]);
            // Read until the next [section] or end of file
            $sectionEnd = preg_match('/^\[/m', $contents, $_, 0, $sectionStart)
                ? strpos($contents, $_, $sectionStart)
                : strlen($contents);

            // Handle the case where preg_match returns the match
            if (preg_match('/^\[[^\]]/m', substr($contents, $sectionStart), $nextSection)) {
                $nextPos = strpos($contents, $nextSection[0], $sectionStart);
                $section = $nextPos !== false
                    ? substr($contents, $sectionStart, $nextPos - $sectionStart)
                    : substr($contents, $sectionStart);
            } else {
                $section = substr($contents, $sectionStart);
            }

            if (preg_match('/^\s*port\s*=\s*(\d+)/m', $section, $portMatch)) {
                $port = (int) $portMatch[1];
            }

            if (preg_match('/^\s*password\s*=\s*["\']([^"\']*)["\']$/m', $section, $pwMatch)) {
                $password = $pwMatch[1];
            } elseif (preg_match('/^\s*password\s*=\s*(\S+)/m', $section, $pwMatch)) {
                $password = trim($pwMatch[1], '"\'');
            }
        } else {
            return null;
        }

        $encodedPassword = rawurlencode($password);

        return "postgresql://postgres:{$encodedPassword}@127.0.0.1:{$port}/postgres";
    }

    // ── supabase link / remote DSN ───────────────────────────────────────────

    /**
     * Read the project reference from `.supabase/temp/project-ref`, which is
     * written by `supabase link --project-ref <ref>`.
     *
     * @param string $projectRoot  The Supabase project root directory.
     * @return string|null  The project ref (e.g. 'abcdefghijklmnopqrst'), or null.
     */
    public static function linkedProjectRef(string $projectRoot): ?string
    {
        // Try the standard location first
        $paths = [
            $projectRoot . '/.supabase/temp/project-ref',
            $projectRoot . '/supabase/.temp/project-ref',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $ref = trim(file_get_contents($path));
                if ($ref !== '') {
                    return $ref;
                }
            }
        }

        return null;
    }

    /**
     * Build a remote Supabase database URL from a linked project reference.
     *
     * Constructs a direct connection URL using the Supabase pooler format:
     *   postgresql://postgres.{ref}:{password}@aws-0-{region}.pooler.supabase.com:5432/postgres
     *
     * The password must be supplied (it's the project's database password, which
     * is never stored locally by the Supabase CLI).  If not supplied, returns a
     * partial URL with a placeholder so the user knows what to provide.
     *
     * @param string      $projectRef  The Supabase project reference (20 chars).
     * @param string|null $password    The database password.  Falls back to
     *                                 SUPABASE_DB_PASSWORD env var.
     * @param string      $region      AWS region slug (default: auto-detect).
     * @return string  A fully-formed postgresql:// URL.
     */
    public static function remoteDbUrl(
        string  $projectRef,
        ?string $password = null,
        string  $region = ''
    ): string {
        // Password fallback chain: explicit → env var → placeholder
        $password = $password
            ?? (getenv('SUPABASE_DB_PASSWORD') ?: null)
            ?? '[YOUR-PASSWORD]';

        $encodedPassword = rawurlencode($password);

        // When no region is provided, use the generic pooler hostname.
        // The Supabase pooler URL format is:
        //   aws-0-{region}.pooler.supabase.com
        // Without a known region we fall back to the direct connection format:
        //   db.{ref}.supabase.co
        if ($region !== '') {
            $host = "aws-0-{$region}.pooler.supabase.com";
            $user = "postgres.{$projectRef}";
        } else {
            $host = "db.{$projectRef}.supabase.co";
            $user = 'postgres';
        }

        return "postgresql://{$user}:{$encodedPassword}@{$host}:5432/postgres";
    }

    /**
     * Full resolution chain: attempt to resolve a DB URL using all available
     * sources, in priority order.
     *
     * 1. Environment variables (SUPABASE_DB_URL, DATABASE_URL, DIRECT_URL)
     * 2. `supabase status --output json` (local stack)
     * 3. config.toml [db] section (local stack credentials)
     *
     * @param string|null $projectRoot  The Supabase project root (auto-detected if null).
     * @return string|null  A database URL, or null if nothing could be resolved.
     */
    public static function resolveDbUrl(?string $projectRoot = null): ?string
    {
        // 1. Environment variables
        $envUrl = self::envDbUrl();
        if ($envUrl !== null) {
            return $envUrl;
        }

        $root = $projectRoot ?? self::find() ?? null;

        // 2. supabase status (local running stack)
        $localUrl = self::localDbUrl($root);
        if ($localUrl !== null) {
            return $localUrl;
        }

        // 3. config.toml [db] section
        if ($root !== null) {
            $tomlUrl = self::configTomlDbUrl($root);
            if ($tomlUrl !== null) {
                return $tomlUrl;
            }
        }

        return null;
    }
}
