<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Config\MigrationConfig;
use DBDiff\Migration\Runner\MigrationFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dbdiff migration:new <name>
 *
 * Scaffolds a new migration file pair in the configured migrations directory:
 *   {timestamp}_{name}.up.sql
 *   {timestamp}_{name}.down.sql
 *
 * Both files are created with a helpful comment header and an empty body.
 * Edit them before running `dbdiff migration:up`.
 */
#[AsCommand(name: 'migration:new', description: 'Scaffold a new migration file pair')]
class MigrationNewCommand extends Command
{
    use ConfigOptionTrait;

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Short description for the migration (e.g. "create_users_table")')
            ->addOption('migrations-dir', null, InputOption::VALUE_REQUIRED, 'Override the migrations directory')
            ->addOption('config',         null, InputOption::VALUE_REQUIRED, 'Path to dbdiff.yml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->loadConfig($input);
        $dir    = $input->getOption('migrations-dir') ?? $config->resolveMigrationsDir();
        $name   = $input->getArgument('name');

        try {
            $file = MigrationFile::scaffold($dir, $name);
        } catch (\RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Created UP  :</info> {$file->upPath}");
        $output->writeln("<info>Created DOWN:</info> {$file->downPath}");
        $output->writeln('');
        $output->writeln('Edit the files above, then run <comment>dbdiff migration:up</comment> to apply.');

        return Command::SUCCESS;
    }
}
