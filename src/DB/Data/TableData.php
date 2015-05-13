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
        $this->distTableData = new DistTableData($manager);
        $this->localTableData = new LocalTableData($manager);
    }

    public function getIterator($connection, $table) {
        return new TableIterator($this->{$connection}, $table);
    }

    public function getNewData($table) {
        Logger::info("Now getting new data from table `$table`");
        $diffSequence = [];
        $iterator = $this->getIterator('source', $table);
        $key = $this->manager->getKey('source', $table);
        while ($iterator->hasNext()) {
            $data = $iterator->next(ArrayDiff::$size);
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
        $key = $this->manager->getKey('target', $table);
        while ($iterator->hasNext()) {
            $data = $iterator->next(ArrayDiff::$size);
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
        $server1 = $this->source->getConfig('host').':'.$this->source->getConfig('port');
        $server2 = $this->target->getConfig('host').':'.$this->target->getConfig('port');
        $sourceKey  = $this->manager->getKey('source', $table);
        $targetKey  = $this->manager->getKey('target', $table);
        $this->checkKeys($table, $sourceKey, $targetKey);
        
        if ($server1 == $server2) {
            return $this->localTableData->getDiff($table, $sourceKey);
        } else {
            return $this->distTableData->getDiff($table, $sourceKey);
        }
    }

    private function checkKeys($table, $sourceKey, $targetKey) {
        if (empty($sourceKey) || empty($targetKey)) {
            throw new DataException("No primary key found in table `$table`");
        }
        if ($sourceKey != $targetKey) {
            throw new DataException("Unmatched primary keys in table `$table`");
        }
        return true;
    }

}
