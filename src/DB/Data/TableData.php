<?php namespace DBDiff\DB\Data;

use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Diff\DeleteData;
use DBDiff\Exceptions\DataException;
use DBDiff\Logger;


class TableData {

    function __construct($manager) {
        $this->manager = $manager;
        $this->source = $this->manager->getDB('source');
        $this->target = $this->manager->getDB('target');
    }

    public function getKey($connection, $table) {
        $keys = $this->{$connection}->select("show indexes from $table");
        $ukey = [];
        foreach ($keys as $key) {
            if ($key['Key_name'] === 'PRIMARY') {
                $ukey[] = $key['Column_name'];
            }
        }
        return $ukey;
    }

    public function checkKeys($table, $sourceKey, $targetKey) {
        if (empty($sourceKey) || empty($targetKey)) {
            throw new DataException("No primary key found in table `$table`");
        }
        if ($sourceKey != $targetKey) {
            throw new DataException("Unmatched primary keys in table `$table`");
        }
        return true;
    }

    public function getIterator($connection, $table) {
        return new TableIterator($this->{$connection}, $table);
    }

    public function getDataDiff($key, $table) {
        $sourceIterator = $this->getIterator('source', $table);
        $targetIterator = $this->getIterator('target', $table);
        $differ = new ArrayDiff($key, $sourceIterator, $targetIterator);
        return $differ->getDiff();
    }

    public function getNewData($table) {
        Logger::info("Now getting new data from table `$table`");
        $diffSequence = [];
        $iterator = $this->getIterator('source', $table);
        $key = $this->getKey('source', $table);
        while ($iterator->hasNext()) {
            $data = $iterator->next(ArrayDiff::SIZE);
            foreach ($data as $entry) {
                $diffSequence[] = new InsertData($table, [
                    'keys' => array_only($entry, $key),
                    'diff' => new \Diff\DiffOp\DiffOpAdd($entry)
                ]);
            }
        }
        return $diffSequence;
    }

    public function getOldData($table) {
        Logger::info("Now getting old data from table `$table`");
        $diffSequence = [];
        $iterator = $this->getIterator('target', $table);
        $key = $this->getKey('target', $table);
        while ($iterator->hasNext()) {
            $data = $iterator->next(ArrayDiff::SIZE);
            foreach ($data as $entry) {
                $diffSequence[] = new DeleteData($table, [
                    'keys' => array_only($entry, $key),
                    'diff' => new \Diff\DiffOp\DiffOpRemove($entry)
                ]);
            }
        }
        return $diffSequence;
    }

    public function getDiff($table) {
        Logger::info("Now calculating data diff for table `$table`");
        $sourceKey  = $this->getKey('source', $table);
        $targetKey  = $this->getKey('target', $table);
        $this->checkKeys($table, $sourceKey, $targetKey);
        $diffs = $this->getDataDiff($sourceKey, $table);
        $diffSequence = [];
        foreach ($diffs as $name => $diff) {
            if ($diff['diff'] instanceof \Diff\DiffOp\DiffOpRemove) {
                $diffSequence[] = new DeleteData($table, $diff);
            } else if (is_array($diff['diff'])) {
                $diffSequence[] = new UpdateData($table, $diff);
            } else if ($diff['diff'] instanceof \Diff\DiffOp\DiffOpAdd) {
                $diffSequence[] = new InsertData($table, $diff);
            }
        }
        return $diffSequence;
    }

}
