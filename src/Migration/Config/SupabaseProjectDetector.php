<?php namespace DBDiff\Migration\Config;

/**
 * Locates a Supabase project root by walking up from a given directory.
 *
 * A Supabase project root is identified by the presence of supabase/config.toml.
 * We do NOT parse the TOML — presence alone is sufficient for auto-detection.
 *
 * Used by MigrationConfig to:
 *   1. Auto-set the migrations directory to supabase/migrations/ (Phase 1)
 *   2. Enable Supabase-aware migration:status output (Phase 4)
 *
 * Used by DiffCommand to:
 *   3. Attempt auto-fill of --server1-url via `supabase status` (Phase 4)
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
}
