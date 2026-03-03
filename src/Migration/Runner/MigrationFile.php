<?php namespace DBDiff\Migration\Runner;

/**
 * Represents a single migration unit on disk — a matching pair of:
 *   {version}_{description}.up.sql    (required)
 *   {version}_{description}.down.sql  (optional, needed for rollback)
 *
 * Naming convention expected:
 *   20260303120000_create_users.up.sql
 *   20260303120000_create_users.down.sql
 *
 * The 14-digit timestamp prefix is the version; everything after the first
 * underscore (before .up.sql / .down.sql) is the human-readable description.
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
            throw new \RuntimeException("UP file not found: {$this->upPath}");
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
     * @return MigrationFile[]
     */
    public static function scanDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob("{$dir}/*" . self::UP_SUFFIX) ?: [];
        sort($files);

        $migrations = [];
        foreach ($files as $upPath) {
            $baseName = basename($upPath, self::UP_SUFFIX);
            $parsed   = self::parseName($baseName);

            if ($parsed === null) {
                continue; // skip files that don't match the naming convention
            }

            [$version, $description] = $parsed;
            $downPath = $dir . '/' . $baseName . self::DOWN_SUFFIX;

            $migrations[] = new self($version, $description, $upPath, $downPath);
        }

        return $migrations;
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

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \RuntimeException("Cannot create migrations directory: {$dir}");
            }
        }

        foreach ([$upPath, $downPath] as $path) {
            if (file_exists($path)) {
                throw new \RuntimeException("Migration file already exists: {$path}");
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
