<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Config\MigrationConfig;
use DBDiff\Migration\Runner\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dbdiff migration:up [--target=<version>]
 *
 * Applies all pending migrations, in version order (oldest first).
 * Stops early if a migration fails; the failed migration is recorded in the
 * history table with success=false and can be retried after `migration:repair`.
 */
#[AsCommand(name: 'migration:up', description: 'Apply pending migrations')]
class MigrationUpCommand extends Command
{
    use ConfigOptionTrait;

    protected function configure(): void
    {
        $this
            ->addOption('target',         null, InputOption::VALUE_REQUIRED, 'Stop after applying this version (inclusive)')
            ->addOption('migrations-dir', null, InputOption::VALUE_REQUIRED, 'Override the migrations directory')
            ->addOption('config',         null, InputOption::VALUE_REQUIRED, 'Path to dbdiff.yml');
        $this->addDbUrlOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->loadConfig($input);
        $runner = new MigrationRunner($config);
        $target = $input->getOption('target');

        $results = $runner->up($target);

        if (empty($results)) {
            $output->writeln('<info>Nothing to migrate — all migrations are up to date.</info>');
            return Command::SUCCESS;
        }

        $exitCode = Command::SUCCESS;

        foreach ($results as $r) {
            $this->printResult($output, $r);
            if ($r['status'] === 'failed') {
                $exitCode = Command::FAILURE;
            }
        }

        if ($exitCode === Command::FAILURE) {
            $output->writeln('');
            $output->writeln('<error>Migration halted due to an error. Fix the issue and run `dbdiff migration:repair` then retry.</error>');
        }

        return $exitCode;
    }

    private function printResult(OutputInterface $output, array $r): void
    {
        $ms   = $r['ms'] ?? 0;
        $tag  = "{$r['version']}_{$r['description']}";

        match ($r['status']) {
            'applied' => $output->writeln("<info>✔ Applied</info>  {$tag}  ({$ms}ms)"),
            'failed'  => $output->writeln("<error>✘ Failed</error>   {$tag}  — {$r['error']}"),
            default   => $output->writeln("<comment>→ {$r['status']}</comment> {$tag}"),
        };
    }
}
