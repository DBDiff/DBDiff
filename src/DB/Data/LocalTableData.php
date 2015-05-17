<?php namespace DBDiff\DB\Data;

use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Diff\DeleteData;
use DBDiff\Exceptions\DataException;
use DBDiff\Logger;


class LocalTableData {

    function __construct($manager) {
        $this->manager = $manager;
        $this->source = $this->manager->getDB('source');
        $this->target = $this->manager->getDB('target');
    }

    public function getDiff($table, $key) {
        Logger::info("Now calculating data diff for table `$table`");
        $diffSequence1 = $this->getOldNewDiff($table, $key);
        $diffSequence2 = $this->getChangeDiff($table, $key);
        $diffSequence = array_merge($diffSequence1, $diffSequence2);

        return $diffSequence;
    }

    public function getOldNewDiff($table, $key) {
        $diffSequence = [];

        $db1 = $this->source->getDatabaseName();
        $db2 = $this->target->getDatabaseName();

        $columns = $this->manager->getColumns('source', $table);
        
        $wrapConvert = function($arr, $p) {
            return array_map(function($el) use ($p) {
                return "CONVERT(`{$p}`.`{$el}` USING utf8)";
            }, $arr);
        };

        $columnsAUtf = implode(',', $wrapConvert($columns, 'a'));
        $columnsBUtf = implode(',', $wrapConvert($columns, 'b'));

        $keyCols = implode(' AND ', array_map(function($el) {
            return "`a`.`{$el}` = `b`.`{$el}`";
        }, $key));

        $keyNull = function($arr, $p) {
            return array_map(function($el) use ($p) {
                return "`{$p}`.`{$el}` IS NULL";
            }, $arr);
        };
        $keyNulls1 = implode(' AND ', $keyNull($key, 'a'));
        $keyNulls2 = implode(' AND ', $keyNull($key, 'b'));

        $this->source->setFetchMode(\PDO::FETCH_NAMED);
        $result = $this->source->select(
           "SELECT $columnsAUtf,'source' AS _connection FROM {$db1}.{$table} as a
            LEFT JOIN {$db2}.{$table} as b ON $keyCols WHERE $keyNulls2
            UNION ALL
            SELECT $columnsBUtf,'target' AS _connection FROM {$db2}.{$table} as b
            LEFT JOIN {$db1}.{$table} as a ON $keyCols WHERE $keyNulls1
        ");
        $this->source->setFetchMode(\PDO::FETCH_ASSOC);

        foreach ($result as $row) {
            if ($row['_connection'] == 'source') {
                $diffSequence[] = new InsertData($table, [
                    'keys' => array_only($row, $key),
                    'diff' => new \Diff\DiffOp\DiffOpAdd(array_except($row, '_connection'))
                ]);
            } else if ($row['_connection'] == 'target') {
                $diffSequence[] = new DeleteData($table, [
                    'keys' => array_only($row, $key),
                    'diff' => new \Diff\DiffOp\DiffOpRemove(array_except($row, '_connection'))
                ]);
            }
        }
        return $diffSequence;
    }

    public function getChangeDiff($table, $key) {
        $diffSequence = [];

        $db1 = $this->source->getDatabaseName();
        $db2 = $this->target->getDatabaseName();

        $columns = $this->manager->getColumns('source', $table);
        
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

        $columnsAas = implode(',', $wrapAs($columns, 'a', 's_'));
        $columnsA   = implode(',', $wrapCast($columns, 'a'));
        $columnsBas = implode(',', $wrapAs($columns, 'b', 't_'));
        $columnsB   = implode(',', $wrapCast($columns, 'b'));
        
        $keyCols = implode(' AND ', array_map(function($el) {
            return "a.{$el} = b.{$el}";
        }, $key));

        $this->source->setFetchMode(\PDO::FETCH_NAMED);
        $result = $this->source->select(
           "SELECT * FROM (
                SELECT $columnsAas, $columnsBas, MD5(concat($columnsA)) AS hash1,
                MD5(concat($columnsB)) AS hash2 FROM {$db1}.{$table} as a 
                INNER JOIN {$db2}.{$table} as b  
                ON $keyCols
            ) t WHERE hash1 <> hash2");
        $this->source->setFetchMode(\PDO::FETCH_ASSOC);
        
        foreach ($result as $row) {
            $diff = []; $keys = [];
            foreach ($row as $k => $value) {
                if (starts_with($k, 's_')) {
                    $theKey = substr($k, 2);
                    $targetKey = 't_'.$theKey;
                    $sourceValue = $value;
                    $targetValue = $row[$targetKey];
                    if (in_array($theKey, $key)) $keys[$theKey] = $value;
                    if ($sourceValue != $targetValue) {
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

}
