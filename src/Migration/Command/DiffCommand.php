<?php namespace DBDiff\Migration\Command;

use DBDiff\DBDiff;
use DBDiff\Migration\Config\MigrationConfig;
use DBDiff\Migration\Format\FormatRegistry;
use DBDiff\Params\DefaultParams;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dbdiff diff <input> [options]
 *
 * Computes the schema/data diff between two databases and outputs the result
 * in the requested format (native, flyway, liquibase-xml, liquibase-yaml, laravel).
 *
 * This command is a full replacement for the legacy aura/cli entry point.
 * All original options are preserved so existing scripts continue to work
 * unchanged when called as `dbdiff diff ...`.
 */
#[AsCommand(name: 'diff', description: 'Compute a schema/data diff between two databases')]
class DiffCommand extends Command
{
    protected function configure(): void
    {
        $formatList = implode(', ', FormatRegistry::keys());

        $this
            ->addArgument(
                'input',
                InputArgument::REQUIRED,
                'Resources to compare. Examples: server1.db1:server2.db2  or  server1.db1.table1:server2.db2.table2'
            )
            ->addOption('server1',    null, InputOption::VALUE_REQUIRED, 'Source server credentials: user:pass@host:port')
            ->addOption('server2',    null, InputOption::VALUE_REQUIRED, 'Target server credentials: user:pass@host:port')
            ->addOption('driver',     null, InputOption::VALUE_REQUIRED, 'Database driver: mysql (default), pgsql, sqlite', 'mysql')
            ->addOption('supabase',   null, InputOption::VALUE_NONE,     'Shorthand for --driver=pgsql with SSL enabled')
            ->addOption('format',     null, InputOption::VALUE_REQUIRED, "Output format [{$formatList}]", 'native')
            ->addOption('description',null, InputOption::VALUE_REQUIRED, 'Human-readable description used in generated file names (e.g. "add_users_table")', '')
            ->addOption('template',   null, InputOption::VALUE_REQUIRED, 'Blade template file for native/custom output')
            ->addOption('type',       null, InputOption::VALUE_REQUIRED, 'Diff type: schema (default), data, all', 'schema')
            ->addOption('include',    null, InputOption::VALUE_REQUIRED, 'Include: up (default), down, both', 'up')
            ->addOption('nocomments', null, InputOption::VALUE_NONE,     'Suppress auto-generated comment headers')
            ->addOption('config',     null, InputOption::VALUE_REQUIRED, 'Path to a .dbdiff config file (YAML)')
            ->addOption('output',     null, InputOption::VALUE_REQUIRED, 'Output file path (default: migration.<ext> in cwd)')
            ->addOption('debug',      null, InputOption::VALUE_NONE,     'Enable verbose error output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format      = strtolower($input->getOption('format'));
        $description = $input->getOption('description') ?: '';
        $version     = date('YmdHis');

        // ── Build params object ───────────────────────────────────────────────
        $params             = new DefaultParams;
        $params->input      = $this->parseInput($input->getArgument('input'));
        $params->driver     = $input->getOption('supabase') ? 'pgsql' : $input->getOption('driver');
        $params->format     = $format;
        $params->type       = $input->getOption('type');
        $params->include    = $this->normaliseInclude($input->getOption('include'));
        $params->nocomments = (bool) $input->getOption('nocomments');
        $params->debug      = (bool) $input->getOption('debug');
        $params->template   = $input->getOption('template') ?? '';
        $params->config     = $input->getOption('config');

        if ($input->getOption('supabase')) {
            $params->sslmode = 'require';
        }

        if ($s1 = $input->getOption('server1')) {
            $params->server1 = $this->parseServer($s1);
        }
        if ($s2 = $input->getOption('server2')) {
            $params->server2 = $this->parseServer($s2);
        }

        // Allow a dbdiff.yml to fill in missing server details
        if ($params->config || file_exists(getcwd() . '/.dbdiff')) {
            $this->mergeFileConfig($params);
        }

        // ── Run diff ──────────────────────────────────────────────────────────
        try {
            $dbdiff = new DBDiff;
            $result = $dbdiff->getDiffResult($params);
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        if ($result['empty']) {
            $output->writeln('<info>Databases are identical — no migration needed.</info>');
            return Command::SUCCESS;
        }

        // ── Apply format ──────────────────────────────────────────────────────
        try {
            $formatter = FormatRegistry::get($format);
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $rendered = $formatter->render($result['up'], $result['down'], $description, $version);

        // ── Write output ──────────────────────────────────────────────────────
        $outputOpt = $input->getOption('output');

        if (is_array($rendered)) {
            // Multi-file format (Flyway, Laravel)
            $dir = $outputOpt ? rtrim($outputOpt, '/') : getcwd();
            foreach ($rendered as $fileName => $content) {
                $path = "{$dir}/{$fileName}";
                file_put_contents($path, $content);
                $output->writeln("<info>Written:</info> {$path}");
            }
        } else {
            // Single-file format
            $ext  = $formatter->getExtension();
            $slug = $description ? preg_replace('/[^a-z0-9_]/i', '_', $description) : 'migration';
            $path = $outputOpt ?: (getcwd() . "/{$slug}.{$ext}");
            file_put_contents($path, $rendered);
            $output->writeln("<info>Written:</info> {$path}");
        }

        return Command::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function parseInput(string $input): array
    {
        $parts  = explode(':', $input);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Input must be in the form source:target");
        }
        $first  = explode('.', $parts[0]);
        $second = explode('.', $parts[1]);

        if (count($first) !== count($second)) {
            throw new \InvalidArgumentException("Source and target must be of the same kind");
        }

        if (count($first) === 2) {
            return [
                'kind'   => 'db',
                'source' => ['server' => $first[0],  'db' => $first[1]],
                'target' => ['server' => $second[0], 'db' => $second[1]],
            ];
        }

        if (count($first) === 3) {
            return [
                'kind'   => 'table',
                'source' => ['server' => $first[0],  'db' => $first[1],  'table' => $first[2]],
                'target' => ['server' => $second[0], 'db' => $second[1], 'table' => $second[2]],
            ];
        }

        throw new \InvalidArgumentException("Unknown input format");
    }

    private function parseServer(string $server): array
    {
        $parts = explode('@', $server);
        $creds = explode(':', $parts[0]);
        $dns   = explode(':', $parts[1]);

        return [
            'user'     => $creds[0],
            'password' => $creds[1],
            'host'     => $dns[0],
            'port'     => $dns[1],
        ];
    }

    /**
     * Normalise --include values: accept 'both'/'all' as aliases for the
     * legacy 'up'/'down' / empty combination.
     */
    private function normaliseInclude(string $include): string
    {
        return match (strtolower($include)) {
            'both', 'all' => 'both',
            'down'        => 'down',
            default       => 'up',
        };
    }

    /**
     * Merge a .dbdiff YAML config into $params (fills in only unset values).
     */
    private function mergeFileConfig(object $params): void
    {
        $configFile = $params->config ?? (getcwd() . '/.dbdiff');

        if (!file_exists($configFile)) {
            return;
        }

        $yaml = \Symfony\Component\Yaml\Yaml::parseFile($configFile) ?? [];

        foreach ($yaml as $key => $value) {
            if (str_contains($key, '-')) {
                [$section, $field] = explode('-', $key, 2);
                $arr = (array) ($params->$section ?? []);
                $arr[$field] = $value;
                $params->$section = $arr;
            } elseif (empty($params->$key)) {
                // Only fill in values that weren't supplied via CLI.
                // Use empty() rather than !isset() so that unset default arrays
                // like server1 = [] are still overridden by the file.
                $params->$key = $value;
            }
        }
    }
}
