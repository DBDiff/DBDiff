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

        return $diffSequence;
    }

    public function getOldNewDiff($table, $key) {
        $diffSequence = [];

        $keyCols = implode(',', $key);
        $db1 = $this->source->getDatabaseName();
        $db2 = $this->target->getDatabaseName();
        $result = $this->source->select("SELECT * FROM (
                SELECT *,'source' AS _connection FROM {$db1}.{$table}
                UNION ALL
                SELECT *,'target' AS _connection FROM {$db2}.{$table}
            ) tbl
            GROUP BY $keyCols
            HAVING count(*) = 1;");

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

        $columns1 = $this->manager->getColumns('source', $table);
        $columns2 = $this->manager->getColumns('target', $table);
        
        $wrap = function($arr) {
            return array_map(function($el) {
                return "`$el`";
            }, $arr);
        };

        $columns1 = implode(',', $wrap($columns1));
        $columns2 = implode(',', $wrap($columns2));
        
        $keyCols = implode(' AND ', array_map(function($el) {
            return "t1.{$el} = t2.{$el}";
        }, $key));

        $this->source->setFetchMode(\PDO::FETCH_NAMED);
        $result = $this->source->select("SELECT * FROM
            (SELECT $columns1, MD5(concat($columns1)) AS hash FROM {$db1}.{$table}) t1       
            INNER JOIN 
            (SELECT $columns2, MD5(concat($columns2)) AS hash FROM {$db2}.{$table}) t2        
            ON $keyCols AND t1.hash != t2.hash;");
        $this->source->setFetchMode(\PDO::FETCH_ASSOC);
        
        foreach ($result as $row) {
            $diff = [];
            $keys = [];
            $row = array_except($row, 'hash');
            foreach ($row as $k => $value) {
                if (in_array($k, $key)) {
                    $keys[$k] = $value[1];
                }
                $diff[$k] = new \Diff\DiffOp\DiffOpChange($value[1], $value[0]);
            }
            $diffSequence[] = new UpdateData($table, [
                'keys' => $keys,
                'diff' => $diff
            ]);
        }

        return $diffSequence;
    }


}
