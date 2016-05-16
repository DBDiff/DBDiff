<?php namespace DBDiff\DB\Data;

use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Diff\DeleteData;
use DBDiff\Exceptions\DataException;
use DBDiff\Logger;


class DistTableData {

    function __construct($manager) {
        $this->manager = $manager;
        $this->source = $this->manager->getDB('source');
        $this->target = $this->manager->getDB('target');
    }

    public function getIterator($connection, $table) {
        return new TableIterator($this->{$connection}, $table);
    }

    public function getDataDiff($table, $key) {
        $sourceIterator = $this->getIterator('source', $table);
        $targetIterator = $this->getIterator('target', $table);
        $differ = new ArrayDiff($key, $sourceIterator, $targetIterator);
        return $differ->getDiff($table);
    }

    public function getDiff($table, $key) {
        Logger::info("Now calculating data diff for table `$table`");
        $diffs = $this->getDataDiff($table, $key);
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
