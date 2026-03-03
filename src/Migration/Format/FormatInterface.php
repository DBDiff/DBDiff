<?php namespace DBDiff\Migration\Format;

/**
 * A Format converts raw UP/DOWN SQL produced by DBDiff into the
 * on-disk representation expected by a particular migration tool.
 *
 * When a format produces a *single* file (native, liquibase-xml, liquibase-yaml)
 * render() returns a plain string.
 *
 * When a format naturally produces *multiple* files (flyway individual UP file,
 * Laravel class) render() returns an associative array:
 *   [ 'relative/path/file.ext' => 'file content', ... ]
 *
 * Callers should check the return type and write accordingly.
 */
interface FormatInterface
{
    /**
     * Render migration content from raw SQL.
     *
     * @param  string $up          Raw UP SQL (may be empty if direction is down-only)
     * @param  string $down        Raw DOWN SQL (may be empty if direction is up-only)
     * @param  string $description Human-readable slug used in file names / class names
     * @param  string $version     Timestamp version string (YYYYMMDDHHMMSS)
     * @return string|array<string,string>  A single string for single-file formats;
     *                                      an associative array of ['filename' => 'content']
     *                                      for formats that produce multiple files (Flyway, Laravel).
     */
    public function render(string $up, string $down, string $description = '', string $version = '');

    /**
     * Primary file extension for the rendered output (e.g. 'sql', 'xml', 'yaml', 'php').
     * Used when the caller writes a single file and no $version/$description-based name is needed.
     */
    public function getExtension(): string;

    /**
     * Human-readable label for the format (used in --help output and status messages).
     */
    public function getLabel(): string;
}
