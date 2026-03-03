<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Runner\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * dbdiff migration:repair
 *
 * Removes all failed (success = false) entries from the history table so that
 * the corresponding migrations can be re-run on the next `migration:up`.
 *
 * This is the recovery path after a migration fails mid-way through, leaving
 * partial state or a "stuck" failed record that blocks future migrations.
 *
 * After repairing: fix the SQL issue in the migration file, then run
 * `migration:up` again.
 */
#[AsCommand(name: 'migration:repair', description: 'Remove failed migration entries so they can be retried')]
class MigrationRepairCommand extends Command
{
    use ConfigOptionTrait;

    protected function configure(): void
    {
        $this
            ->addOption('force',          null, InputOption::VALUE_NONE,     'Skip the confirmation prompt')
            ->addOption('migrations-dir', null, InputOption::VALUE_REQUIRED, 'Override the migrations directory')
            ->addOption('config',         null, InputOption::VALUE_REQUIRED, 'Path to dbdiff.yml');
        $this->addDbUrlOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->loadConfig($input);
        $runner = new MigrationRunner($config);

        if (!$input->getOption('force')) {
            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<question>This will remove all failed migration records from the history table. Continue? [y/N]</question> ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $removed = $runner->repair();

        if ($removed === 0) {
            $output->writeln('<info>No failed migration records found — nothing to repair.</info>');
        } else {
            $output->writeln("<info>✔ Removed {$removed} failed migration record(s) from the history table.</info>");
            $output->writeln('<info>Run `dbdiff migration:up` to retry.</info>');
        }

        return Command::SUCCESS;
    }
}
