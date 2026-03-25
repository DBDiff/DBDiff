<?php namespace DBDiff\DB\Data;

use DBDiff\Params\ParamsFactory;
use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Diff\DeleteData;
use DBDiff\Exceptions\DataException;
use DBDiff\Logger;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;


class LocalTableData {

    private const SQL_AND = ' AND ';

    function __construct($manager) {
        $this->manager = $manager;
        $this->source  = $this->manager->getDB('source');
        $this->target  = $this->manager->getDB('target');
        $this->driver  = $this->manager->getDriver();
    }

    public function getDiff($table, $key) {
        Logger::info("Now calculating data diff for table `$table`");

        if ($this->driver === 'sqlite') {
            return $this->getDiffSQLite($table, $key);
        }

        if ($this->driver === 'pgsql') {
            return $this->getDiffPgsql($table, $key);
        }

        // MySQL: CHECKSUM TABLE pre-scan to skip identical tables.
        if ($this->skipIfIdentical($table)) {
            Logger::info("Table `$table` is identical (CHECKSUM match) — skipping data diff");
            return [];
        }

        $diffSequence1 = $this->getOldNewDiff($table, $key);
        $diffSequence2 = $this->getChangeDiff($table, $key);

        return array_merge($diffSequence1, $diffSequence2);
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
                "CHECKSUM TABLE `$db1`.`$table`, `$db2`.`$table`"
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
     * (CONVERT … USING utf8, CAST … AS CHAR CHARACTER SET utf8, SHA2) are
     * replaced with SQLite-compatible equivalents.
     */
    private function getDiffSQLite(string $table, array $key): array
    {
        $db2 = $this->target->getDatabaseName(); // absolute file path

        // Attach target file to source connection under a unique alias.
        $alias = '_dbdiff_target';
        $this->source->unprepared("ATTACH DATABASE '$db2' AS \"$alias\"");

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
        $columns1 = $this->manager->getColumns('source', $table);
        $columns2 = $this->manager->getColumns('target', $table);

        $keyCols  = implode(self::SQL_AND, array_map(
            fn($el) => "\"a\".\"$el\" = \"b\".\"$el\"",
            $key
        ));
        $keyNullsSrc = implode(self::SQL_AND, array_map(fn($el) => "\"a\".\"$el\" IS NULL", $key));
        $keyNullsTgt = implode(self::SQL_AND, array_map(fn($el) => "\"b\".\"$el\" IS NULL", $key));

        // ── Rows only in source (→ INSERT in UP) ─────────────────────────
        $colsA   = implode(',', array_map(fn($el) => "\"a\".\"$el\" AS \"$el\"", $columns1));
        $result1 = $this->source->select(
            "SELECT $colsA FROM \"main\".\"$table\" AS a
             LEFT JOIN \"$alias\".\"$table\" AS b ON $keyCols
             WHERE $keyNullsTgt"
        );

        // ── Rows only in target (→ DELETE in UP) ─────────────────────────
        $colsB   = implode(',', array_map(fn($el) => "\"b\".\"$el\" AS \"$el\"", $columns2));
        $result2 = $this->source->select(
            "SELECT $colsB FROM \"$alias\".\"$table\" AS b
             LEFT JOIN \"main\".\"$table\" AS a ON $keyCols
             WHERE $keyNullsSrc"
        );

        // ── Changed rows (→ UPDATE in UP) ─────────────────────────────────
        $params     = ParamsFactory::get();
        $commonCols = array_values(array_intersect($columns1, $columns2));
        if (isset($params->fieldsToIgnore[$table])) {
            $commonCols = array_values(array_diff($commonCols, $params->fieldsToIgnore[$table]));
        }

        $result3 = [];
        if (!empty($commonCols)) {
            $changeConds = $this->buildSQLiteChangeConds($commonCols);
            $allCols = implode(',', array_merge(
                array_map(fn($el) => "\"a\".\"$el\" AS \"s_$el\"", $commonCols),
                array_map(fn($el) => "\"b\".\"$el\" AS \"t_$el\"", $commonCols)
            ));
            $result3 = $this->source->select(
                "SELECT $allCols FROM \"main\".\"$table\" AS a
                 INNER JOIN \"$alias\".\"$table\" AS b ON $keyCols
                 WHERE $changeConds"
            );
        }

        return $this->buildDiffSequence($table, $result1, $result2, $result3, $key);
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
            array_map(fn($el) => "CAST(\"a\".\"$el\" AS TEXT) IS NOT CAST(\"b\".\"$el\" AS TEXT)", $commonCols)
        );
    }

    /**
     * Build a sha3() hash expression over the given columns for a table alias.
     * Example: sha3(COALESCE(CAST("a"."col1" AS TEXT), '') || X'00' || ..., 256)
     */
    private function buildSQLiteSha3Expr(array $columns, string $alias): string
    {
        $parts = array_map(
            fn($c) => "COALESCE(CAST(\"$alias\".\"$c\" AS TEXT), '')",
            $columns
        );
        return "sha3(" . implode(" || X'00' || ", $parts) . ", 256)";
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
    private function buildDiffSequence(string $table, array $result1, array $result2, array $result3, array $key): array
    {
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
            $update = $this->buildUpdateFromRow($table, $row, $key);
            if ($update !== null) {
                $diffSequence[] = $update;
            }
        }

        return $diffSequence;
    }

    private function buildUpdateFromRow(string $table, array $row, array $key): ?UpdateData
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

    private function getDiffPgsql(string $table, array $key): array
    {
        $params   = ParamsFactory::get();
        $columns1 = $this->manager->getColumns('source', $table);
        $columns2 = $this->manager->getColumns('target', $table);

        $fieldsToIgnore = $params->fieldsToIgnore[$table] ?? [];

        $merge = new StreamingMergeDiff($this->source, $this->target, 'pgsql');
        return $merge->getDiff($table, $key, $columns1, $columns2, $fieldsToIgnore);
    }

    // ── MySQL / PostgreSQL path ───────────────────────────────────────────

    public function getOldNewDiff($table, $key) {
        $diffSequence = [];

        $db1 = $this->source->getDatabaseName();
        $db2 = $this->target->getDatabaseName();

        $columns1 = $this->manager->getColumns('source', $table);
        $columns2 = $this->manager->getColumns('target', $table);

        $wrapConvert = function($arr, $p) {
            return array_map(function($el) use ($p) {
                return "CONVERT(`{$p}`.`{$el}` USING utf8) as `{$el}`";
            }, $arr);
        };

        $columnsAUtf = implode(',', $wrapConvert($columns1, 'a'));
        $columnsBUtf = implode(',', $wrapConvert($columns2, 'b'));

        $keyCols = implode(self::SQL_AND, array_map(function($el) {
            return "`a`.`{$el}` = `b`.`{$el}`";
        }, $key));

        $keyNull = function($arr, $p) {
            return array_map(function($el) use ($p) {
                return "`{$p}`.`{$el}` IS NULL";
            }, $arr);
        };
        $keyNulls1 = implode(self::SQL_AND, $keyNull($key, 'a'));
        $keyNulls2 = implode(self::SQL_AND, $keyNull($key, 'b'));

        $this->setFetchMode(\PDO::FETCH_NAMED);
        $result1 = $this->source->select(
           "SELECT $columnsAUtf FROM {$db1}.{$table} as a
            LEFT JOIN {$db2}.{$table} as b ON $keyCols WHERE $keyNulls2
        ");
        $result2 = $this->source->select(
           "SELECT $columnsBUtf FROM {$db2}.{$table} as b
            LEFT JOIN {$db1}.{$table} as a ON $keyCols WHERE $keyNulls1
        ");
        $this->setFetchMode(\PDO::FETCH_ASSOC);

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

        $columns1 = $this->manager->getColumns('source', $table);
        $columns2 = $this->manager->getColumns('target', $table);

        if (isset($params->fieldsToIgnore[$table])) {
            $columns1 = array_diff($columns1, $params->fieldsToIgnore[$table]);
            $columns2 = array_diff($columns2, $params->fieldsToIgnore[$table]);
        }
        
        $wrapAs = function($arr, $p1, $p2) {
            return array_map(function($el) use ($p1, $p2) {
                return "`{$p1}`.`{$el}` as `{$p2}{$el}`";
            }, $arr);
        };

        $wrapCast = function($arr, $p) {
            return array_map(function($el) use ($p) {
                return "CAST(`{$p}`.`{$el}` AS CHAR CHARACTER SET utf8)";
            }, $arr);
        };

        $columnsAas = implode(',', $wrapAs($columns1, 'a', 's_'));
        $columnsA   = implode(',', $wrapCast($columns1, 'a'));
        $columnsBas = implode(',', $wrapAs($columns2, 'b', 't_'));
        $columnsB   = implode(',', $wrapCast($columns2, 'b'));
        
        $keyCols = implode(self::SQL_AND, array_map(function($el) {
            return "a.{$el} = b.{$el}";
        }, $key));

        $this->setFetchMode(\PDO::FETCH_NAMED);
        $result = $this->source->select(
           "SELECT * FROM (
                SELECT $columnsAas, $columnsBas, SHA2(concat($columnsA), 256) AS hash1,
                SHA2(concat($columnsB), 256) AS hash2 FROM {$db1}.{$table} as a 
                INNER JOIN {$db2}.{$table} as b  
                ON $keyCols
            ) t WHERE hash1 <> hash2");
        $this->setFetchMode(\PDO::FETCH_ASSOC);
        
        foreach ($result as $row) {
            $diff = []; $keys = [];
            foreach ($row as $k => $value) {
                if (Str::startsWith($k, 's_')) {
                    $theKey = substr($k, 2);
                    $targetKey = 't_'.$theKey;
                    $sourceValue = $value;
                    
                    if (in_array($theKey, $key)) $keys[$theKey] = $value;
                    
                    if (isset($row[$targetKey])) {
                        $targetValue = $row[$targetKey];
                        if ($sourceValue != $targetValue) {
                            $diff[$theKey] = new \Diff\DiffOp\DiffOpChange($targetValue, $sourceValue);
                        }
                    } else {
                        $diff[$theKey] = new \Diff\DiffOp\DiffOpChange(NULL, $sourceValue);
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

    private function setFetchMode($fetchMode = \PDO::FETCH_ASSOC)
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, function ($event) use ($fetchMode) {
            $event->statement->setFetchMode($fetchMode);
        });

        $this->source->setEventDispatcher($dispatcher);
    }

}
