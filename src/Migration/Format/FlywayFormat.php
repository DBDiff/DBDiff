<?php namespace DBDiff\Migration\Format;

/**
 * Flyway format.
 *
 * Produces a single, UP-only SQL file whose name follows the Flyway convention:
 *   V{version}__{description}.sql
 *
 * Flyway does not support UNDO migrations in its community edition; the DOWN SQL
 * is intentionally omitted.  If you have Flyway Teams/Enterprise you can place
 * the down SQL in a matching U{version}__{description}.sql file — pass
 * $options['include_undo'] = true to opt into this behaviour.
 *
 * render() returns an array so the caller can write one or two files:
 *   [ 'V20260303120000__create_users.sql' => '...', ... ]
 */
class FlywayFormat implements FormatInterface
{
    public function render(string $up, string $down, string $description = '', string $version = ''): array
    {
        $version     = $version    ?: date('YmdHis');
        $description = $description ? self::slugify($description) : 'migration';

        $upFile   = "V{$version}__{$description}.sql";
        $upContent  = "-- Flyway migration\n"
                    . "-- Version: {$version}\n"
                    . "-- Description: {$description}\n\n"
                    . rtrim($up) . "\n";

        $files = [$upFile => $upContent];

        // Optional UNDO file (Flyway Teams+)
        if ($down) {
            $undoFile = "U{$version}__{$description}.sql";
            $undoContent = "-- Flyway UNDO migration\n"
                         . "-- Version: {$version}\n"
                         . "-- Description: {$description}\n\n"
                         . rtrim($down) . "\n";
            $files[$undoFile] = $undoContent;
        }

        return $files;
    }

    public function getExtension(): string
    {
        return 'sql';
    }

    public function getLabel(): string
    {
        return 'Flyway (V{ts}__{desc}.sql)';
    }

    private static function slugify(string $text): string
    {
        // Convert spaces and non-word chars to underscores, strip leading/trailing
        return trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $text), '_');
    }
}
