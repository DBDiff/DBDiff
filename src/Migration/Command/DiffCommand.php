<?php namespace DBDiff\Migration\Command;

use DBDiff\DBDiff;
use DBDiff\Exceptions\FSException;
use DBDiff\Migration\Config\DsnParser;
use DBDiff\Migration\Format\FormatRegistry;
use DBDiff\Params\DefaultParams;
use DBDiff\Params\ParamsFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dbdiff diff [<input>] [options]
 *
 * Computes the schema/data diff between two databases and outputs the result
 * in the requested format (native, flyway, liquibase-xml, liquibase-yaml, laravel).
 *
 * Two connection styles are supported:
 *
 *   (A) Legacy style (individual options):
 *         dbdiff diff server1.mydb:server2.mydb \
 *           --server1 user:pass@host1:3306 --server2 user:pass@host2:3306
 *
 *   (B) URL style — simpler, ideal for Supabase and CI environments:
 *         dbdiff diff \
 *           --server1-url postgres://user:pass@db.abc.supabase.co:5432/postgres \
 *           --server2-url postgres://user:pass@db.xyz.supabase.co:5432/staging
 *         (the `input` positional argument is not needed when URLs are supplied)
 *
 * This command is the default command so the legacy invocation
 *   dbdiff server1.db1:server2.db2 [options]
 * continues to work unchanged.
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
                InputArgument::OPTIONAL,
                'Resources to compare: server1.db1:server2.db2 or server1.db1.tbl:server2.db2.tbl. '
                . 'Optional when --server1-url / --server2-url are provided.'
            )
            // ── Legacy connection options ─────────────────────────────────────
            ->addOption('server1',     null, InputOption::VALUE_REQUIRED, 'Source server: user:pass@host:port')
            ->addOption('server2',     null, InputOption::VALUE_REQUIRED, 'Target server: user:pass@host:port')
            ->addOption('driver',      null, InputOption::VALUE_REQUIRED, 'Database driver: mysql (default), pgsql, sqlite', 'mysql')
            ->addOption('supabase',    null, InputOption::VALUE_NONE,     'Alias for --driver=pgsql --sslmode=require (Supabase shorthand)')
            // ── URL-style connection options (Supabase-friendly) ─────────────
            ->addOption('server1-url', null, InputOption::VALUE_REQUIRED,
                'Full DSN URL for the source DB. e.g. postgres://user:pass@db.abc.supabase.co:5432/postgres. '
                . 'When provided, --server1 / --driver / --supabase are not needed.')
            ->addOption('server2-url', null, InputOption::VALUE_REQUIRED,
                'Full DSN URL for the target DB. Same format as --server1-url.')
            // ── Output options ────────────────────────────────────────────────
            ->addOption('format',      null, InputOption::VALUE_REQUIRED, "Output format [{$formatList}]", 'native')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Human-readable description for generated file names', '')
            ->addOption('template',    null, InputOption::VALUE_REQUIRED, 'Blade template file for native/custom output')
            ->addOption('type',        null, InputOption::VALUE_REQUIRED, 'Diff type: schema (default), data, all', 'schema')
            ->addOption('include',     null, InputOption::VALUE_REQUIRED, 'Include: up (default), down, both', 'up')
            ->addOption('nocomments',  null, InputOption::VALUE_NONE,     'Suppress auto-generated comment headers')
            ->addOption('config',      null, InputOption::VALUE_REQUIRED, 'Path to a .dbdiff config file (YAML)')
            ->addOption('output',      null, InputOption::VALUE_REQUIRED, 'Output file path (default: migration.<ext> in cwd)')
            ->addOption('debug',       null, InputOption::VALUE_NONE,     'Enable verbose error output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $params = $this->buildParams($input);
            $this->resolveConnections($input, $params);

            if ($params->config || file_exists(getcwd() . '/.dbdiff')) {
                $this->mergeFileConfig($params);
            }

            // Pre-populate so internal code (DBSchema, TableSchema, etc.)
            // that calls ParamsFactory::get() receives the same params.
            ParamsFactory::set($params);

            $written = $this->runAndWrite($params, $input->getOption('output'), $output);

            if (!$written) {
                $output->writeln('<info>Databases are identical — no migration needed.</info>');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build a DefaultParams object from the raw console input options.
     * No validation occurs here — that happens in resolveConnections().
     */
    private function buildParams(InputInterface $input): DefaultParams
    {
        $params              = new DefaultParams;
        $params->format      = strtolower($input->getOption('format'));
        $params->type        = $input->getOption('type');
        $params->include     = $this->normaliseInclude($input->getOption('include'));
        $params->nocomments  = (bool) $input->getOption('nocomments');
        $params->debug       = (bool) $input->getOption('debug');
        $params->template    = $input->getOption('template') ?? '';
        $params->config      = $input->getOption('config');
        $params->description = $input->getOption('description') ?: '';

        return $params;
    }

    /**
     * Populate $params with connection details from either URL-style or legacy
     * CLI options.  Throws \InvalidArgumentException on invalid/missing input.
     */
    private function resolveConnections(InputInterface $input, DefaultParams $params): void
    {
        $s1url = $input->getOption('server1-url');
        $s2url = $input->getOption('server2-url');

        if ($s1url || $s2url) {
            $autoInput     = $this->applyServerUrls($params, $s1url, $s2url);
            $rawInput      = $input->getArgument('input');
            $params->input = $rawInput ? $this->parseInput($rawInput) : $autoInput;

            return;
        }

        // Legacy style
        $rawInput = $input->getArgument('input');

        if (!$rawInput) {
            throw new \InvalidArgumentException(
                'Missing input argument. Provide server1.db:server2.db or use --server1-url / --server2-url.'
            );
        }

        $params->input  = $this->parseInput($rawInput);
        $params->driver = $input->getOption('supabase') ? 'pgsql' : $input->getOption('driver');

        if ($input->getOption('supabase')) {
            $params->sslmode = 'require';
        }
        if ($s1 = $input->getOption('server1')) {
            $params->server1 = $this->parseServer($s1);
        }
        if ($s2 = $input->getOption('server2')) {
            $params->server2 = $this->parseServer($s2);
        }
    }

    /**
     * Run the diff, format the result, and persist output files.
     *
     * @return bool  true when output was written; false when databases are identical.
     */
    private function runAndWrite(DefaultParams $params, ?string $outputOpt, OutputInterface $output): bool
    {
        $dbdiff = new DBDiff;
        $result = $dbdiff->getDiffResult($params);

        if ($result['empty']) {
            return false;
        }

        $formatter = FormatRegistry::get($params->format);
        $rendered  = $formatter->render(
            $result['up'],
            $result['down'],
            $params->description ?? '',
            date('YmdHis')
        );

        $this->writeRendered($rendered, $formatter, $params->description ?? '', $outputOpt, $output);

        return true;
    }

    /**
     * Persist $rendered to disk — either a map of fileName → content
     * (multi-file formats like Flyway) or a single string.
     */
    private function writeRendered(
        array|string $rendered,
        object       $formatter,
        string       $description,
        ?string      $outputOpt,
        OutputInterface $output
    ): void {
        if (is_array($rendered)) {
            $dir = $outputOpt ? rtrim($outputOpt, '/') : getcwd();
            foreach ($rendered as $fileName => $content) {
                $path = "{$dir}/{$fileName}";
                if (file_put_contents($path, $content) === false) {
                    throw new FSException("Failed to write output file: {$path}");
                }
                $output->writeln("<info>Written:</info> {$path}");
            }
            return;
        }

        $ext  = $formatter->getExtension();
        $slug = $description ? preg_replace('/[^a-z0-9_]/i', '_', $description) : 'migration';
        $path = $outputOpt ?: (getcwd() . "/{$slug}.{$ext}");
        if (file_put_contents($path, $rendered) === false) {
            throw new FSException("Failed to write output file: {$path}");
        }
        $output->writeln("<info>Written:</info> {$path}");
    }

    /**
     * Parse --server1-url / --server2-url DSN values into $params.
     * Mutates $params with driver, server1, server2, and optionally sslmode.
     *
     * @return array  Auto-built `input` structure derived from the DB names in the URLs.
     * @throws \InvalidArgumentException when either URL is missing.
     */
    private function applyServerUrls(DefaultParams $params, ?string $s1url, ?string $s2url): array
    {
        if (!$s1url) {
            throw new \InvalidArgumentException('--server1-url is required when using URL-style connections.');
        }
        if (!$s2url) {
            throw new \InvalidArgumentException('--server2-url is required when using URL-style connections.');
        }

        $p1 = DsnParser::toServerAndDb(DsnParser::parse($s1url));
        $p2 = DsnParser::toServerAndDb(DsnParser::parse($s2url));

        $params->driver  = $p1['driver'];
        $params->server1 = $p1['server'];
        $params->server2 = $p2['server'];

        if (!empty($p1['sslmode'])) {
            $params->sslmode = $p1['sslmode'];
        }

        return [
            'kind'   => 'db',
            'source' => ['server' => 'server1', 'db' => $p1['db']],
            'target' => ['server' => 'server2', 'db' => $p2['db']],
        ];
    }

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

    /**
     * Parse a server string — accepts EITHER:
     *   Legacy format: user:pass@host:port
     *   URL   format:  postgres://user:pass@host:port  (or mysql://, etc.)
     */
    private function parseServer(string $server): array
    {
        // Detect URL by presence of a scheme
        if (str_contains($server, '://')) {
            return DsnParser::toServerAndDb(DsnParser::parse($server))['server'];
        }

        // Legacy user:pass@host:port
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

    private function normaliseInclude(string $include): string
    {
        return match (strtolower($include)) {
            'both', 'all' => 'both',
            'down'        => 'down',
            default       => 'up',
        };
    }

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
                $params->$key = $value;
            }
        }
    }
}
