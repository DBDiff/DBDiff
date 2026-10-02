<?php namespace DBDiff\DB\Data;

use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Diff\DeleteData;
use DBDiff\Exceptions\DataException;
use DBDiff\Logger;
use DBDiff\Params\ParamsFactory;
use DBDiff\Params\TableFilter;
use DBDiff\DB\Support\ComputedColumns;
use Illuminate\Support\Arr;


class TableData {

    function __construct($manager) {
        $this->manager = $manager;
        $this->source = $this->manager->getDB('source');
        $this->target = $this->manager->getDB('target');
        $this->distTableData = new DistTableData($manager);
        $this->localTableData = new LocalTableData($manager);
    }

    public function getIterator($connection, $table) {
        $columns = $this->manager->getDataColumns($connection, $table);
        $order = $this->manager->getKey($connection, $table) ?: $columns;
        return new TableIterator($this->{$connection}, $table, $order, $columns);
    }

    public function getNewData($table) {
        Logger::info("Now getting new data from table `$table`");
        $diffSequence = [];
        $iterator = $this->getIterator('source', $table);
        $key = $this->manager->getKey('source', $table);
        $rowRules = TableFilter::getRowIgnoreRules($table, ParamsFactory::get());
        while ($iterator->hasNext()) {
            $data = $iterator->next(ArrayDiff::$size);
            if (!empty($rowRules)) {
                $data = TableFilter::filterRows($data, $rowRules);
            }
            foreach ($data as $entry) {
                $diffSequence[] = new InsertData($table, self::rowDiff($entry, $key, new \Diff\DiffOp\DiffOpAdd($entry)));
            }
        }
        return $this->withOverriding($diffSequence, 'source', $table);
    }

    public function getOldData($table) {
        Logger::info("Now getting old data from table `$table`");
        $diffSequence = [];
        $iterator = $this->getIterator('target', $table);
        $key = $this->manager->getKey('target', $table);
        $rowRules = TableFilter::getRowIgnoreRules($table, ParamsFactory::get());
        while ($iterator->hasNext()) {
            $data = $iterator->next(ArrayDiff::$size);
            if (!empty($rowRules)) {
                $data = TableFilter::filterRows($data, $rowRules);
            }
            foreach ($data as $entry) {
                $diffSequence[] = new DeleteData($table, self::rowDiff($entry, $key, new \Diff\DiffOp\DiffOpRemove($entry)));
            }
        }
        return $this->withOverriding($diffSequence, 'target', $table);
    }

    /**
     * Mark each row inserted into a GENERATED ALWAYS identity column — an
     * INSERT, or the DOWN of a DELETE — so its SQL overrides the system value.
     * PostgreSQL refuses the row otherwise: "cannot insert a non-DEFAULT value".
     * `$into` is the database the rows are inserted into.
     */
    private function withOverriding(array $diffs, string $into, string $table): array {
        $identity = ComputedColumns::identityAlways($this->manager->getDB($into), $this->manager->getDriver(), $table);
        if (empty($identity)) {
            return $diffs;
        }
        foreach ($diffs as $d) {
            $row = match (true) {
                $d instanceof InsertData => $d->diff['diff']->getNewValue(),
                $d instanceof DeleteData => $d->diff['diff']->getOldValue(),
                default => [],
            };
            if (array_intersect($identity, array_keys($row))) {
                $d->diff['overriding'] = true;
            }
        }
        return $diffs;
    }

    /**
     * The diff payload for one row: keyed by the table's key, or — for a table
     * without one — by every column, marked keyless so that removing it
     * removes one of several identical rows rather than all of them.
     */
    private static function rowDiff(array $row, array $key, $op): array {
        return empty($key)
            ? ['keys' => $row, 'diff' => $op, 'keyless' => true]
            : ['keys' => Arr::only($row, $key), 'diff' => $op];
    }

    public function getDiff($table) {
        $server1 = $this->source->getConfig('host').':'.$this->source->getConfig('port');
        $server2 = $this->target->getConfig('host').':'.$this->target->getConfig('port');
        $sourceKey  = $this->manager->getKey('source', $table);
        $targetKey  = $this->manager->getKey('target', $table);
        if (empty($sourceKey) && empty($targetKey)) {
            return $this->withOverriding($this->getKeylessDiff($table), 'target', $table);
        }
        $this->checkKeys($table, $sourceKey, $targetKey);

        $diffs = $server1 == $server2
            ? $this->localTableData->getDiff($table, $sourceKey)
            : $this->distTableData->getDiff($table, $sourceKey);
        return $this->withOverriding($diffs, 'target', $table);
    }

    /**
     * The data diff of a table with no key on either side.
     *
     * Rows can only be told apart by their values, so each side is read whole
     * and counted: a row the source holds more often is inserted that many
     * times, one the target holds more often is deleted one row at a time.
     * There is no UPDATE without a key — a changed row is a delete and an
     * insert. Such tables used to be skipped with an error in the log and
     * nothing in the migration, which read as no difference at all.
     */
    private function getKeylessDiff($table): array {
        Logger::info("Table `$table` has no key: comparing whole rows");
        $sourceColumns = $this->manager->getDataColumns('source', $table);
        $targetColumns = $this->manager->getDataColumns('target', $table);
        if (array_diff($sourceColumns, $targetColumns) || array_diff($targetColumns, $sourceColumns)) {
            throw new DataException("Table `$table` has no key and different columns on each side; its rows cannot be matched");
        }

        $count = function (string $side) use ($table): array {
            $rows = [];
            $iterator = $this->getIterator($side, $table);
            while ($iterator->hasNext()) {
                foreach ($iterator->next(ArrayDiff::$size) as $row) {
                    ksort($row);
                    $id = serialize($row);
                    $rows[$id] ??= ['row' => $row, 'n' => 0];
                    $rows[$id]['n']++;
                }
            }
            return $rows;
        };
        $source = $count('source');
        $target = $count('target');

        $diffSequence = [];
        foreach ($source as $id => ['row' => $row, 'n' => $n]) {
            for ($i = $target[$id]['n'] ?? 0; $i < $n; $i++) {
                $diffSequence[] = new InsertData($table, self::rowDiff($row, [], new \Diff\DiffOp\DiffOpAdd($row)));
            }
        }
        foreach ($target as $id => ['row' => $row, 'n' => $n]) {
            for ($i = $source[$id]['n'] ?? 0; $i < $n; $i++) {
                $diffSequence[] = new DeleteData($table, self::rowDiff($row, [], new \Diff\DiffOp\DiffOpRemove($row)));
            }
        }
        return $diffSequence;
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
