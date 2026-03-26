<?php namespace DBDiff\DB\Data;

use DBDiff\Params\ParamsFactory;
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

    public function getDiff($table, $key) {
        Logger::info("Now calculating data diff for table `$table` (cross-server)");

        $params   = ParamsFactory::get();
        $driver   = $this->manager->getDriver();
        $columns1 = $this->manager->getColumns('source', $table);
        $columns2 = $this->manager->getColumns('target', $table);

        $fieldsToIgnore = $params->fieldsToIgnore[$table] ?? [];

        $merge = new StreamingMergeDiff($this->source, $this->target, $driver);
        return $merge->getDiff($table, $key, $columns1, $columns2, $fieldsToIgnore);
    }

}
