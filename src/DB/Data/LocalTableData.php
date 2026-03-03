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

        $diffSequence1 = $this->getOldNewDiff($table, $key);
        $diffSequence2 = $this->getChangeDiff($table, $key);

        return array_merge($diffSequence1, $diffSequence2);
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
            // NULL-safe inequality: "a IS NOT b" is the SQLite idiom.
            $changeConds = implode(
                ' OR ',
                array_map(fn($el) => "CAST(\"a\".\"$el\" AS TEXT) IS NOT CAST(\"b\".\"$el\" AS TEXT)", $commonCols)
            );
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
    // PostgreSQL does not support cross-database queries, so we cannot JOIN
    // across two databases in a single SQL statement.  Instead we fetch all
    // rows from each database separately over their respective connections
    // and perform the diff in PHP.

    private function getDiffPgsql(string $table, array $key): array
    {
        $params   = ParamsFactory::get();
        $columns1 = $this->manager->getColumns('source', $table);
        $columns2 = $this->manager->getColumns('target', $table);

        // Quote identifiers for PostgreSQL
        $quotedCols1 = implode(', ', array_map(fn($c) => "\"$c\"", $columns1));
        $quotedCols2 = implode(', ', array_map(fn($c) => "\"$c\"", $columns2));

        // Fetch all rows, keyed by primary-key composite string for fast lookup
        $srcRows = $this->fetchIndexed($this->source, $table, $quotedCols1, $key);
        $tgtRows = $this->fetchIndexed($this->target, $table, $quotedCols2, $key);

        $commonCols = array_values(array_intersect($columns1, $columns2));
        if (isset($params->fieldsToIgnore[$table])) {
            $commonCols = array_values(array_diff($commonCols, $params->fieldsToIgnore[$table]));
        }

        return array_merge(
            $this->buildPgsqlInserts($table, $key, $srcRows, $tgtRows),
            $this->buildPgsqlDeletes($table, $key, $srcRows, $tgtRows),
            empty($commonCols) ? [] : $this->buildPgsqlUpdates($table, $key, $commonCols, $srcRows, $tgtRows)
        );
    }

    private function buildPgsqlInserts(string $table, array $key, array $srcRows, array $tgtRows): array
    {
        $result = [];
        foreach ($srcRows as $pk => $row) {
            if (!array_key_exists($pk, $tgtRows)) {
                $result[] = new InsertData($table, [
                    'keys' => Arr::only($row, $key),
                    'diff' => new \Diff\DiffOp\DiffOpAdd(Arr::except($row, '_connection')),
                ]);
            }
        }
        return $result;
    }

    private function buildPgsqlDeletes(string $table, array $key, array $srcRows, array $tgtRows): array
    {
        $result = [];
        foreach ($tgtRows as $pk => $row) {
            if (!array_key_exists($pk, $srcRows)) {
                $result[] = new DeleteData($table, [
                    'keys' => Arr::only($row, $key),
                    'diff' => new \Diff\DiffOp\DiffOpRemove(Arr::except($row, '_connection')),
                ]);
            }
        }
        return $result;
    }

    private function buildPgsqlUpdates(string $table, array $key, array $commonCols, array $srcRows, array $tgtRows): array
    {
        $result = [];
        foreach ($srcRows as $pk => $srcRow) {
            if (!array_key_exists($pk, $tgtRows)) {
                continue;
            }
            $update = $this->buildPgsqlUpdateForRow($table, $key, $commonCols, $srcRow, $tgtRows[$pk]);
            if ($update !== null) {
                $result[] = $update;
            }
        }
        return $result;
    }

    private function buildPgsqlUpdateForRow(string $table, array $key, array $commonCols, array $srcRow, array $tgtRow): ?UpdateData
    {
        $diff = [];
        $keys = [];
        foreach ($commonCols as $col) {
            if (in_array($col, $key)) {
                $keys[$col] = $srcRow[$col];
            }
            $sv = (string) ($srcRow[$col] ?? '');
            $tv = (string) ($tgtRow[$col] ?? '');
            if ($sv !== $tv) {
                $diff[$col] = new \Diff\DiffOp\DiffOpChange($tgtRow[$col], $srcRow[$col]);
            }
        }
        if (empty($diff)) {
            return null;
        }
        foreach ($key as $k) {
            if (!isset($keys[$k])) {
                $keys[$k] = $srcRow[$k];
            }
        }
        return new UpdateData($table, ['keys' => $keys, 'diff' => $diff]);
    }

    /**
     * Fetch all rows from a table over the given connection and index them
     * by a composite primary-key string for O(1) lookup.
     *
     * @param  \Illuminate\Database\Connection $conn
     * @param  string   $table      Table name (unquoted)
     * @param  string   $selectExpr Already-quoted SELECT column list
     * @param  string[] $key        Primary-key column names
     * @return array<string, array>
     */
    private function fetchIndexed($conn, string $table, string $selectExpr, array $key): array
    {
        $rows   = $conn->select("SELECT $selectExpr FROM \"$table\"");
        $indexed = [];
        foreach ($rows as $row) {
            // Laravel returns stdClass objects when using select(); normalise to array.
            $row = is_array($row) ? $row : (array) $row;
            $pkParts = array_map(fn($k) => (string) ($row[$k] ?? ''), $key);
            $pkStr   = implode("\x00", $pkParts);
            $indexed[$pkStr] = $row;
        }
        return $indexed;
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
