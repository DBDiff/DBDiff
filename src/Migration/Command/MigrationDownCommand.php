<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Runner\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dbdiff migration:down [--last=1] [--target=<version>]
 *
 * Rolls back the last N applied migrations (default: 1) using their .down.sql files.
 * Stops early if a rollback fails or if the .down.sql file is missing.
 *
 * Use --target to roll back to (but not including) a specific version.
 */
#[AsCommand(name: 'migration:down', description: 'Roll back the last applied migration(s)')]
class MigrationDownCommand extends Command
{
    use ConfigOptionTrait;

    protected function configure(): void
    {
        $this
            ->addOption('last',          null, InputOption::VALUE_REQUIRED, 'Number of migrations to roll back', '1')
            ->addOption('target',        null, InputOption::VALUE_REQUIRED, 'Roll back to (but not including) this version')
            ->addOption('migrations-dir',null, InputOption::VALUE_REQUIRED, 'Override the migrations directory')
            ->addOption('config',        null, InputOption::VALUE_REQUIRED, 'Path to dbdiff.yml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->loadConfig($input);
        $runner = new MigrationRunner($config);
        $last   = (int) ($input->getOption('last') ?? 1);
        $target = $input->getOption('target');

        $results  = $runner->down($last, $target);
        $exitCode = Command::SUCCESS;

        if (empty($results)) {
            $output->writeln('<info>Nothing to roll back — no applied migrations found.</info>');
            return Command::SUCCESS;
        }

        foreach ($results as $r) {
            $tag = "{$r['version']}_{$r['description']}";
            $ms  = $r['ms'] ?? 0;

            match ($r['status']) {
                'rolled_back' => $output->writeln("<info>↩ Rolled back</info> {$tag}  ({$ms}ms)"),
                'no_down'     => $output->writeln("<comment>⚠ No DOWN file</comment> {$tag}  — {$r['error']}"),
                'failed'      => $output->writeln("<error>✘ Failed</error>      {$tag}  — {$r['error']}"),
                default       => $output->writeln("<comment>{$r['status']}</comment> {$tag}"),
            };

            if (in_array($r['status'], ['no_down', 'failed'])) {
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
    }
}
