<?php namespace DBDiff\DB\Data;

use Diff\Differ\MapDiffer;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;


class ArrayDiff {

    const SIZE = 1;

    function __construct($key, $dbiterator1, $dbiterator2) {
        $this->key = $key;
        $this->dbiterator1 = $dbiterator1;
        $this->dbiterator2 = $dbiterator2;
        $this->sourceBucket = [];
        $this->targetBucket = [];
        $this->diffBucket = [];
    }

    public function getDiff() {
        while ($this->dbiterator1->hasNext() || $this->dbiterator2->hasNext()) {
            $this->iterate();
        }
        return $this->getResults();
    }

    public function iterate() {
        $data1 = $this->dbiterator1->next(ArrayDiff::SIZE);
        $this->sourceBucket = array_merge($this->sourceBucket, $data1);
        $data2 = $this->dbiterator2->next(ArrayDiff::SIZE);
        $this->targetBucket = array_merge($this->targetBucket, $data2);
        $this->tag();
    }

    public function isKeyEqual($entry1, $entry2) {
        foreach ($this->key as $key) {
            if ($entry1[$key] !== $entry2[$key]) return false;
        }
        return true;
    }

    public function tag() {
        foreach ($this->sourceBucket as &$entry1) {
            if (is_null($entry1)) continue;
            foreach ($this->targetBucket as &$entry2) {
                if (is_null($entry2)) continue;
                if ($this->isKeyEqual($entry1, $entry2)) {
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
