<?php namespace DBDiff\DB\Data;

use DBDiff\Params\ParamsFactory;
use Diff\Differ\MapDiffer;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;


class ArrayDiff {

    public static $size = 1000;

    function __construct($key, $dbiterator1, $dbiterator2) {
        $this->key = $key;
        $this->dbiterator1 = $dbiterator1;
        $this->dbiterator2 = $dbiterator2;
        $this->sourceBucket = [];
        $this->targetBucket = [];
        $this->diffBucket = [];
    }

    public function getDiff($table) {
        while ($this->dbiterator1->hasNext() || $this->dbiterator2->hasNext()) {
            $this->iterate($table);
        }
        return $this->getResults();
    }

    public function iterate($table) {
        $data1 = $this->dbiterator1->next(ArrayDiff::$size);
        $this->sourceBucket = array_merge($this->sourceBucket, $data1);
        $data2 = $this->dbiterator2->next(ArrayDiff::$size);
        $this->targetBucket = array_merge($this->targetBucket, $data2);

        $this->tag($table);
    }

    public function isKeyEqual($entry1, $entry2) {
        foreach ($this->key as $key) {
            if ($entry1[$key] !== $entry2[$key]) return false;
        }
        return true;
    }

    public function tag($table) {
        foreach ($this->sourceBucket as &$entry1) {
            if (is_null($entry1)) continue;
            foreach ($this->targetBucket as &$entry2) {
                if (is_null($entry2)) continue;
                if ($this->isKeyEqual($entry1, $entry2)) {

                    // unset the fields to ignore
                    $params = ParamsFactory::get();
                    if (isset($params->fieldsToIgnore[$table])) {
                        foreach ($params->fieldsToIgnore[$table] as $fieldToIgnore) {
                            unset($entry1[$fieldToIgnore]);
                            unset($entry2[$fieldToIgnore]);
                        }
                    }

                    $differ = new MapDiffer();
                    $diff = $differ->doDiff($entry2, $entry1);
                    if (!empty($diff)) {
                        $this->diffBucket[] = [
                            'keys' => array_only($entry1, $this->key),
                            'diff' => $diff
                        ];
                    }
                    $entry1 = null;
                    $entry2 = null;
                }
            }
        }
    }

    public function getResults() {
        // New
        foreach ($this->sourceBucket as $entry) {
            if (is_null($entry)) continue;
            $this->diffBucket[] = [
                'keys' => array_only($entry, $this->key),
                'diff' => new DiffOpAdd($entry)
            ];
        }

        // Deleted
        foreach ($this->targetBucket as $entry) {
            if (is_null($entry)) continue;
            $this->diffBucket[] = [
                'keys' => array_only($entry, $this->key),
                'diff' => new DiffOpRemove($entry)
            ];
        }

        return $this->diffBucket;
    }
}
