<?php namespace DBDiff\SQLGen;


class DiffSorter {

    private $up_order = [
        "SetDBCharset",
        "SetDBCollation",

        "DropView",
        "DropTrigger",
        "DropRoutine",

        "AddTable",

        "DeleteData",
        "DropTable",

        "AlterTableEngine",
        "AlterTableCollation",

        "AlterTableAddColumn",
        "AlterTableChangeColumn",
        "AlterTableDropColumn",

        "AlterTableAddKey",
        "AlterTableChangeKey",
        "AlterTableDropKey",

        "AlterTableAddConstraint",
        "AlterTableChangeConstraint",
        "AlterTableDropConstraint",

        "InsertData",
        "UpdateData",

        "CreateView",
        "AlterView",
        "CreateTrigger",
        "AlterTrigger",
        "CreateRoutine",
        "AlterRoutine",
    ];

    private $down_order = [
        "SetDBCharset",
        "SetDBCollation",

        "DropRoutine",
        "AlterRoutine",
        "CreateRoutine",
        "DropTrigger",
        "AlterTrigger",
        "CreateTrigger",
        "DropView",
        "AlterView",
        "CreateView",

        "InsertData",
        "AddTable",

        "DropTable",

        "AlterTableEngine",
        "AlterTableCollation",

        "AlterTableAddColumn",
        "AlterTableChangeColumn",
        "AlterTableDropColumn",

        "AlterTableAddKey",
        "AlterTableChangeKey",
        "AlterTableDropKey",

        "AlterTableAddConstraint",
        "AlterTableChangeConstraint",
        "AlterTableDropConstraint",

        "DeleteData",
        "UpdateData"
    ];

    public function sort($diff, $type) {
        usort($diff, [$this, 'compare'.ucfirst($type)]);
        return $diff;
    }
    
    private function compareUp($a, $b) {
        return $this->compare($this->up_order, $a, $b, 'up');
    }

    private function compareDown($a, $b) {
        return $this->compare($this->down_order, $a, $b, 'down');
    }

    private function compare($order, $a, $b, string $direction = 'up') {
        $orderMap = array_flip($order);
        $reflectionA = new \ReflectionClass($a);
        $reflectionB = new \ReflectionClass($b);
        $sqlGenClassA = $reflectionA->getShortName();
        $sqlGenClassB = $reflectionB->getShortName();
        $indexA = $orderMap[$sqlGenClassA];
        $indexB = $orderMap[$sqlGenClassB];
        
        if ($indexA === $indexB) {
            // FK topological ordering for AddTable / DropTable
            $sortA = $a->sortOrder ?? null;
            $sortB = $b->sortOrder ?? null;
            if ($sortA !== null && $sortB !== null && $sortA !== $sortB) {
                // CREATE operations: ascending (parents first)
                // DROP operations: descending (children first)
                $isCreate = ($direction === 'up'   && $sqlGenClassA === 'AddTable')
                         || ($direction === 'down'  && $sqlGenClassA === 'DropTable');
                return $isCreate ? ($sortA <=> $sortB) : ($sortB <=> $sortA);
            }

            // Secondary sort by table name if available
            $tableA = $a->table ?? '';
            $tableB = $b->table ?? '';
            if ($tableA !== $tableB) {
                return strcmp($tableA, $tableB);
            }

            // Tertiary sort by item (column, key, etc.) if available
            $itemA = $a->column ?? $a->key ?? $a->name ?? '';
            $itemB = $b->column ?? $b->key ?? $b->name ?? '';
            if ($itemA !== $itemB) {
                return strcmp((string)$itemA, (string)$itemB);
            }

            // Quaternary sort by data keys if it's a data diff
            if (is_array($a->diff) && isset($a->diff['keys']) && is_array($b->diff) && isset($b->diff['keys'])) {
                return strcmp(json_encode($a->diff['keys']), json_encode($b->diff['keys']));
            }

            return 0;
        }
        return ($indexA > $indexB) ? 1 : -1;
    }
}
