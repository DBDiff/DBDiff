<?php namespace DBDiff\DB\Data;

use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Diff\DeleteData;
use DBDiff\Logger;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;

/**
 * Streaming sorted-merge diff algorithm.
 *
 * Performs a two-phase data diff that scales to millions of rows without
 * loading entire tables into PHP memory:
 *
 * Phase 1 — Hash stream: Fetch only PK + row hash from both sides in PK
 *           order. A merge-sort pass identifies inserts, deletes, and
 *           changed PKs without transferring full row data.
 *
 * Phase 2 — Targeted fetch: Retrieve full rows only for the differing PKs
 *           (chunked in batches) to build the actual diff objects.
 *
 * Used by:
 *   - PostgreSQL same-server: cannot JOIN across databases natively
 *   - Any driver cross-server: DistTableData
 */
class StreamingMergeDiff
{
    /** Maximum PKs per batch-fetch query in Phase 2. */
    private const BATCH_SIZE = 500;

    private Connection $source;
    private Connection $target;
    private string $driver;

    public function __construct(Connection $source, Connection $target, string $driver)
    {
        $this->source = $source;
        $this->target = $target;
        $this->driver = $driver;
    }

    /**
     * @param string   $table          Table name
     * @param string[] $key            Primary key column names
     * @param string[] $sourceColumns  All column names on source
     * @param string[] $targetColumns  All column names on target
     * @param string[] $fieldsToIgnore Column names to exclude from hash comparison
     * @return array Diff objects (InsertData, DeleteData, UpdateData)
     */
    public function getDiff(
        string $table,
        array  $key,
        array  $sourceColumns,
        array  $targetColumns,
        array  $fieldsToIgnore = []
    ): array {
        $commonCols = array_values(array_intersect($sourceColumns, $targetColumns));
        $hashCols   = array_values(array_diff($commonCols, $fieldsToIgnore));

        // Phase 1: Stream PK + hash, identify differing PKs
        Logger::info("Streaming hash comparison for table `$table`");
        [$insertPKs, $deletePKs, $updatePKs] = $this->streamAndMerge($table, $key, $hashCols);

        if (empty($insertPKs) && empty($deletePKs) && empty($updatePKs)) {
            return [];
        }

        // Phase 2: Fetch full rows only for differing PKs
        Logger::info(sprintf(
            'Fetching %d insert(s), %d delete(s), %d update(s) for table `%s`',
            count($insertPKs), count($deletePKs), count($updatePKs), $table
        ));

        $diffSequence = [];

        // Inserts (rows in source but not target)
        foreach (array_chunk($insertPKs, self::BATCH_SIZE) as $batch) {
            $rows = $this->fetchRowsByPK($this->source, $table, $key, $sourceColumns, $batch);
            foreach ($rows as $row) {
                $diffSequence[] = new InsertData($table, [
                    'keys' => Arr::only($row, $key),
                    'diff' => new \Diff\DiffOp\DiffOpAdd($row),
                ]);
            }
        }

        // Deletes (rows in target but not source)
        foreach (array_chunk($deletePKs, self::BATCH_SIZE) as $batch) {
            $rows = $this->fetchRowsByPK($this->target, $table, $key, $targetColumns, $batch);
            foreach ($rows as $row) {
                $diffSequence[] = new DeleteData($table, [
                    'keys' => Arr::only($row, $key),
                    'diff' => new \Diff\DiffOp\DiffOpRemove($row),
                ]);
            }
        }

        // Updates (rows in both but with different hashes)
        if (!empty($updatePKs) && !empty($hashCols)) {
            foreach (array_chunk($updatePKs, self::BATCH_SIZE) as $batch) {
                $srcRows = $this->fetchRowsByPK($this->source, $table, $key, $commonCols, $batch);
                $tgtRows = $this->fetchRowsByPK($this->target, $table, $key, $commonCols, $batch);

                // Index target rows by PK for O(1) lookup
                $tgtIndex = [];
                foreach ($tgtRows as $row) {
                    $pkStr = $this->buildPKString($row, $key);
                    $tgtIndex[$pkStr] = $row;
                }

                foreach ($srcRows as $srcRow) {
                    $pkStr = $this->buildPKString($srcRow, $key);
                    $tgtRow = $tgtIndex[$pkStr] ?? null;
                    if ($tgtRow === null) {
                        continue;
                    }

                    $diff = [];
                    $keys = [];
                    foreach ($hashCols as $col) {
                        if (in_array($col, $key)) {
                            $keys[$col] = $srcRow[$col];
                        }
                        $sv = (string) ($srcRow[$col] ?? '');
                        $tv = (string) ($tgtRow[$col] ?? '');
                        if ($sv !== $tv) {
                            $diff[$col] = new \Diff\DiffOp\DiffOpChange($tgtRow[$col], $srcRow[$col]);
                        }
                    }
                    foreach ($key as $k) {
                        if (!isset($keys[$k])) {
                            $keys[$k] = $srcRow[$k];
                        }
                    }
                    if (!empty($diff)) {
                        $diffSequence[] = new UpdateData($table, ['keys' => $keys, 'diff' => $diff]);
                    }
                }
            }
        }

        return $diffSequence;
    }

    /**
     * Phase 1: Stream PK + hash from both databases in PK order using a
     * merge-sort-like comparison.
     *
     * @return array{0: array[], 1: array[], 2: array[]} [insertPKs, deletePKs, updatePKs]
     */
    private function streamAndMerge(string $table, array $key, array $hashCols): array
    {
        $hashExpr = $this->buildHashExpression($hashCols);
        $pkCols   = $this->quoteCols($key);
        $pkSelect = implode(', ', $pkCols);
        $orderBy  = implode(', ', $pkCols);
        $q        = $this->quoteIdentifier($table);

        $srcPdo  = $this->source->getPdo();
        $tgtPdo  = $this->target->getPdo();

        $sql     = "SELECT {$pkSelect}, {$hashExpr} AS _hash FROM {$q} ORDER BY {$orderBy}";
        $srcStmt = $srcPdo->prepare($sql);
        $srcStmt->execute();
        $tgtStmt = $tgtPdo->prepare($sql);
        $tgtStmt->execute();

        $insertPKs = [];
        $deletePKs = [];
        $updatePKs = [];

        $srcRow = $srcStmt->fetch(\PDO::FETCH_ASSOC);
        $tgtRow = $tgtStmt->fetch(\PDO::FETCH_ASSOC);

        while ($srcRow !== false || $tgtRow !== false) {
            if ($srcRow === false) {
                // Source exhausted → remaining target rows are deletes
                $deletePKs[] = Arr::only($tgtRow, $key);
                $tgtRow = $tgtStmt->fetch(\PDO::FETCH_ASSOC);
            } elseif ($tgtRow === false) {
                // Target exhausted → remaining source rows are inserts
                $insertPKs[] = Arr::only($srcRow, $key);
                $srcRow = $srcStmt->fetch(\PDO::FETCH_ASSOC);
            } else {
                $cmp = $this->comparePK($srcRow, $tgtRow, $key);
                if ($cmp < 0) {
                    $insertPKs[] = Arr::only($srcRow, $key);
                    $srcRow = $srcStmt->fetch(\PDO::FETCH_ASSOC);
                } elseif ($cmp > 0) {
                    $deletePKs[] = Arr::only($tgtRow, $key);
                    $tgtRow = $tgtStmt->fetch(\PDO::FETCH_ASSOC);
                } else {
                    // PKs match — compare hashes
                    if ($srcRow['_hash'] !== $tgtRow['_hash']) {
                        $updatePKs[] = Arr::only($srcRow, $key);
                    }
                    $srcRow = $srcStmt->fetch(\PDO::FETCH_ASSOC);
                    $tgtRow = $tgtStmt->fetch(\PDO::FETCH_ASSOC);
                }
            }
        }

        $srcStmt->closeCursor();
        $tgtStmt->closeCursor();

        return [$insertPKs, $deletePKs, $updatePKs];
    }

    /**
     * Build a driver-appropriate SQL hash expression for the given columns.
     *
     * MySQL:    SHA2(CONCAT(CAST(col AS CHAR CHARACTER SET utf8), …), 256)
     * Postgres: md5(COALESCE(col::text, '') || E'\x1f' || …)
     * SQLite:   hex(COALESCE(CAST(col AS TEXT), '') || X'1f' || …)
     */
    public function buildHashExpression(array $columns): string
    {
        if (empty($columns)) {
            return "'empty'";
        }

        switch ($this->driver) {
            case 'mysql':
                $cast = array_map(fn($c) => "CAST(`$c` AS CHAR CHARACTER SET utf8)", $columns);
                return 'SHA2(CONCAT(' . implode(', ', $cast) . '), 256)';

            case 'pgsql':
                $parts = array_map(fn($c) => "COALESCE(\"$c\"::text, '')", $columns);
                return "md5(" . implode(" || E'\\x1f' || ", $parts) . ")";

            case 'sqlite':
                $parts = array_map(fn($c) => "COALESCE(CAST(\"$c\" AS TEXT), '')", $columns);
                return "hex(" . implode(" || X'1f' || ", $parts) . ")";

            default:
                throw new \RuntimeException("Unsupported driver: {$this->driver}");
        }
    }

    /**
     * Compare two PK tuples for merge-sort ordering.
     * Returns < 0 if $a < $b, 0 if equal, > 0 if $a > $b.
     */
    public function comparePK(array $a, array $b, array $key): int
    {
        foreach ($key as $col) {
            $av = $a[$col] ?? '';
            $bv = $b[$col] ?? '';
            if (is_numeric($av) && is_numeric($bv)) {
                $cmp = $av <=> $bv;
            } else {
                $cmp = strcmp((string) $av, (string) $bv);
            }
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return 0;
    }

    /**
     * Fetch full rows for a batch of PKs, ordered by PK for deterministic output.
     *
     * @param Connection $conn
     * @param string     $table
     * @param string[]   $key     Primary key column names
     * @param string[]   $columns Columns to select
     * @param array[]    $pks     Array of PK value arrays
     * @return array[]
     */
    private function fetchRowsByPK(Connection $conn, string $table, array $key, array $columns, array $pks): array
    {
        if (empty($pks)) {
            return [];
        }

        $q       = $this->quoteIdentifier($table);
        $colList = implode(', ', $this->quoteCols($columns));
        $orderBy = implode(', ', $this->quoteCols($key));

        if (count($key) === 1) {
            $pkCol        = $key[0];
            $values       = array_map(fn($pk) => $pk[$pkCol], $pks);
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $qCol         = $this->quoteIdentifier($pkCol);
            $sql          = "SELECT {$colList} FROM {$q} WHERE {$qCol} IN ({$placeholders}) ORDER BY {$orderBy}";
            return $this->normaliseRows($conn->select($sql, $values));
        }

        // Composite PK: use OR'd equality conditions
        $conditions = [];
        $bindings   = [];
        foreach ($pks as $pk) {
            $parts = [];
            foreach ($key as $col) {
                $parts[]    = $this->quoteIdentifier($col) . ' = ?';
                $bindings[] = $pk[$col];
            }
            $conditions[] = '(' . implode(' AND ', $parts) . ')';
        }
        $sql = "SELECT {$colList} FROM {$q} WHERE " . implode(' OR ', $conditions) . " ORDER BY {$orderBy}";
        return $this->normaliseRows($conn->select($sql, $bindings));
    }

    /**
     * Normalise Illuminate select results to plain arrays.
     */
    private function normaliseRows(array $rows): array
    {
        return array_map(fn($row) => is_array($row) ? $row : (array) $row, $rows);
    }

    /**
     * Quote a single identifier for the active driver.
     */
    public function quoteIdentifier(string $name): string
    {
        return match ($this->driver) {
            'mysql' => "`$name`",
            default => "\"$name\"",
        };
    }

    /**
     * Quote an array of column names.
     */
    public function quoteCols(array $cols): array
    {
        return array_map(fn($c) => $this->quoteIdentifier($c), $cols);
    }

    /**
     * Build a composite PK string for array indexing.
     */
    public function buildPKString(array $row, array $key): string
    {
        return implode("\x00", array_map(fn($k) => (string) ($row[$k] ?? ''), $key));
    }
}
