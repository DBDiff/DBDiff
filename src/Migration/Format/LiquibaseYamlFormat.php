<?php namespace DBDiff\Migration\Format;

use Symfony\Component\Yaml\Yaml;

/**
 * Liquibase YAML format.
 *
 * Produces a single `changelog.yaml` file that mirrors the XML format but in
 * Liquibase's YAML changelog syntax.  Uses symfony/yaml (already a project
 * dependency) for safe serialisation.
 *
 * render() returns a plain string — the complete YAML document.
 */
class LiquibaseYamlFormat implements FormatInterface
{
    public function render(string $up, string $down, string $description = '', string $version = ''): string
    {
        $version     = $version    ?: date('YmdHis');
        $description = $description ?: 'migration';

        $changelog = [
            'databaseChangeLog' => [
                [
                    'changeSet' => [
                        'id'      => $version,
                        'author'  => 'dbdiff',
                        'comment' => $description,
                        'changes' => [
                            [
                                'sql' => [
                                    'sql'              => trim($up) ?: '-- (empty)',
                                    'splitStatements'  => true,
                                    'stripComments'    => false,
                                ],
                            ],
                        ],
                        'rollback' => [
                            [
                                'sql' => [
                                    'sql' => trim($down) ?: '-- (empty)',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $header  = "# DBDiff-generated Liquibase changelog\n";
        $header .= "# Version     : {$version}\n";
        $header .= "# Description : {$description}\n";
        $header .= "# Generated   : " . date('Y-m-d H:i:s') . "\n\n";

        return $header . Yaml::dump($changelog, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    public function getExtension(): string
    {
        return 'yaml';
    }

    public function getLabel(): string
    {
        return 'Liquibase YAML (changelog.yaml)';
    }
}
