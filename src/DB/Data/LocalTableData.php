<?php namespace DBDiff\DB\Data;

use DBDiff\Params\ParamsFactory;
use DBDiff\Params\TableFilter;
use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Diff\DeleteData;
use DBDiff\DB\Data\BinaryValue;
use DBDiff\Exceptions\DataException;
use DBDiff\Logger;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;


class LocalTableData {

    private const SQL_AND = ' AND ';

    /** @var \DBDiff\DB\DBManager */
    protected $manager;
    /** @var \Illuminate\Database\Connection */
    protected $source;
    /** @var \Illuminate\Database\Connection */
    protected $target;
    protected string $driver;

    function __construct($manager) {
        $this->manager = $manager;
        $this->source  = $this->manager->getDB('source');
        $this->target  = $this->manager->getDB('target');
        $this->driver  = $this->manager->getDriver();
    }

    /** @param string[] $renamed source columns the schema diff renames (PostgreSQL only renames) */
    public function getDiff($table, $key, array $renamed = []) {
        Logger::info("Now calculating data diff for table `$table`");

        if ($this->driver === 'sqlite') {
            return $this->getDiffSQLite($table, $key);
        }

        if ($this->driver === 'pgsql') {
            return $this->getDiffPgsql($table, $key, $renamed);
        }

        return $this->getDiffMySQL($table, $key);
    }

    private function getDiffMySQL($table, $key): array
    {
        if ($this->skipIfIdentical($table)) {
            Logger::info("Table `$table` is identical (CHECKSUM match) — skipping data diff");
            return [];
        }

        return array_merge($this->getOldNewDiff($table, $key), $this->getChangeDiff($table, $key));
    }

    /**
     * MySQL CHECKSUM TABLE pre-scan.
     *
     * Issues CHECKSUM TABLE for both databases and compares.  If both
     * checksums match and are non-null, the table data is identical and
     * the full diff can be skipped.  This is only useful for same-server
     * comparisons (which is always the case in LocalTableData).
     */
    private function skipIfIdentical(string $table): bool
    {
        $db1 = $this->source->getDatabaseName();
        $db2 = $this->target->getDatabaseName();
        try {
            $result = $this->source->select(
                "CHECKSUM TABLE " . self::backtick($db1) . "." . self::backtick($table) . ", " . self::backtick($db2) . "." . self::backtick($table)
            );
            if (count($result) === 2) {
                $cs1 = $result[0]['Checksum'] ?? null;
                $cs2 = $result[1]['Checksum'] ?? null;
                return $cs1 !== null && $cs2 !== null && $cs1 === $cs2;
            }
        } catch (\Throwable $e) {
            // CHECKSUM TABLE may not be supported (e.g. certain storage engines).
            // Fall through to the full diff.
            Logger::info("CHECKSUM TABLE not available for `$table`: " . $e->getMessage());
        }
        return false;
    }

    // ── SQLite path ───────────────────────────────────────────────────────

    /**
     * SQLite data diff via ATTACH DATABASE.
     *
     * SQLite only supports cross-database queries when the second file is
     * attached to the same connection.  MySQL-specific functions
     * (CONVERT … USING utf8mb4, CAST … AS CHAR CHARACTER SET utf8mb4, SHA2) are
     * replaced with SQLite-compatible equivalents.
     */
    private function getDiffSQLite(string $table, array $key): array
    {
        $db2 = $this->target->getDatabaseName(); // absolute file path

        // Attach target file to source connection under a unique alias.
        $alias = '_dbdiff_target';
        $this->source->unprepared("ATTACH DATABASE '" . str_replace("'", "''", $db2) . "' AS \"$alias\"");

        try {
            return $this->runSQLiteDiff($table, $key, $alias);
        } finally {
            try {
                $this->source->unprepared("DETACH DATABASE \"$alias\"");
            } catch (\Throwable $e) {
                // Ignore detach errors (e.g. connection already closed).
            }
        }
    }

    private function runSQLiteDiff(string $table, array $key, string $alias): array
    {
        $columns1 = $this->manager->getDataColumns('source', $table);
        $columns2 = $this->manager->getDataColumns('target', $table);

        $keyCols  = implode(self::SQL_AND, array_map(
            fn($el) => "\"a\"." . self::q($el) . " = \"b\"." . self::q($el),
            $key
        ));
        $keyNullsSrc = implode(self::SQL_AND, array_map(fn($el) => "\"a\"." . self::q($el) . " IS NULL", $key));
        $keyNullsTgt = implode(self::SQL_AND, array_map(fn($el) => "\"b\"." . self::q($el) . " IS NULL", $key));

        // A BLOB column is read as hex, so a value is written back as X'…'
        // whether or not its bytes happen to be UTF-8 text.
        $blob = array_values(array_unique(array_merge(
            $this->manager->getBinaryColumns('source', $table),
            $this->manager->getBinaryColumns('target', $table),
        )));
        $col = fn(string $side, string $el, string $as) => in_array($el, $blob, true)
            ? "CASE WHEN \"$side\"." . self::q($el) . " IS NULL THEN NULL ELSE hex(\"$side\"." . self::q($el) . ") END AS " . self::q($as)
            : "\"$side\"." . self::q($el) . " AS " . self::q($as);
        $unhex = fn(array $rows, string $prefix = '') => array_map(function ($row) use ($blob, $prefix) {
            foreach ($blob as $el) {
                foreach ($prefix === '' ? [$el] : ["s_$el", "t_$el"] as $k) {
                    if (isset($row[$k])) {
                        $row[$k] = new BinaryValue((string) $row[$k]);
                    }
                }
            }
            return $row;
        }, $rows);

        // ── Rows only in source (→ INSERT in UP) ─────────────────────────
        $colsA   = implode(',', array_map(fn($el) => $col('a', $el, $el), $columns1));
        $result1 = $this->source->select(
            "SELECT $colsA FROM \"main\"." . self::q($table) . " AS a
             LEFT JOIN \"$alias\"." . self::q($table) . " AS b ON $keyCols
             WHERE $keyNullsTgt"
        );
        $result1 = $unhex($result1);

        // ── Rows only in target (→ DELETE in UP) ─────────────────────────
        $colsB   = implode(',', array_map(fn($el) => $col('b', $el, $el), $columns2));
        $result2 = $this->source->select(
            "SELECT $colsB FROM \"$alias\"." . self::q($table) . " AS b
             LEFT JOIN \"main\"." . self::q($table) . " AS a ON $keyCols
             WHERE $keyNullsSrc"
        );
        $result2 = $unhex($result2);

        // ── Changed rows (→ UPDATE in UP) ─────────────────────────────────
        $params     = ParamsFactory::get();
        $commonCols = array_values(array_intersect($columns1, $columns2));
        $ignoredFields = TableFilter::getFieldsToIgnore($table, $params);
        if (!empty($ignoredFields)) {
            $commonCols = array_values(array_diff($commonCols, $ignoredFields));
        }

        // A column only the source has is added by the schema diff, so every
        // row both sides have is set to the source's value — see
        // StreamingMergeDiff::getDiff.
        $sourceOnly = array_values(array_diff($columns1, $columns2, $ignoredFields));

        $result3 = [];
        if (!empty($commonCols) || !empty($sourceOnly)) {
            $where = $sourceOnly === [] ? 'WHERE ' . $this->buildSQLiteChangeConds($commonCols) : '';
            $allCols = implode(',', array_merge(
                array_map(fn($el) => $col('a', $el, "s_$el"), array_merge($commonCols, $sourceOnly)),
                array_map(fn($el) => $col('b', $el, "t_$el"), $commonCols)
            ));
            $result3 = $this->source->select(
                "SELECT $allCols FROM \"main\"." . self::q($table) . " AS a
                 INNER JOIN \"$alias\"." . self::q($table) . " AS b ON $keyCols
                 $where"
            );
            $result3 = $unhex($result3, 's_');
        }

        return $this->buildDiffSequence($table, $result1, $result2, $result3, $key, $sourceOnly);
    }

    /**
     * Build the WHERE condition for detecting changed rows in SQLite.
     *
     * If sha3() is available (bundled into many libsqlite3 builds), use a
     * single hash comparison — one condition per row rather than N per column.
     * Otherwise, fall back to the NULL-safe field-by-field IS NOT comparison.
     */
    private function buildSQLiteChangeConds(array $commonCols): string
    {
        if ($this->sqliteSha3Available()) {
            $hashA = $this->buildSQLiteSha3Expr($commonCols, 'a');
            $hashB = $this->buildSQLiteSha3Expr($commonCols, 'b');
            return "$hashA <> $hashB";
        }

        // Fallback: NULL-safe inequality per column
        return implode(
            ' OR ',
            array_map(fn($el) => "CAST(\"a\"." . self::q($el) . " AS TEXT) IS NOT CAST(\"b\"." . self::q($el) . " AS TEXT)", $commonCols)
        );
    }

    /**
     * Build a sha3() hash expression over the given columns for a table alias.
     * Example: sha3(CASE WHEN "a"."col1" IS NULL THEN 'N' ELSE 'V' || CAST("a"."col1" AS TEXT) END || X'1f' || ..., 256)
     */
    private function buildSQLiteSha3Expr(array $columns, string $alias): string
    {
        $parts = array_map(
            // N for NULL, V and the text otherwise: COALESCE to '' made NULL
            // and an empty string hash alike — see StreamingMergeDiff.
            fn($c) => "CASE WHEN \"$alias\"." . self::q($c) . " IS NULL THEN 'N' ELSE 'V' || CAST(\"$alias\"." . self::q($c) . " AS TEXT) END",
            $columns
        );
        return "sha3(" . implode(" || X'1f' || ", $parts) . ", 256)";
    }

    /**
     * Probe whether sha3() is available in this SQLite build.
     * Caches the result per-process.
     */
    private function sqliteSha3Available(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        try {
            $this->source->select("SELECT sha3('test', 256) AS h");
            $available = true;
        } catch (\Throwable $e) {
            $available = false;
        }
        return $available;
    }

    /** Turn the three raw result sets into Diff objects. */
    private function buildDiffSequence(string $table, array $result1, array $result2, array $result3, array $key, array $sourceOnly = []): array
    {
        // Apply rowsToIgnore filtering
        $params = ParamsFactory::get();
        $rowRules = TableFilter::getRowIgnoreRules($table, $params);
        if (!empty($rowRules)) {
            $result1 = TableFilter::filterRows($result1, $rowRules);
            $result2 = TableFilter::filterRows($result2, $rowRules);
        }

        $diffSequence = [];

        foreach ($result1 as $row) {
            $diffSequence[] = new InsertData($table, [
                'keys' => Arr::only($row, $key),
                'diff' => new \Diff\DiffOp\DiffOpAdd(Arr::except($row, '_connection')),
            ]);
        }
        foreach ($result2 as $row) {
            $diffSequence[] = new DeleteData($table, [
                'keys' => Arr::only($row, $key),
                'diff' => new \Diff\DiffOp\DiffOpRemove(Arr::except($row, '_connection')),
            ]);
        }
        foreach ($result3 as $row) {
            $update = $this->buildUpdateFromRow($table, $row, $key, $sourceOnly);
            if ($update !== null) {
                $diffSequence[] = $update;
            }
        }

        return $diffSequence;
    }

    private function buildUpdateFromRow(string $table, array $row, array $key, array $sourceOnly = []): ?UpdateData
    {
        $diff = [];
        $keys = [];
        foreach ($row as $k => $value) {
            if (!Str::startsWith($k, 's_')) {
                continue;
            }
            $theKey      = substr($k, 2);
            $targetKey   = 't_' . $theKey;
            $sourceValue = $value;
            if (in_array($theKey, $key)) {
                $keys[$theKey] = $value;
            }
            // Set by the UP; the DOWN drops the column, so does not set it.
            if (in_array($theKey, $sourceOnly, true)) {
                $diff[$theKey] = new \Diff\DiffOp\DiffOpAdd($sourceValue);
                continue;
            }
            $targetValue = $row[$targetKey] ?? null;
            if ((string) $sourceValue !== (string) $targetValue) {
                $diff[$theKey] = new \Diff\DiffOp\DiffOpChange($targetValue, $sourceValue);
            }
        }
        return empty($diff) ? null : new UpdateData($table, ['keys' => $keys, 'diff' => $diff]);
    }

    // ── PostgreSQL path ───────────────────────────────────────────────────
    //
    // PostgreSQL does not support cross-database queries.  Instead of loading
    // both full tables into PHP memory (the old approach), we use a streaming
    // sorted-merge with md5() row hashing:
    //
    // Phase 1: Stream PK + md5(row::text) from both databases in PK order.
    //          A merge-sort pass identifies inserts, deletes, and changed PKs
    //          without transferring full row data.
    //
    // Phase 2: Batch-fetch full rows only for the differing PKs.
    //
    // This scales to millions of rows and transfers only O(pk + 32 byte hash)
    // per row in Phase 1.

    private function getDiffPgsql(string $table, array $key, array $renamed = []): array
    {
        $params   = ParamsFactory::get();
        $columns1 = $this->manager->getDataColumns('source', $table);
        $columns2 = $this->manager->getDataColumns('target', $table);

        $fieldsToIgnore = TableFilter::getFieldsToIgnore($table, $params);

        $merge = new StreamingMergeDiff($this->source, $this->target, 'pgsql');
        return $merge->getDiff($table, $key, $columns1, $columns2, $fieldsToIgnore, $renamed);
    }

    // ── MySQL / PostgreSQL path ───────────────────────────────────────────

    public function getOldNewDiff($table, $key) {
        $diffSequence = [];

        $db1 = $this->source->getDatabaseName();
        $db2 = $this->target->getDatabaseName();

        $columns1 = $this->manager->getDataColumns('source', $table);
        $columns2 = $this->manager->getDataColumns('target', $table);

        $binaryCols = $this->manager->getBinaryColumns('source', $table);

        $wrapConvert = function($arr, $p) use ($binaryCols) {
            return array_map(function($el) use ($p, $binaryCols) {
                if (in_array($el, $binaryCols)) {
                    return "HEX({$this->bt($p)}.{$this->bt($el)}) as {$this->bt($el)}";
                }
                return "CONVERT({$this->bt($p)}.{$this->bt($el)} USING utf8mb4) as {$this->bt($el)}";
            }, $arr);
        };

        $columnsAUtf = implode(',', $wrapConvert($columns1, 'a'));
        $columnsBUtf = implode(',', $wrapConvert($columns2, 'b'));

        $keyCols = implode(self::SQL_AND, array_map(function($el) {
            return "`a`.{$this->bt($el)} = `b`.{$this->bt($el)}";
        }, $key));

        $keyNull = function($arr, $p) {
            return array_map(function($el) use ($p) {
                return "{$this->bt($p)}.{$this->bt($el)} IS NULL";
            }, $arr);
        };
        $keyNulls1 = implode(self::SQL_AND, $keyNull($key, 'a'));
        $keyNulls2 = implode(self::SQL_AND, $keyNull($key, 'b'));

        $this->setFetchMode(\PDO::FETCH_NAMED);
        $result1 = $this->source->select(
           "SELECT $columnsAUtf FROM {$this->bt($db1)}.{$this->bt($table)} as a
            LEFT JOIN {$this->bt($db2)}.{$this->bt($table)} as b ON $keyCols WHERE $keyNulls2
        ");
        $result2 = $this->source->select(
           "SELECT $columnsBUtf FROM {$this->bt($db2)}.{$this->bt($table)} as b
            LEFT JOIN {$this->bt($db1)}.{$this->bt($table)} as a ON $keyCols WHERE $keyNulls1
        ");
        $this->setFetchMode(\PDO::FETCH_ASSOC);

        $this->wrapBinaryValues($result1, $binaryCols);
        $this->wrapBinaryValues($result2, $binaryCols);

        // Apply rowsToIgnore filtering
        $params = ParamsFactory::get();
        $rowRules = TableFilter::getRowIgnoreRules($table, $params);
        if (!empty($rowRules)) {
            $result1 = TableFilter::filterRows($result1, $rowRules);
            $result2 = TableFilter::filterRows($result2, $rowRules);
        }

        foreach ($result1 as $row) {
            $diffSequence[] = new InsertData($table, [
                'keys' => Arr::only($row, $key),
                'diff' => new \Diff\DiffOp\DiffOpAdd(Arr::except($row, '_connection'))
            ]);
        }
        foreach ($result2 as $row) {
            $diffSequence[] = new DeleteData($table, [
                'keys' => Arr::only($row, $key),
                'diff' => new \Diff\DiffOp\DiffOpRemove(Arr::except($row, '_connection'))
            ]);
        }

        return $diffSequence;
    }

    public function getChangeDiff($table, $key) {
        $params = ParamsFactory::get();

        $diffSequence = [];

        $db1 = $this->source->getDatabaseName();
        $db2 = $this->target->getDatabaseName();

        $columns1 = $this->manager->getDataColumns('source', $table);
        $columns2 = $this->manager->getDataColumns('target', $table);

        $ignoredFields = TableFilter::getFieldsToIgnore($table, $params);
        if (!empty($ignoredFields)) {
            $columns1 = array_diff($columns1, $ignoredFields);
            $columns2 = array_diff($columns2, $ignoredFields);
        }

        $binaryCols = $this->manager->getBinaryColumns('source', $table);

        $wrapAs = function($arr, $p1, $p2) use ($binaryCols) {
            return array_map(function($el) use ($p1, $p2, $binaryCols) {
                if (in_array($el, $binaryCols)) {
                    return "HEX({$this->bt($p1)}.{$this->bt($el)}) as {$this->bt($p2 . $el)}";
                }
                return "{$this->bt($p1)}.{$this->bt($el)} as {$this->bt($p2 . $el)}";
            }, $arr);
        };

        $wrapCast = function($arr, $p) use ($binaryCols) {
            return array_map(function($el) use ($p, $binaryCols) {
                if (in_array($el, $binaryCols)) {
                    return "HEX(IFNULL({$this->bt($p)}.{$this->bt($el)}, ''))";
                }
                return "CAST(IFNULL({$this->bt($p)}.{$this->bt($el)}, '\\0') AS CHAR CHARACTER SET utf8mb4)";
            }, $arr);
        };

        // NULL-presence bitmap: distinguishes NULL from empty string
        $wrapNullCheck = function($arr, $p) {
            return array_map(function($el) use ($p) {
                return "IF({$this->bt($p)}.{$this->bt($el)} IS NULL, '1', '0')";
            }, $arr);
        };

        $columnsAas = implode(',', $wrapAs($columns1, 'a', 's_'));
        $columnsA   = implode(',', $wrapCast($columns1, 'a'));
        $columnsA0  = implode(',', $wrapNullCheck($columns1, 'a'));
        $columnsBas = implode(',', $wrapAs($columns2, 'b', 't_'));
        $columnsB   = implode(',', $wrapCast($columns2, 'b'));
        $columnsB0  = implode(',', $wrapNullCheck($columns2, 'b'));
        
        $keyCols = implode(self::SQL_AND, array_map(function($el) {
            return "`a`.{$this->bt($el)} = `b`.{$this->bt($el)}";
        }, $key));

        $this->setFetchMode(\PDO::FETCH_NAMED);
        $result = $this->source->select(
           "SELECT * FROM (
                SELECT $columnsAas, $columnsBas, SHA2(concat($columnsA), 256) AS hash1,
                SHA2(concat($columnsB), 256) AS hash2,
                CONCAT($columnsA0) AS nullmap1, CONCAT($columnsB0) AS nullmap2
                FROM {$this->bt($db1)}.{$this->bt($table)} as a
                INNER JOIN {$this->bt($db2)}.{$this->bt($table)} as b
                ON $keyCols
            ) t WHERE hash1 <> hash2 OR nullmap1 <> nullmap2");
        $this->setFetchMode(\PDO::FETCH_ASSOC);

        $this->wrapBinaryValuesChangeDiff($result, $binaryCols);

        foreach ($result as $row) {
            $diff = []; $keys = [];
            foreach ($row as $k => $value) {
                if (Str::startsWith($k, 's_')) {
                    $theKey = substr($k, 2);
                    $targetKey = 't_'.$theKey;
                    $sourceValue = $value;
                    
                    if (in_array($theKey, $key)) $keys[$theKey] = $value;

                    // A column only the source has is the UP's to add and the
                    // DOWN's to drop: the DOWN does not set it (UpdateDataSQL).
                    if (!in_array($theKey, $columns2, true)) {
                        $diff[$theKey] = new \Diff\DiffOp\DiffOpAdd($sourceValue);
                        continue;
                    }
                    $targetValue = $row[$targetKey] ?? null;
                    if ($sourceValue === null && $targetValue === null) {
                        continue;
                    }
                    if ($sourceValue === null || $targetValue === null || $sourceValue != $targetValue) {
                        $diff[$theKey] = new \Diff\DiffOp\DiffOpChange($targetValue, $sourceValue);
                    }
                }
            }
            $diffSequence[] = new UpdateData($table, [
                'keys' => $keys,
                'diff' => $diff
            ]);
        }

        return $diffSequence;
    }

    private function wrapBinaryValues(array &$rows, array $binaryCols): void
    {
        if (empty($binaryCols)) {
            return;
        }
        foreach ($rows as &$row) {
            foreach ($binaryCols as $col) {
                if (isset($row[$col]) && $row[$col] !== null) {
                    $row[$col] = new BinaryValue($row[$col]);
                }
            }
        }
    }

    private function wrapBinaryValuesChangeDiff(array &$rows, array $binaryCols): void
    {
        if (empty($binaryCols)) {
            return;
        }
        foreach ($rows as &$row) {
            foreach ($binaryCols as $col) {
                $sKey = 's_' . $col;
                $tKey = 't_' . $col;
                if (isset($row[$sKey]) && $row[$sKey] !== null) {
                    $row[$sKey] = new BinaryValue($row[$sKey]);
                }
                if (isset($row[$tKey]) && $row[$tKey] !== null) {
                    $row[$tKey] = new BinaryValue($row[$tKey]);
                }
            }
        }
    }

    private function setFetchMode($fetchMode = \PDO::FETCH_ASSOC)
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, function ($event) use ($fetchMode) {
            $event->statement->setFetchMode($fetchMode);
        });

        $this->source->setEventDispatcher($dispatcher);
    }


    /** An identifier, quoted for SQLite: a `"` in the name is doubled. */
    private static function q(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** An identifier, quoted for MySQL: a backtick in the name is doubled. */
    private static function backtick(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** backtick(), callable inside a string. */
    private function bt(string $name): string
    {
        return self::backtick($name);
    }
}
