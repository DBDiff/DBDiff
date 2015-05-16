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

        $keyCols = implode(',', $key);
        $db1 = $this->source->getDatabaseName();
        $db2 = $this->target->getDatabaseName();
        $this->source->setFetchMode(\PDO::FETCH_NAMED);
        $result = $this->source->select(
           "SELECT *,'target' AS _connection FROM {$db1}.{$table} as a
            LEFT JOIN {$db2}.{$table} as b ON a.id = b.id WHERE b.id IS NULL
            UNION ALL
            SELECT *,'source' AS _connection FROM {$db2}.{$table} as b
            LEFT JOIN {$db1}.{$table} as a ON a.id = b.id WHERE a.id IS NULL
        ");
        $this->source->setFetchMode(\PDO::FETCH_ASSOC);

        foreach ($result as $row) {
            foreach ($row as $k => &$v) { if ($k != '_connection') $v = $v[0]; }
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

        $columns1 = $this->manager->getColumns('source', $table);
        $columns2 = $this->manager->getColumns('target', $table);
        
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

        $columns1as = implode(',', $wrapAs($columns1, 'a', 's_'));
        $columns1   = implode(',', $wrapCast($columns1, 'a'));
        $columns2as = implode(',', $wrapAs($columns2, 'b', 't_'));
        $columns2   = implode(',', $wrapCast($columns2, 'b'));
        
        $keyCols = implode(' AND ', array_map(function($el) {
            return "a.{$el} = b.{$el}";
        }, $key));

        $this->source->setFetchMode(\PDO::FETCH_NAMED);
        $result = $this->source->select(
           "SELECT * FROM (
                SELECT $columns1as, $columns2as, MD5(concat($columns1)) AS hash1,
                MD5(concat($columns2)) AS hash2 FROM {$db1}.{$table} as a 
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
