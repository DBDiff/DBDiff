<?php namespace DBDiff\Migration\Format;

/**
 * Native DBDiff format — the default.
 *
 * Produces a single SQL file with an UP block and a DOWN block separated by
 * clearly labelled comment headers.  Mirrors the output of the legacy Templater
 * so that existing consumers see no change when --format is omitted.
 */
class NativeFormat implements FormatInterface
{
    public function render(string $up, string $down, string $description = '', string $version = ''): string
    {
        $header  = "-- DBDiff migration";
        $header .= $description ? ": {$description}" : '';
        $header .= "\n-- Version: " . ($version ?: date('YmdHis'));
        $header .= "\n-- Generated: " . date('Y-m-d H:i:s');
        // Which renderer produced the DDL. It is chosen from what is installed,
        // so two machines running the same DBDiff can differ; saying so here
        // makes that explainable without a re-run.
        if (\DBDiff\DB\Support\PgDumpRenderer::wasUsed()) {
            $header .= "\n-- Renderer: pg_dump";
        }
        $header .= "\n";

        $content  = $header . "\n";
        $content .= "-- ==================== UP ====================\n\n";
        $content .= $up ? (rtrim($up) . "\n") : "-- (empty)\n";
        $content .= "\n-- ==================== DOWN ====================\n\n";
        $content .= $down ? (rtrim($down) . "\n") : "-- (empty)\n";

        return $content;
    }

    public function getExtension(): string
    {
        return 'sql';
    }

    public function getLabel(): string
    {
        return 'Native DBDiff (default)';
    }
}
