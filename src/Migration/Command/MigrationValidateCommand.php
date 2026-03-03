<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Runner\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dbdiff migration:validate
 *
 * Verifies that the SHA-256 checksum of every applied migration's .up.sql file
 * matches what was recorded in the history table at application time.
 *
 * A mismatch means the file was modified after it ran — which is dangerous in
 * production because the schema state may no longer match what the file
 * describes.  Use `migration:repair` + manual correction to resolve this.
 */
#[AsCommand(name: 'migration:validate', description: 'Verify on-disk checksums match the history table')]
class MigrationValidateCommand extends Command
{
    use ConfigOptionTrait;

    protected function configure(): void
    {
        $this
            ->addOption('migrations-dir', null, InputOption::VALUE_REQUIRED, 'Override the migrations directory')
            ->addOption('config',         null, InputOption::VALUE_REQUIRED, 'Path to dbdiff.yml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config     = $this->loadConfig($input);
        $runner     = new MigrationRunner($config);
        $mismatches = $runner->validate();

        if (empty($mismatches)) {
            $output->writeln('<info>✔ All migration checksums are valid.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>Checksum validation FAILED — the following migrations have been modified after application:</error>');
        $output->writeln('');

        foreach ($mismatches as $m) {
            $output->writeln("  <comment>{$m['version']}_{$m['description']}</comment>");
            $output->writeln("    Issue    : {$m['issue']}");

            if ($m['expected']) {
                $output->writeln("    Expected : {$m['expected']}");
            }
            if ($m['actual']) {
                $output->writeln("    Actual   : {$m['actual']}");
            }
            $output->writeln('');
        }

        $output->writeln('<comment>Run `dbdiff migration:repair` to clear failed entries, or restore the original file contents.</comment>');

        return Command::FAILURE;
    }
}
