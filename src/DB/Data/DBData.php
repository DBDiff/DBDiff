<?php namespace DBDiff\DB\Data;

use DBDiff\Params\ParamsFactory;
use DBDiff\Exceptions\DataException;
use DBDiff\Logger;

class DBData
{
    /** @var mixed */
    protected $manager;

    /** @var TableData */
    protected $tableData;

    public function __construct($manager)
    {
        $this->manager   = $manager;
        $this->tableData = new TableData($this->manager);
    }

    /**
     * Main entry point – supports:
     *  - optional pre-scan to skip identical tables (same-server only)
     *  - optional parallel per-table processing with weighted chunking
     *
     * .dbdiff example:
     *
     * options:
     *   parallelTables: 4
     *   skipIdenticalTables: true
     */
    public function getDiff()
    {
        $params = ParamsFactory::get();

        $sourceTables = $this->manager->getTables('source');
        $targetTables = $this->manager->getTables('target');

        if (isset($params->tablesToIgnore)) {
            $sourceTables = array_diff($sourceTables, $params->tablesToIgnore);
            $targetTables = array_diff($targetTables, $params->tablesToIgnore);
        }

        $commonTables  = array_intersect($sourceTables, $targetTables);
        $addedTables   = array_diff($sourceTables, $targetTables);
        $deletedTables = array_diff($targetTables, $sourceTables);

        // read skipIdenticalTables from top-level OR options.skipIdenticalTables
        $skipIdentical = false;
        if (isset($params->skipIdenticalTables)) {
            $skipIdentical = (bool) $params->skipIdenticalTables;
        } elseif (isset($params->options->skipIdenticalTables)) {
            $skipIdentical = (bool) $params->options->skipIdenticalTables;
        }

        if ($skipIdentical && !empty($commonTables)) {
            $commonTables = $this->filterIdenticalTables($commonTables);
        }

        // read parallelTables from top-level OR options.parallelTables
        if (isset($params->parallelTables)) {
            $parallelTables = (int) $params->parallelTables;
        } elseif (isset($params->options->parallelTables)) {
            $parallelTables = (int) $params->options->parallelTables;
        } else {
            $parallelTables = 1;
        }

        $pcntlAvailable = function_exists('pcntl_fork');
        Logger::error('parallelTables=' . $parallelTables);
        Logger::error('pcntlAvailable=' . (int)$pcntlAvailable);

        if ($parallelTables > 1 && $pcntlAvailable) {
            Logger::info(
                "Running table data diff in parallel with {$parallelTables} workers (weighted by table size)"
            );

            return $this->getDiffParallel(
                $commonTables,
                $addedTables,
                $deletedTables,
                $parallelTables
            );
        }

        if ($parallelTables > 1 && !$pcntlAvailable) {
            Logger::info(
                "parallelTables={$parallelTables} requested, but pcntl_fork() not available; falling back to sequential mode"
            );
        }

        return $this->getDiffSequential(
            $commonTables,
            $addedTables,
            $deletedTables
        );
    }


    /**
     * Original sequential behaviour.
     */
    protected function getDiffSequential(array $commonTables, array $addedTables, array $deletedTables): array
    {
        $diffSequence = [];
        Logger::error('Sequential mode:');

        // Common tables – actual diffs
        foreach ($commonTables as $table) {
            try {
                $diffs = $this->tableData->getDiff($table);
                $diffSequence = array_merge($diffSequence, $diffs);
            } catch (DataException $e) {
                Logger::error($e->getMessage());
            }
        }

        // Tables only in source – new data
        foreach ($addedTables as $table) {
            try {
                $diffs = $this->tableData->getNewData($table);
                $diffSequence = array_merge($diffSequence, $diffs);
            } catch (DataException $e) {
                Logger::error($e->getMessage());
            }
        }

        // Tables only in target – old data
        foreach ($deletedTables as $table) {
            try {
                $diffs = $this->tableData->getOldData($table);
                $diffSequence = array_merge($diffSequence, $diffs);
            } catch (DataException $e) {
                Logger::error($e->getMessage());
            }
        }

        return $diffSequence;
    }

    /**
     * Parallel version using pcntl_fork() and weighted table chunking.
     */
    protected function getDiffParallel(
        array $commonTables,
        array $addedTables,
        array $deletedTables,
        int $numWorkers
    ): array {
        $jobs = [];

        // Build job list
        foreach ($commonTables as $table) {
            $jobs[] = ['type' => 'common', 'table' => $table];
        }
        foreach ($addedTables as $table) {
            $jobs[] = ['type' => 'added', 'table' => $table];
        }
        foreach ($deletedTables as $table) {
            $jobs[] = ['type' => 'deleted', 'table' => $table];
        }

        if (empty($jobs)) {
            Logger::info("Parallel mode: No tables to process.");
            return [];
        }

        $numWorkers = max(1, min($numWorkers, count($jobs)));

        Logger::info("Parallel diff starting – {$numWorkers} workers, " . count($jobs) . " tables total.");

        $start = microtime(true);

        // Chunk by weighted costs
        $jobChunks = $this->buildWeightedChunks($jobs, $numWorkers);

        // Log chunk distribution
        foreach ($jobChunks as $workerIndex => $chunk) {
            $tableList = array_map(fn($j) => $j['table'], $chunk);
            Logger::info("Worker {$workerIndex} assigned " . count($chunk) . " tables: [" . implode(', ', $tableList) . "]");
        }

        // Prepare temp storage
        $tmpDir = sys_get_temp_dir() . '/dbdiff_' . uniqid('', true);
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0770, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException("Failed to create temp directory: {$tmpDir}");
        }

        $pids = [];

        Logger::info("Forking {$numWorkers} workers...");

        foreach ($jobChunks as $workerIndex => $chunk) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                throw new \RuntimeException('Could not fork process');
            }

            if ($pid === 0) {
                // Child
                Logger::info("[Worker {$workerIndex}] Started.");
                $this->runWorker($workerIndex, $chunk, $tmpDir);
                Logger::info("[Worker {$workerIndex}] Finished.");
                exit(0);
            }

            // Parent
            $pids[$pid] = $workerIndex;
            Logger::info("Forked worker {$workerIndex} with PID {$pid}");
        }

        Logger::info("All workers forked. Waiting for completion...");

        // Parent waits for workers
        foreach ($pids as $pid => $workerIndex) {
            pcntl_waitpid($pid, $status);

            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                Logger::info("Worker {$workerIndex} (PID {$pid}) exited normally.");
            } else {
                Logger::warning("Worker {$workerIndex} (PID {$pid}) exited with status " . pcntl_wexitstatus($status));
            }
        }

        Logger::info("All workers finished. Merging results...");

        // Merge results
        $diffSequence = [];
        foreach (array_keys($jobChunks) as $workerIndex) {
            $file = $tmpDir . "/worker_{$workerIndex}.ser";

            if (!is_file($file)) {
                Logger::warning("Worker {$workerIndex} returned no result file.");
                continue;
            }

            $data = file_get_contents($file);

            if ($data === false || $data === '') {
                Logger::warning("Worker {$workerIndex} result file empty.");
                continue;
            }

            $workerDiffs = @unserialize($data);

            if (!is_array($workerDiffs)) {
                Logger::warning("Worker {$workerIndex} returned invalid serialized diff data.");
            } else {
                Logger::info("Merged " . count($workerDiffs) . " diffs from worker {$workerIndex}.");
                $diffSequence = array_merge($diffSequence, $workerDiffs);
            }

            @unlink($file);
        }

        @rmdir($tmpDir);

        $end = microtime(true);
        $duration = round($end - $start, 2);

        Logger::info("Parallel diff complete. Total combined diffs: " . count($diffSequence));
        Logger::info("Parallel run finished in {$duration} seconds.");

        return $diffSequence;
    }


    /**
     * Child-worker logic – processes its assigned jobs and serializes the diff sequence.
     *
     * @param int   $workerIndex
     * @param array $jobs Each job: ['type' => 'common|added|deleted', 'table' => string]
     * @param string $tmpDir
     */
    protected function runWorker(int $workerIndex, array $jobs, string $tmpDir): void
    {
        $diffSequence = [];

        foreach ($jobs as $job) {
            $table = $job['table'];
            $type  = $job['type'];

            try {
                switch ($type) {
                    case 'common':
                        Logger::info("[Worker {$workerIndex}] Diffing table `{$table}`");
                        $diffs = $this->tableData->getDiff($table);
                        break;

                    case 'added':
                        Logger::info("[Worker {$workerIndex}] Getting new data from table `{$table}`");
                        $diffs = $this->tableData->getNewData($table);
                        break;

                    case 'deleted':
                        Logger::info("[Worker {$workerIndex}] Getting old data from table `{$table}`");
                        $diffs = $this->tableData->getOldData($table);
                        break;

                    default:
                        $diffs = [];
                        break;
                }

                if (!empty($diffs)) {
                    $diffSequence = array_merge($diffSequence, $diffs);
                }
            } catch (DataException $e) {
                Logger::error("[Worker {$workerIndex}] " . $e->getMessage());
            } catch (\Throwable $e) {
                Logger::error("[Worker {$workerIndex}] Unexpected error on table `{$table}`: " . $e->getMessage());
            }
        }

        $file = $tmpDir . "/worker_{$workerIndex}.ser";
        file_put_contents($file, serialize($diffSequence));
    }

    /**
     * Build chunks of jobs for N workers using greedy bin-packing
     * based on estimated table size (row count).
     *
     * @param array $jobs       Each job: ['type' => 'common|added|deleted', 'table' => string]
     * @param int   $numWorkers
     * @return array            Array of job arrays, one per worker
     */
    protected function buildWeightedChunks(array $jobs, int $numWorkers): array
    {
        Logger::info("Building weighted chunks: " . count($jobs) . " jobs across {$numWorkers} workers.");

        // Attach weights
        foreach ($jobs as $i => $job) {
            $jobs[$i]['weight'] = $this->estimateJobWeight($job);
            Logger::info(sprintf(
                "Job %d: table=`%s`, type=%s, weight=%d",
                $i,
                $job['table'],
                $job['type'],
                $jobs[$i]['weight']
            ));
        }

        // Sort by weight DESC so biggest jobs are placed first
        usort($jobs, function ($a, $b) {
            return $b['weight'] <=> $a['weight'];
        });

        // Initialise N buckets
        $buckets = [];
        for ($i = 0; $i < $numWorkers; $i++) {
            $buckets[$i] = [
                'jobs'   => [],
                'weight' => 0,
            ];
        }

        // Greedy bin-packing: each job goes to the least-loaded worker
        foreach ($jobs as $job) {
            $minIndex  = 0;
            $minWeight = $buckets[0]['weight'];

            foreach ($buckets as $idx => $bucket) {
                if ($bucket['weight'] < $minWeight) {
                    $minWeight = $bucket['weight'];
                    $minIndex  = $idx;
                }
            }

            $buckets[$minIndex]['jobs'][]  = $job;
            $buckets[$minIndex]['weight'] += $job['weight'];
        }

        // Strip weight metadata, return plain job lists – but log the distribution first
        $chunks = [];
        foreach ($buckets as $idx => $bucket) {
            $tableNames = array_map(function ($job) {
                return $job['table'];
            }, $bucket['jobs']);

            Logger::info(sprintf(
                "Worker %d assigned %d jobs, total weight=%d, tables=[%s]",
                $idx,
                count($bucket['jobs']),
                $bucket['weight'],
                implode(', ', $tableNames)
            ));

            $chunks[] = array_map(function ($job) {
                unset($job['weight']);
                return $job;
            }, $bucket['jobs']);
        }

        return $chunks;
    }


    /**
     * Estimate the "weight" (cost) of a job based on table row count.
     *
     * - For 'common' and 'added' we use source DB row count
     * - For 'deleted' we use target DB row count
     * - Fallback is 1 if anything fails
     */
    protected function estimateJobWeight(array $job): int
    {
        $table = $job['table'];
        $type  = $job['type'];

        try {
            if ($type === 'deleted') {
                $db = $this->manager->getDB('target');
            } else {
                // 'common' or 'added'
                $db = $this->manager->getDB('source');
            }

            // Same API used by TableIterator: $connection->table($table)->count()
            $count = $db->table($table)->count();

            // Avoid zero weight – ensure at least 1
            return max(1, (int) $count);
        } catch (\Throwable $e) {
            Logger::warning("Failed to estimate size for table `{$table}` ({$type}): " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Filter out tables that are identical between source and target.
     * Only uses a strict checksum when source & target are on the same MySQL server.
     *
     * @param array $commonTables
     * @return array filtered table list
     */
    protected function filterIdenticalTables(array $commonTables): array
    {
        if (empty($commonTables)) {
            return $commonTables;
        }

        $source = $this->manager->getDB('source');
        $target = $this->manager->getDB('target');

        $server1 = $source->getConfig('host') . ':' . $source->getConfig('port');
        $server2 = $target->getConfig('host') . ':' . $target->getConfig('port');

        // Only support safe pre-scan when both DBs are on the same MySQL server
        if ($server1 !== $server2) {
            Logger::info(
                "skipIdenticalTables enabled, but source/target are on different servers; pre-scan skipped"
            );
            return $commonTables;
        }

        $filtered = [];
        foreach ($commonTables as $table) {
            try {
                if ($this->isTableIdenticalSameServer($table)) {
                    Logger::info("Skipping table `{$table}` – CHECKSUM TABLE indicates identical data");
                    continue;
                }
            } catch (\Throwable $e) {
                Logger::warning("Pre-scan for table `{$table}` failed: " . $e->getMessage());
                // If anything goes wrong, fall back to normal diff
            }

            $filtered[] = $table;
        }

        return $filtered;
    }

    /**
     * Strict check for identical table contents when both DBs are on the same MySQL server.
     *
     * Uses: CHECKSUM TABLE db1.table, db2.table;
     *
     * @param string $table
     * @return bool true if tables are identical
     */
    protected function isTableIdenticalSameServer(string $table): bool
    {
        $source = $this->manager->getDB('source');
        $target = $this->manager->getDB('target');

        $db1 = $source->getDatabaseName();
        $db2 = $target->getDatabaseName();

        $source->setFetchMode(\PDO::FETCH_ASSOC);

        $sql = sprintf(
            'CHECKSUM TABLE `%s`.`%s`, `%s`.`%s`',
            $db1,
            $table,
            $db2,
            $table
        );

        $rows = $source->select($sql);

        // Expected shape:
        // [
        //   ['Table' => 'db1.table', 'Checksum' => 12345],
        //   ['Table' => 'db2.table', 'Checksum' => 12345],
        // ]
        if (!is_array($rows) || count($rows) < 2) {
            return false;
        }

        $checksum1 = $rows[0]['Checksum'] ?? null;
        $checksum2 = $rows[1]['Checksum'] ?? null;

        // Some engines can return NULL => cannot assert equality
        if ($checksum1 === null || $checksum2 === null) {
            return false;
        }

        return ((string) $checksum1 === (string) $checksum2);
    }
}
