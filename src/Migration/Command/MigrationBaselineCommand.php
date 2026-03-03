<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Runner\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * dbdiff migration:baseline [--version=<ver>]
 *
 * Records a baseline entry in the history table that tells the runner "all
 * migrations at or before this version are already applied".  This is the
 * standard onboarding step for databases that existed before DBDiff was
 * introduced.
 *
 * --version defaults to the current timestamp, which effectively marks the
 * entire existing migration set as applied without actually running it.
 *
 * Equivalent to Flyway's `baseline` command.
 */
#[AsCommand(name: 'migration:baseline', description: 'Mark the current DB state as the migration baseline')]
class MigrationBaselineCommand extends Command
{
    use ConfigOptionTrait;

    protected function configure(): void
    {
        $this
            ->addOption('version',         null, InputOption::VALUE_REQUIRED, '14-digit version to baseline at (default: current timestamp)')
            ->addOption('description',     null, InputOption::VALUE_REQUIRED, 'Description for the baseline entry', 'baseline')
            ->addOption('force',           null, InputOption::VALUE_NONE,     'Skip the confirmation prompt')
            ->addOption('migrations-dir',  null, InputOption::VALUE_REQUIRED, 'Override the migrations directory')
            ->addOption('config',          null, InputOption::VALUE_REQUIRED, 'Path to dbdiff.yml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config      = $this->loadConfig($input);
        $runner      = new MigrationRunner($config);
        $version     = $input->getOption('version') ?? date('YmdHis');
        $description = $input->getOption('description') ?? 'baseline';

        // Validate version format
        if (!preg_match('/^\d{14}$/', $version)) {
            $output->writeln('<error>Version must be a 14-digit timestamp (YYYYMMDDHHmmss).</error>');
            return Command::FAILURE;
        }

        if (!$input->getOption('force')) {
            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                "<question>Mark version <comment>{$version}</comment> as baseline in the history table? [y/N]</question> ",
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $runner->baseline($version, $description);

        $output->writeln("<info>✔ Baseline set at version {$version} ({$description}).</info>");
        $output->writeln('<info>Migrations at or before this version will be considered already applied.</info>');

        return Command::SUCCESS;
    }
}
