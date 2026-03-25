<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Runner\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dbdiff migration:status
 *
 * Shows the state of every migration file found in the migrations directory,
 * cross-referenced against the history table:
 *
 *   applied          — migration was applied and its on-disk checksum is unchanged
 *   pending          — migration has not been applied yet
 *   checksum_mismatch — migration was applied but the file has since been modified
 *   failed           — last run of this migration recorded an error
 *   missing_file     — migration is in the history table but the file is gone
 */
#[AsCommand(name: 'migration:status', description: 'Show applied vs pending migration status')]
class MigrationStatusCommand extends Command
{
    use ConfigOptionTrait;

    protected function configure(): void
    {
        $this
            ->addOption('migrations-dir', null, InputOption::VALUE_REQUIRED, 'Override the migrations directory')
            ->addOption('config',         null, InputOption::VALUE_REQUIRED, 'Path to dbdiff.yml');
        $this->addDbUrlOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->loadConfig($input);
        $runner = new MigrationRunner($config);
        $report = $runner->status();

        // Phase 4: fetch Supabase-tracked versions when inside a Supabase project
        $supabaseVersions = [];
        $isSupabase       = $config->isSupabaseProject;

        if ($isSupabase) {
            $supabaseVersions = array_flip($runner->getSupabaseAppliedVersions());
            $output->writeln(
                '<comment>Supabase project detected:</comment> ' . $config->supabaseProjectRoot
                . ' — showing Supabase tracking column (Supa?)'
            );
            $output->writeln('');
        }

        if (empty($report)) {
            $output->writeln('<info>No migrations found in ' . $config->resolveMigrationsDir() . '</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);

        $headers = ['#', 'Version', 'Description', 'State', 'Applied On', 'Down?', 'Time (ms)'];
        if ($isSupabase) {
            $headers[] = 'Supa?';
        }
        $table->setHeaders($headers);

        $i = 1;
        foreach ($report as $row) {
            $state = match ($row['state']) {
                'applied'           => '<info>✔ applied</info>',
                'pending'           => '<comment>⏳ pending</comment>',
                'checksum_mismatch' => '<error>⚠ checksum mismatch</error>',
                'failed'            => '<error>✘ failed</error>',
                'missing_file'      => '<error>⚠ file missing</error>',
                default             => $row['state'],
            };

            $cells = [
                $i++,
                $row['version'],
                $row['description'],
                $state,
                $row['applied_on'] ?? '—',
                $row['has_down'] ? 'yes' : '<comment>no</comment>',
                $row['execution_ms'] ?? '—',
            ];

            if ($isSupabase) {
                $baseName      = $row['version'] . '_' . $row['description'];
                $supaTracked   = isset($supabaseVersions[$baseName]);
                $cells[]       = $supaTracked ? '<info>✔</info>' : '<comment>—</comment>';
            }

            $table->addRow($cells);
        }

        $table->render();

        // Summary
        $total   = count($report);
        $applied = count(array_filter($report, fn($r) => $r['state'] === 'applied'));
        $pending = count(array_filter($report, fn($r) => $r['state'] === 'pending'));
        $issues  = $total - $applied - $pending;

        $output->writeln('');
        $output->writeln("<info>{$applied} applied</info>, <comment>{$pending} pending</comment>" . ($issues > 0 ? ", <error>{$issues} with issues</error>" : ''));

        return Command::SUCCESS;
    }
}
