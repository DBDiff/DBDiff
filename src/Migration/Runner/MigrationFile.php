<?php namespace DBDiff\Migration\Runner;

use DBDiff\Migration\Exceptions\MigrationException;

/**
 * Represents a single migration unit on disk.
 *
 * Two on-disk formats are supported:
 *
 *   DBDiff native (default):
 *     {version}_{description}.up.sql    (required)
 *     {version}_{description}.down.sql  (optional, needed for rollback)
 *
 *   Supabase:
 *     {version}_{description}.sql       (UP-only — no DOWN concept)
 *
 * In both cases the version is a 14-digit timestamp prefix, e.g.:
 *   20260303120000_create_users.up.sql
 *   20260303120000_create_users.sql
 *
 * To create a file pair on disk, use MigrationFile::scaffold().
 * To load all migrations from a directory, use MigrationFile::scanDir().
 */
class MigrationFile
{
    public const UP_SUFFIX   = '.up.sql';
    public const DOWN_SUFFIX = '.down.sql';

    /** 14-digit timestamp, e.g. '20260303120000' */
    public string $version;

    /** Human-readable slug, e.g. 'create_users' */
    public string $description;

    /** Absolute path to the UP file */
    public string $upPath;

    /** Absolute path to the DOWN file (may not exist) */
    public string $downPath;

    public function __construct(string $version, string $description, string $upPath, string $downPath)
    {
        $this->version     = $version;
        $this->description = $description;
        $this->upPath      = $upPath;
        $this->downPath    = $downPath;
    }

    // ── SQL accessors ─────────────────────────────────────────────────────────

    public function getUpSql(): string
    {
        if (!file_exists($this->upPath)) {
            throw new MigrationException("UP file not found: {$this->upPath}");
        }

        return file_get_contents($this->upPath);
    }

    public function hasDown(): bool
    {
        return file_exists($this->downPath);
    }

    public function getDownSql(): ?string
    {
        return $this->hasDown() ? file_get_contents($this->downPath) : null;
    }

    /** SHA-256 checksum of the UP SQL (used for tamper detection). */
    public function getChecksum(): string
    {
        return hash('sha256', $this->getUpSql());
    }

    /** Canonical base name without extension, e.g. '20260303120000_create_users'. */
    public function getBaseName(): string
    {
        return "{$this->version}_{$this->description}";
    }

    // ── Factory / scan helpers ────────────────────────────────────────────────

    /**
     * Scan a migrations directory and return all valid MigrationFile instances,
     * sorted ascending by version (i.e. oldest first).
     *
     * Supports two on-disk formats:
     *   DBDiff native : {version}_{desc}.up.sql  + optional .down.sql
     *   Supabase      : {version}_{desc}.sql      (UP-only, no DOWN concept)
     *
     * If both formats are present for the same version, the DBDiff native
     * (.up.sql) file takes precedence.
     *
     * @return MigrationFile[]
     */
    public static function scanDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        // ── DBDiff native format (.up.sql) ────────────────────────────────────
        $upFiles = glob("{$dir}/*" . self::UP_SUFFIX) ?: [];

        $migrations = [];

        foreach ($upFiles as $upPath) {
            $baseName = basename($upPath, self::UP_SUFFIX);
            $parsed   = self::parseName($baseName);

            if ($parsed === null) {
                continue;
            }

            [$version, $description] = $parsed;
            $downPath = $dir . '/' . $baseName . self::DOWN_SUFFIX;

            $migrations[$version] = new self($version, $description, $upPath, $downPath);
        }

        // ── Supabase format (plain .sql — no .up/.down suffix) ───────────────
        $allSqlFiles = glob("{$dir}/*.sql") ?: [];

        foreach ($allSqlFiles as $upPath) {
            // Skip files already handled as DBDiff native format
            if (str_ends_with($upPath, self::UP_SUFFIX) || str_ends_with($upPath, self::DOWN_SUFFIX)) {
                continue;
            }

            $baseName = basename($upPath, '.sql');
            $parsed   = self::parseName($baseName);

            if ($parsed === null) {
                continue;
            }

            [$version, $description] = $parsed;

            // .up.sql takes precedence if the same version was already registered
            if (isset($migrations[$version])) {
                continue;
            }

            // Supabase migrations are UP-only; downPath points to a non-existent path
            $downPath = $dir . '/' . $baseName . self::DOWN_SUFFIX;

            $migrations[$version] = new self($version, $description, $upPath, $downPath);
        }

        // Sort ascending by version (14-digit timestamp key)
        ksort($migrations);

        return array_values($migrations);
    }

    /**
     * Scaffold a new migration file pair in the given directory.
     * Returns the MigrationFile instance representing the created files.
     *
     * @throws \RuntimeException if the directory cannot be created or files already exist
     */
    public static function scaffold(string $dir, string $description, string $version = ''): self
    {
        $version     = $version ?: date('YmdHis');
        $slug        = self::slugify($description);
        $baseName    = "{$version}_{$slug}";
        $upPath      = "{$dir}/{$baseName}.up.sql";
        $downPath    = "{$dir}/{$baseName}.down.sql";

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new MigrationException("Cannot create migrations directory: {$dir}");
        }

        foreach ([$upPath, $downPath] as $path) {
            if (file_exists($path)) {
                throw new MigrationException("Migration file already exists: {$path}");
            }
        }

        $directionLabel    = ucfirst(strtolower($slug));
        $upTemplate = <<<SQL
-- DBDiff migration: {$directionLabel}
-- Version    : {$version}
-- Direction  : UP
-- Description: Edit this file to define your schema changes.

SQL;

        $downTemplate = <<<SQL
-- DBDiff migration: {$directionLabel}
-- Version    : {$version}
-- Direction  : DOWN (UNDO)
-- Description: Edit this file to reverse the changes in the UP file.

SQL;

        file_put_contents($upPath,   $upTemplate);
        file_put_contents($downPath, $downTemplate);

        return new self($version, $slug, $upPath, $downPath);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Parse '{version}_{description}' into [$version, $description].
     * Returns null if the name does not conform to the expected pattern.
     */
    private static function parseName(string $baseName): ?array
    {
        // version = 14 consecutive digits; description = remainder after first underscore
        if (!preg_match('/^(\d{14})_(.+)$/', $baseName, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    private static function slugify(string $text): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $text), '_'));
    }
}
