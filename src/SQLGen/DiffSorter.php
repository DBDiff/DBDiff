<?php namespace DBDiff\SQLGen;


class DiffSorter {

    private $up_order = [
        "SetDBCharset",
        "SetDBCollation",

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
        "UpdateData"
    ];

    private $down_order = [
        "SetDBCharset",
        "SetDBCollation",

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
        return $this->compare($this->up_order, $a, $b);
    }

    private function compareDown($a, $b) {
        return $this->compare($this->down_order, $a, $b);
    }

    private function compare($order, $a, $b) {
        $orderMap = array_flip($order);
        $reflectionA = new \ReflectionClass($a);
        $reflectionB = new \ReflectionClass($b);
        $sqlGenClassA = $reflectionA->getShortName();
        $sqlGenClassB = $reflectionB->getShortName();
        $indexA = $orderMap[$sqlGenClassA];
        $indexB = $orderMap[$sqlGenClassB];
        
        if ($indexA === $indexB) {
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
