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
     * stays flat. See RowStream and fitsInMemory().
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
        [$orderBy, $numeric] = $this->keyOrder($table, $key);
        $q        = $this->tableName($table);

        $srcPdo  = $this->source->getPdo();
        $tgtPdo  = $this->target->getPdo();
        $shared  = $srcPdo === $tgtPdo;

        $sql = "SELECT {$pkSelect}, {$hashExpr} AS _hash FROM {$q} ORDER BY {$orderBy}";
        // Read a batch at a time: see RowStream for why fetch() alone is not enough.
        $bounded = $this->driver !== 'pgsql'
            || !$this->fitsInMemory($this->source, $q)
            || !$this->fitsInMemory($this->target, $q);
        $src = new RowStream($srcPdo, $this->driver, $sql, 'stream_source', !$shared, $bounded);
        try {
            $tgt = new RowStream($tgtPdo, $this->driver, $sql, 'stream_target', !$shared, $bounded);
        } catch (\Throwable $e) {
            $src->close();
            throw $e;
        }

        $insertPKs = [];
        $deletePKs = [];
        $updatePKs = [];

        try {
            $srcRow = $src->fetch();
            $tgtRow = $tgt->fetch();

            while ($srcRow !== false || $tgtRow !== false) {
                if ($srcRow === false) {
                    // Source exhausted → remaining target rows are deletes
                    $deletePKs[] = Arr::only($tgtRow, $key);
                    $tgtRow = $tgt->fetch();
                } elseif ($tgtRow === false) {
                    // Target exhausted → remaining source rows are inserts
                    $insertPKs[] = Arr::only($srcRow, $key);
                    $srcRow = $src->fetch();
                } else {
                    $cmp = $this->comparePK($srcRow, $tgtRow, $key, $numeric);
                    if ($cmp < 0) {
                        $insertPKs[] = Arr::only($srcRow, $key);
                        $srcRow = $src->fetch();
                    } elseif ($cmp > 0) {
                        $deletePKs[] = Arr::only($tgtRow, $key);
                        $tgtRow = $tgt->fetch();
                    } else {
                        // PKs match — compare hashes
                        if ($srcRow['_hash'] !== $tgtRow['_hash']) {
                            $updatePKs[] = Arr::only($srcRow, $key);
                        }
                        $srcRow = $src->fetch();
                        $tgtRow = $tgt->fetch();
                    }
                }
            }
        } finally {
            // Reverse order: on a shared connection the source owns the transaction.
            $tgt->close();
            $src->close();
        }

        return [$insertPKs, $deletePKs, $updatePKs];
    }

    /**
     * Whether `$table` (already quoted) holds few enough rows to buffer.
     *
     * Estimated the way PostgreSQL's planner does: the rows per page seen by
     * the last ANALYZE or VACUUM, times the pages the table has now — so a
     * table that has grown since is not mistaken for its old size. With no
     * statistics yet (reltuples -1, or no pages counted), or for anything but
     * a plain table or materialised view (a partitioned parent has no pages
     * of its own), there is no estimate, and the table is not assumed small.
     */
    private function fitsInMemory(Connection $conn, string $table): bool
    {
        try {
            $row = $conn->selectOne(
                "SELECT c.relkind AS kind, c.reltuples::float8 AS tuples, c.relpages AS pages,
                        pg_relation_size(c.oid) / current_setting('block_size')::int AS current_pages
                   FROM pg_class c WHERE c.oid = to_regclass(?)",
                [$table]
            );
        } catch (\Throwable) {
            return false;
        }
        if ($row === null) {
            return false;
        }
        // DBDiff's connections fetch arrays, a plain Laravel one objects.
        $row = (array) $row;
        if (!in_array($row['kind'], ['r', 'm'], true)) {
            return false;
        }
        if ((int) $row['current_pages'] === 0) {
            return true;
        }
        if ((int) $row['pages'] <= 0 || (float) $row['tuples'] < 0) {
            return false;
        }
        $estimate = (float) $row['tuples'] / (int) $row['pages'] * (int) $row['current_pages'];
        return $estimate <= self::BUFFER_ROWS;
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
     * keyOrder()); every other column compares byte by byte. Without it, a
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
     * The hash query's ORDER BY, and which key columns comparePK() treats as
     * numbers — chosen together so the database and the merge agree.
     *
     * A numeric key sorts natively and compares as a number. Anything else
     * sorts by the bytes of its text, which is what strcmp() compares: under
     * a linguistic collation (en_US, utf8mb4_0900_ai_ci) the database puts
     * 'a' before 'B' where strcmp() puts 'B' first. A uuid keeps its native
     * order, which is already its hex text's, so its index still serves.
     *
     * @return array{0: string, 1: string[]}
     */
    private function keyOrder(string $table, array $key): array
    {
        $kinds   = $this->keyKinds($table, $key);
        $numeric = [];
        $terms   = [];
        foreach ($key as $col) {
            $quoted = $this->quoteIdentifier($col);
            $kind   = $kinds[$col] ?? 'text';
            if ($kind === 'number') {
                $numeric[] = $col;
                $terms[]   = $quoted;
            } elseif ($kind === 'uuid') {
                $terms[] = $quoted;
            } else {
                $terms[] = match ($this->driver) {
                    'pgsql'  => "({$quoted})::text COLLATE \"C\"",
                    'mysql'  => "CAST({$quoted} AS BINARY)",
                    default  => "CAST({$quoted} AS TEXT) COLLATE BINARY",
                };
            }
        }
        return [implode(', ', $terms), $numeric];
    }

    /**
     * 'number', 'uuid' or 'text' for each key column, from the source's
     * catalog. A column it cannot classify is 'text', the order that is
     * always consistent with strcmp().
     *
     * @return array<string, string>
     */
    private function keyKinds(string $table, array $key): array
    {
        $kinds = [];
        try {
            if ($this->driver === 'pgsql') {
                $rows = $this->source->select(
                    "SELECT a.attname AS name, t.typcategory AS category, t.typname AS type
                       FROM pg_attribute a
                       JOIN pg_type d ON d.oid = a.atttypid
                       -- A domain sorts as its base type.
                       JOIN pg_type t ON t.oid = CASE WHEN d.typtype = 'd' THEN d.typbasetype ELSE d.oid END
                      WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped",
                    [$this->tableName($table)]
                );
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $kinds[$row['name']] = $row['category'] === 'N' ? 'number' : ($row['type'] === 'uuid' ? 'uuid' : 'text');
                }
            } elseif ($this->driver === 'mysql') {
                $rows = $this->source->select(
                    'SELECT COLUMN_NAME AS name, DATA_TYPE AS type FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$table]
                );
                $numberTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'year'];
                foreach ($rows as $row) {
                    $row = array_change_key_case((array) $row, CASE_LOWER);
                    $kinds[$row['name']] = in_array(strtolower($row['type']), $numberTypes, true) ? 'number' : 'text';
                }
            } else {
                foreach ($this->source->select('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')') as $row) {
                    $row = (array) $row;
                    // SQLite's own affinity rules: a declared type naming INT, REAL, FLOA, DOUB, NUM or DEC.
                    $kinds[$row['name']] = preg_match('/INT|REAL|FLOA|DOUB|NUM|DEC/i', (string) $row['type']) ? 'number' : 'text';
                }
            }
        } catch (\Throwable $e) {
            Logger::info("Could not read key column types for `$table`; ordering keys as text: " . $e->getMessage());
        }
        return array_intersect_key($kinds, array_flip($key));
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
