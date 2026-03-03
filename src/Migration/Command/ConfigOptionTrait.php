<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Config\MigrationConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared helpers for all migration:* commands.
 *
 * Expects the consuming command to declare (via configure()):
 *   --config          optional path to dbdiff.yml
 *   --db-url          optional full DSN URL (overrides all other db options)
 *   --migrations-dir  optional override for the migrations directory
 *   --driver          optional driver override
 *
 * Call addDbUrlOption() from your configure() to register --db-url.
 */
trait ConfigOptionTrait
{
    /**
     * Register the --db-url option on the command.
     * Call from configure() *before* returning.
     */
    protected function addDbUrlOption(): void
    {
        $this->addOption(
            'db-url',
            null,
            InputOption::VALUE_REQUIRED,
            'Full database connection URL (e.g. postgres://user:pass@host:5432/db). '
            . 'Overrides --driver/--host/--port/--name/--user/--password. '
            . 'Supabase URLs are auto-detected (SSL enabled, pooler support).'
        );
    }

    /**
     * Build a MigrationConfig from CLI options and the auto-detected YAML file.
     * CLI options WIN over the file, which wins over built-in defaults.
     * A --db-url value wins over all individual connection options.
     */
    protected function loadConfig(InputInterface $input): MigrationConfig
    {
        $configFile = $input->hasOption('config') ? $input->getOption('config') : null;

        $overrides = [];

        // --db-url takes highest precedence — parse it first
        if ($input->hasOption('db-url') && $input->getOption('db-url') !== null) {
            $overrides['db_url'] = $input->getOption('db-url');
        }

        // Individual connection overrides (only applied if no --db-url)
        if (empty($overrides['db_url'])) {
            foreach (['driver', 'host', 'port', 'name', 'path', 'user', 'password'] as $opt) {
                if ($input->hasOption($opt) && $input->getOption($opt) !== null) {
                    $overrides[$opt] = $input->getOption($opt);
                }
            }
        }

        // Migrations-dir can always be overridden
        if ($input->hasOption('migrations-dir') && $input->getOption('migrations-dir') !== null) {
            $overrides['migrations_dir'] = $input->getOption('migrations-dir');
        }

        return new MigrationConfig($configFile, $overrides);
    }
}
