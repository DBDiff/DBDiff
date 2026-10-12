<?php namespace DBDiff\DB\Data;

use DBDiff\DB\Support\SchemaScope;
use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Diff\DeleteData;
use DBDiff\Exceptions\DataException;
use DBDiff\Logger;
use DBDiff\Params\ParamsFactory;
use DBDiff\Params\TableFilter;
use DBDiff\DB\Support\QueryHelper;
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

    /**
     * Up to about this many rows a side, a PostgreSQL table is hashed with a
     * buffered query: faster than a cursor, and at roughly 90 bytes a row
     * still well inside the memory limit. Larger tables, and tables the
     * server has no row estimate for, are read through a cursor so memory
     * stays flat. See RowStream and RowEstimate.
     */
    private const BUFFER_ROWS = 1_000_000;

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

        return array_merge(
            $this->collectInserts($table, $key, $sourceColumns, $insertPKs),
            $this->collectDeletes($table, $key, $targetColumns, $deletePKs),
            $this->collectUpdates($table, $key, $commonCols, $hashCols, $updatePKs)
        );
    }

    /**
     * The data diff of a table with no key: rows are matched by their whole
     * content, and identical rows by how many copies each side holds.
     *
     * Each server groups its rows by a hash of every column and counts them,
     * in hash order, so the merge reads one small row per distinct value
     * rather than holding both tables: memory follows the differences, not
     * the table. Only if counts differ is each side read once more, to pick
     * up one copy of every row that has to be inserted or deleted.
     *
     * @param string[] $columns The table's columns, the same on both sides.
     * @return array InsertData and DeleteData, marked keyless so that removing
     *               a row removes one of several identical copies, not all.
     */
    public function getKeylessDiff(string $table, array $columns): array
    {
        $hashExpr = $this->buildHashExpression($columns);
        $q        = $this->tableName($table);
        $order    = match ($this->driver) {
            'pgsql' => 'h COLLATE "C"',
            'mysql' => 'CAST(h AS BINARY)',
            default => 'h',
        };
        $sql = "SELECT h AS _hash, n AS _n FROM (SELECT {$hashExpr} AS h, count(*) AS n FROM {$q} GROUP BY {$hashExpr}) g ORDER BY {$order}";

        Logger::info("Table `$table` has no key: comparing whole rows by hash");
        [$insert, $delete] = $this->mergeCounts($table, $sql);
        if (!$insert && !$delete) {
            return [];
        }
        Logger::info(sprintf('Fetching %d distinct row(s) to insert, %d to delete for table `%s`', count($insert), count($delete), $table));

        $textMatch = $this->textMatchColumns($table, $columns);
        $diffs = [];
        foreach ([[$this->source, $insert, InsertData::class, \Diff\DiffOp\DiffOpAdd::class, 'keyless_source'],
                  [$this->target, $delete, DeleteData::class, \Diff\DiffOp\DiffOpRemove::class, 'keyless_target']]
                 as [$conn, $wanted, $class, $op, $name]) {
            if (!$wanted) {
                continue;
            }
            $rows = $this->rowsWithHash($conn, $table, $columns, $hashExpr, $wanted, $name);
            ksort($rows, SORT_STRING); // a stable order, independent of how the server returned them
            foreach ($rows as $hash => $row) {
                for ($i = 0; $i < $wanted[$hash]; $i++) {
                    $diffs[] = new $class($table, ['keys' => $row, 'diff' => new $op($row), 'keyless' => true]
                        + ($textMatch ? ['textMatch' => $textMatch] : []));
                }
            }
        }
        return $diffs;
    }

    /**
     * Columns a keyless row has to be matched on by their text: those whose
     * type has no `=` (PostgreSQL json, xml, point, polygon…), and MySQL
     * JSON, which compares unequal to the string it was read as.
     *
     * @return string[]
     */
    private function textMatchColumns(string $table, array $columns): array
    {
        try {
            if ($this->driver === 'pgsql') {
                $rows = $this->source->select(
                    "SELECT a.attname AS name
                       FROM pg_attribute a
                       JOIN pg_type d ON d.oid = a.atttypid
                       JOIN pg_type t ON t.oid = CASE WHEN d.typtype = 'd' THEN d.typbasetype ELSE d.oid END
                      WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped
                        AND NOT EXISTS (SELECT 1 FROM pg_operator o
                                         WHERE o.oprname = '=' AND o.oprleft = t.oid AND o.oprright = t.oid)",
                    [$this->tableName($table)]
                );
            } elseif ($this->driver === 'mysql') {
                $rows = $this->source->select(
                    "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND DATA_TYPE = 'json'",
                    [$table]
                );
            } else {
                return [];
            }
        } catch (\Throwable $e) {
            Logger::info("Could not read column types for `$table`: " . $e->getMessage());
            return [];
        }
        $names = array_map(fn($r) => array_change_key_case((array) $r, CASE_LOWER)['name'], $rows);
        return array_values(array_intersect($columns, $names));
    }

    /**
     * Merge two `_hash, _n` streams in hash order.
     *
     * @return array{0: array<string, int>, 1: array<string, int>} copies to insert and to delete, by hash
     */
    private function mergeCounts(string $table, string $sql): array
    {
        $srcPdo  = $this->source->getPdo();
        $tgtPdo  = $this->target->getPdo();
        $shared  = $srcPdo === $tgtPdo;
        $q       = $this->tableName($table);
        $bounded = $this->driver !== 'pgsql' || !$this->fitsInMemory($this->source, $q) || !$this->fitsInMemory($this->target, $q);

        $src = new RowStream($srcPdo, $this->driver, $sql, 'keyless_source', !$shared, $bounded);
        try {
            $tgt = new RowStream($tgtPdo, $this->driver, $sql, 'keyless_target', !$shared, $bounded);
        } catch (\Throwable $e) {
            $src->close();
            throw $e;
        }

        $insert = [];
        $delete = [];
        try {
            $s = $src->fetch();
            $t = $tgt->fetch();
            while ($s !== false || $t !== false) {
                $cmp = $s === false ? 1 : ($t === false ? -1 : strcmp((string) $s['_hash'], (string) $t['_hash']));
                if ($cmp < 0) {
                    $insert[(string) $s['_hash']] = (int) $s['_n'];
                    $s = $src->fetch();
                } elseif ($cmp > 0) {
                    $delete[(string) $t['_hash']] = (int) $t['_n'];
                    $t = $tgt->fetch();
                } else {
                    $extra = (int) $s['_n'] - (int) $t['_n'];
                    if ($extra > 0) {
                        $insert[(string) $s['_hash']] = $extra;
                    } elseif ($extra < 0) {
                        $delete[(string) $t['_hash']] = -$extra;
                    }
                    $s = $src->fetch();
                    $t = $tgt->fetch();
                }
            }
        } finally {
            $tgt->close();
            $src->close();
        }
        return [$insert, $delete];
    }

    /**
     * One copy of each row of `$table` whose hash is in `$wanted`, read in a
     * single pass, keyed by hash. Columns in name order, as the keyless
     * payload has always carried them.
     *
     * @param array<string, int> $wanted
     * @return array<string, array>
     */
    private function rowsWithHash(Connection $conn, string $table, array $columns, string $hashExpr, array $wanted, string $name): array
    {
        $q       = $this->tableName($table);
        $cols    = implode(', ', $this->quoteCols($columns));
        $bounded = $this->driver !== 'pgsql' || !$this->fitsInMemory($conn, $q);
        $stream  = new RowStream($conn->getPdo(), $this->driver, "SELECT {$cols}, {$hashExpr} AS _hash FROM {$q}", $name, true, $bounded);

        $found = [];
        try {
            while (count($found) < count($wanted) && ($row = $stream->fetch()) !== false) {
                $hash = (string) $row['_hash'];
                if (isset($wanted[$hash]) && !isset($found[$hash])) {
                    unset($row['_hash']);
                    $row = BinaryValue::fromStreams($row);
                    ksort($row);
                    $found[$hash] = $row;
                }
            }
        } finally {
            $stream->close();
        }
        return $found;
    }

    private function collectInserts(string $table, array $key, array $sourceColumns, array $insertPKs): array
    {
        $rowRules = TableFilter::getRowIgnoreRules($table, ParamsFactory::get());
        $result = [];
        foreach (array_chunk($insertPKs, self::BATCH_SIZE) as $batch) {
            $rows = $this->fetchRowsByPK($this->source, $table, $key, $sourceColumns, $batch);
            if (!empty($rowRules)) {
                $rows = TableFilter::filterRows($rows, $rowRules);
            }
            foreach ($rows as $row) {
                $result[] = new InsertData($table, [
                    'keys' => Arr::only($row, $key),
                    'diff' => new \Diff\DiffOp\DiffOpAdd($row),
                ]);
            }
        }
        return $result;
    }

    private function collectDeletes(string $table, array $key, array $targetColumns, array $deletePKs): array
    {
        $rowRules = TableFilter::getRowIgnoreRules($table, ParamsFactory::get());
        $result = [];
        foreach (array_chunk($deletePKs, self::BATCH_SIZE) as $batch) {
            $rows = $this->fetchRowsByPK($this->target, $table, $key, $targetColumns, $batch);
            if (!empty($rowRules)) {
                $rows = TableFilter::filterRows($rows, $rowRules);
            }
            foreach ($rows as $row) {
                $result[] = new DeleteData($table, [
                    'keys' => Arr::only($row, $key),
                    'diff' => new \Diff\DiffOp\DiffOpRemove($row),
                ]);
            }
        }
        return $result;
    }

    private function collectUpdates(string $table, array $key, array $commonCols, array $hashCols, array $updatePKs): array
    {
        if (empty($updatePKs) || empty($hashCols)) {
            return [];
        }
        $result = [];
        foreach (array_chunk($updatePKs, self::BATCH_SIZE) as $batch) {
            $srcRows  = $this->fetchRowsByPK($this->source, $table, $key, $commonCols, $batch);
            $tgtRows  = $this->fetchRowsByPK($this->target, $table, $key, $commonCols, $batch);
            $tgtIndex = [];
            foreach ($tgtRows as $row) {
                $tgtIndex[$this->buildPKString($row, $key)] = $row;
            }
            foreach ($srcRows as $srcRow) {
                $update = $this->buildSingleUpdate($table, $key, $hashCols, $srcRow, $tgtIndex);
                if ($update !== null) {
                    $result[] = $update;
                }
            }
        }
        return $result;
    }

    private function buildSingleUpdate(string $table, array $key, array $hashCols, array $srcRow, array $tgtIndex): ?UpdateData
    {
        $tgtRow = $tgtIndex[$this->buildPKString($srcRow, $key)] ?? null;
        if ($tgtRow === null) {
            return null;
        }
        $diff = [];
        $keys = [];
        foreach ($hashCols as $col) {
            if (in_array($col, $key)) {
                $keys[$col] = $srcRow[$col];
            }
            if (!self::sameValue($srcRow[$col] ?? null, $tgtRow[$col] ?? null)) {
                $diff[$col] = new \Diff\DiffOp\DiffOpChange($tgtRow[$col], $srcRow[$col]);
            }
        }
        foreach ($key as $k) {
            if (!isset($keys[$k])) {
                $keys[$k] = $srcRow[$k];
            }
        }
        return empty($diff) ? null : new UpdateData($table, ['keys' => $keys, 'diff' => $diff]);
    }

    /**
     * Whether two fetched values are the same. NULL equals only NULL: compared
     * as strings, it read the same as an empty string.
     */
    private static function sameValue(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return ScalarText::of($a) === ScalarText::of($b);
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
        [$orderBy, $numeric] = (new KeyOrder($this->source, $this->driver))->of($table, $this->tableName($table), $key);
        $q        = $this->tableName($table);

        [$src, $tgt] = $this->openStreams("SELECT {$pkSelect}, {$hashExpr} AS _hash FROM {$q} ORDER BY {$orderBy}", $q, 'stream');
        try {
            return $this->mergeByKey($src, $tgt, $key, $numeric);
        } finally {
            // Reverse order: on a shared connection the source owns the transaction.
            $tgt->close();
            $src->close();
        }
    }

    /**
     * `$sql` on both sides, read a batch at a time — see RowStream for why
     * fetch() alone is not enough. A PostgreSQL table small enough to buffer
     * keeps the faster buffered read.
     *
     * @return RowStream[] [source, target]; the caller closes them, target first.
     */
    private function openStreams(string $sql, string $table, string $name): array
    {
        $srcPdo  = $this->source->getPdo();
        $tgtPdo  = $this->target->getPdo();
        $shared  = $srcPdo === $tgtPdo;
        $bounded = $this->driver !== 'pgsql'
            || !RowEstimate::atMost($this->source, $table, self::BUFFER_ROWS)
            || !RowEstimate::atMost($this->target, $table, self::BUFFER_ROWS);

        $src = new RowStream($srcPdo, $this->driver, $sql, "{$name}_source", !$shared, $bounded);
        try {
            $tgt = new RowStream($tgtPdo, $this->driver, $sql, "{$name}_target", !$shared, $bounded);
        } catch (\Throwable $e) {
            $src->close();
            throw $e;
        }
        return [$src, $tgt];
    }

    /**
     * Walk two key-ordered `key…, _hash` streams together. `$numeric`:
     * the key columns compared as numbers, see KeyOrder.
     *
     * @return array{0: array[], 1: array[], 2: array[]} [insertPKs, deletePKs, updatePKs]
     */
    private function mergeByKey(RowStream $src, RowStream $tgt, array $key, array $numeric): array
    {
        $insertPKs = [];
        $deletePKs = [];
        $updatePKs = [];

        $srcRow = $src->fetch();
        $tgtRow = $tgt->fetch();
        while ($srcRow !== false || $tgtRow !== false) {
            $cmp = $this->nextInOrder($srcRow, $tgtRow, $key, $numeric);
            if ($cmp < 0) {
                // Only the source has it
                $insertPKs[] = Arr::only($srcRow, $key);
                $srcRow = $src->fetch();
            } elseif ($cmp > 0) {
                // Only the target has it
                $deletePKs[] = Arr::only($tgtRow, $key);
                $tgtRow = $tgt->fetch();
            } else {
                // Both have it — compare hashes
                if ($srcRow['_hash'] !== $tgtRow['_hash']) {
                    $updatePKs[] = Arr::only($srcRow, $key);
                }
                $srcRow = $src->fetch();
                $tgtRow = $tgt->fetch();
            }
        }
        return [$insertPKs, $deletePKs, $updatePKs];
    }

    /** Which row comes first: negative the source's, positive the target's, 0 the same key. An exhausted side comes last. */
    private function nextInOrder(array|false $srcRow, array|false $tgtRow, array $key, array $numeric): int
    {
        if ($srcRow === false) {
            return 1;
        }
        if ($tgtRow === false) {
            return -1;
        }
        return $this->comparePK($srcRow, $tgtRow, $key, $numeric);
    }

    /**
     * Build a driver-appropriate SQL hash expression for the given columns.
     *
     * Each value is tagged: N for NULL, V followed by its text otherwise, and
     * joined with the unit separator (0x1f). Writing NULL as an empty string
     * instead, as this once did, hashed NULL and '' alike, so a column changed
     * from one to the other was reported as no change at all.
     *
     * MySQL:    SHA2(CONCAT(IF(col IS NULL, 'N', CONCAT('V', CAST(col AS CHAR …))), CHAR(31), …), 256)
     * Postgres: md5(CASE WHEN col IS NULL THEN 'N' ELSE 'V' || col::text END || E'\x1f' || …)
     * SQLite:   hex(CASE WHEN col IS NULL THEN 'N' ELSE 'V' || CAST(col AS TEXT) END || X'1f' || …)
     */
    public function buildHashExpression(array $columns): string
    {
        if (empty($columns)) {
            return "'empty'";
        }

        return match ($this->driver) {
            'mysql'  => 'SHA2(CONCAT(' . implode(", CHAR(31), ", array_map(fn($c) => "IF(`$c` IS NULL, 'N', CONCAT('V', CAST(`$c` AS CHAR CHARACTER SET utf8)))", $columns)) . '), 256)',
            'pgsql'  => "md5(" . implode(" || E'\\x1f' || ", array_map(fn($c) => "CASE WHEN \"$c\" IS NULL THEN 'N' ELSE 'V' || \"$c\"::text END", $columns)) . ")",
            'sqlite' => "hex(" . implode(" || X'1f' || ", array_map(fn($c) => "CASE WHEN \"$c\" IS NULL THEN 'N' ELSE 'V' || CAST(\"$c\" AS TEXT) END", $columns)) . ")",
            default  => throw new DataException("Unsupported driver: {$this->driver}"),
        };
    }

    /**
     * Compare two PK tuples for merge-sort ordering.
     *
     * Must order keys exactly as the hash query's ORDER BY does, or the merge
     * falls out of step and reports rows both sides have as an insert and a
     * delete. `$numeric` names the key columns compared as numbers (see
     * KeyOrder); every other column compares byte by byte. Without it, a
     * value that looks numeric is compared as a number — fine for an integer
     * key, wrong for a text key holding '9' and '10'.
     *
     * @param string[]|null $numeric
     */
    public function comparePK(array $a, array $b, array $key, ?array $numeric = null): int
    {
        foreach ($key as $col) {
            $av = $a[$col] ?? '';
            $bv = $b[$col] ?? '';
            $asNumber = $numeric === null ? is_numeric($av) && is_numeric($bv) : in_array($col, $numeric, true);
            $cmp = $asNumber ? $av <=> $bv : strcmp((string) $av, (string) $bv);
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

        $q       = $this->tableName($table);
        $colList = implode(', ', $this->quoteCols($columns));
        $orderBy = implode(', ', $this->quoteCols($key));

        if (count($key) === 1) {
            $pkCol        = $key[0];
            $values       = array_map(fn($pk) => $pk[$pkCol], $pks);
            $placeholders = QueryHelper::placeholders($values);
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
        return array_map(fn($row) => BinaryValue::fromStreams(is_array($row) ? $row : (array) $row), $rows);
    }

    /**
     * A table as the queries name it: in PostgreSQL, in the schema being
     * compared, which both connections share.
     */
    private function tableName(string $table): string
    {
        return $this->driver === 'pgsql' ? SchemaScope::name($this->source, $table) : $this->quoteIdentifier($table);
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
