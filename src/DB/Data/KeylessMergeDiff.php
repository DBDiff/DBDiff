<?php namespace DBDiff\DB\Data;

use DBDiff\Diff\DeleteData;
use DBDiff\Diff\InsertData;
use DBDiff\Logger;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;
use DBDiff\DB\Support\PostgresSnapshot;
use Illuminate\Database\Connection;

/**
 * The data diff of a table with no key, by the same streaming merge as a
 * keyed table but matched on a hash of the whole row.
 */
class KeylessMergeDiff extends StreamingMergeDiff
{
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

        $extra = ($textMatch = $this->textMatchColumns($table, $columns)) ? ['textMatch' => $textMatch] : [];
        $rows  = fn(Connection $conn, array $wanted, string $name) => $wanted
            ? $this->rowsWithHash($conn, $table, $columns, $hashExpr, $wanted, $name)
            : [];

        return array_merge(
            $this->copies($table, $rows($this->source, $insert, 'keyless_source'), $insert, InsertData::class, DiffOpAdd::class, $extra),
            $this->copies($table, $rows($this->target, $delete, 'keyless_target'), $delete, DeleteData::class, DiffOpRemove::class, $extra)
        );
    }

    /**
     * `$counts[$hash]` diffs of `$class` for each row, keyless.
     *
     * @param array<string, array> $rows
     * @param array<string, int>   $counts
     */
    private function copies(string $table, array $rows, array $counts, string $class, string $op, array $extra): array
    {
        ksort($rows, SORT_STRING); // a stable order, independent of how the server returned them
        $diffs = [];
        foreach ($rows as $hash => $row) {
            for ($i = 0; $i < $counts[$hash]; $i++) {
                $diffs[] = new $class($table, ['keys' => $row, 'diff' => new $op($row), 'keyless' => true] + $extra);
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
                $rows = PostgresSnapshot::attempt($this->source, fn() => $this->source->select(
                    "SELECT a.attname AS name
                       FROM pg_attribute a
                       JOIN pg_type d ON d.oid = a.atttypid
                       JOIN pg_type t ON t.oid = CASE WHEN d.typtype = 'd' THEN d.typbasetype ELSE d.oid END
                      WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped
                        AND NOT EXISTS (SELECT 1 FROM pg_operator o
                                         WHERE o.oprname = '=' AND o.oprleft = t.oid AND o.oprright = t.oid)",
                    [$this->tableName($table)]
                ));
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
        [$src, $tgt] = $this->openStreams($sql, $this->tableName($table), 'keyless');
        try {
            return $this->mergeHashes($src, $tgt);
        } finally {
            $tgt->close();
            $src->close();
        }
    }

    /** @return array{0: array<string, int>, 1: array<string, int>} */
    private function mergeHashes(RowStream $src, RowStream $tgt): array
    {
        $insert = [];
        $delete = [];
        $s = $src->fetch();
        $t = $tgt->fetch();
        while ($s !== false || $t !== false) {
            $cmp = self::hashOrder($s, $t);
            if ($cmp < 0) {
                $insert[(string) $s['_hash']] = (int) $s['_n'];
                $s = $src->fetch();
            } elseif ($cmp > 0) {
                $delete[(string) $t['_hash']] = (int) $t['_n'];
                $t = $tgt->fetch();
            } else {
                // Both have it: whichever side has more copies gains or loses the difference.
                $extra = (int) $s['_n'] - (int) $t['_n'];
                if ($extra > 0) {
                    $insert[(string) $s['_hash']] = $extra;
                } elseif ($extra < 0) {
                    $delete[(string) $s['_hash']] = -$extra;
                }
                $s = $src->fetch();
                $t = $tgt->fetch();
            }
        }
        return [$insert, $delete];
    }

    /** Which comes first: negative the source's hash, positive the target's. An exhausted side comes last. */
    private static function hashOrder(array|false $s, array|false $t): int
    {
        if ($s === false) {
            return 1;
        }
        if ($t === false) {
            return -1;
        }
        return strcmp((string) $s['_hash'], (string) $t['_hash']);
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
        $bounded = $this->driver !== 'pgsql' || !RowEstimate::atMost($conn, $q, self::BUFFER_ROWS);
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
}
