<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Config\MigrationConfig;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Shared helpers for all migration:* commands.
 *
 * Expects the consuming command to declare:
 *   --config          optional path to dbdiff.yml
 *   --migrations-dir  optional override for the migrations directory
 *   --driver          optional driver override
 */
trait ConfigOptionTrait
{
    /**
     * Build a MigrationConfig from CLI options and the auto-detected YAML file.
     * CLI options WIN over the file, which wins over built-in defaults.
     */
    protected function loadConfig(InputInterface $input): MigrationConfig
    {
        $configFile = $input->hasOption('config') ? $input->getOption('config') : null;

        $overrides = [];

        foreach (['driver', 'host', 'port', 'name', 'path', 'user', 'password', 'migrations-dir'] as $opt) {
            if ($input->hasOption($opt) && $input->getOption($opt) !== null) {
                // Normalise 'migrations-dir' → 'migrations_dir'
                $key             = str_replace('-', '_', $opt);
                $overrides[$key] = $input->getOption($opt);
            }
        }

        return new MigrationConfig($configFile, $overrides);
    }
}
