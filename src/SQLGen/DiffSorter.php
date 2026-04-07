<?php namespace DBDiff\SQLGen;


class DiffSorter {

    private $up_order = [
        "SetDBCharset",
        "SetDBCollation",

        "DropEnum",
        "DropView",
        "DropTrigger",
        "DropRoutine",

        "AlterTableDropConstraint",

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

        "InsertData",
        "UpdateData",

        "CreateEnum",
        "AlterEnum",
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
        "DropEnum",
        "AlterEnum",
        "CreateEnum",

        "AlterTableAddConstraint",
        "AlterTableChangeConstraint",

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

    private function compare($order, $a, $b, string $direction = 'up'): int {
        $orderMap     = array_flip($order);
        $sqlGenClassA = (new \ReflectionClass($a))->getShortName();
        $sqlGenClassB = (new \ReflectionClass($b))->getShortName();
        $indexA = $orderMap[$sqlGenClassA];
        $indexB = $orderMap[$sqlGenClassB];
        if ($indexA !== $indexB) {
            return $indexA <=> $indexB;
        }
        return $this->compareSamePriority($a, $b, $direction, $sqlGenClassA);
    }

    private function compareSamePriority($a, $b, string $direction, string $sqlGenClassA): int {
        $sortA = $a->sortOrder ?? null;
        $sortB = $b->sortOrder ?? null;
        if ($sortA !== null && $sortB !== null && $sortA !== $sortB) {
            // CREATE: ascending (parents first); DROP: descending (children first)
            $isCreate = ($direction === 'up'   && $sqlGenClassA === 'AddTable')
                     || ($direction === 'down'  && $sqlGenClassA === 'DropTable');
            return $isCreate ? ($sortA <=> $sortB) : ($sortB <=> $sortA);
        }
        return $this->compareByName($a, $b);
    }

    private function compareByName($a, $b): int {
        $tableA = $a->table ?? '';
        $tableB = $b->table ?? '';
        $itemA  = $a->column ?? $a->key ?? $a->name ?? '';
        $itemB  = $b->column ?? $b->key ?? $b->name ?? '';
        return strcmp($tableA, $tableB)
            ?: strcmp((string) $itemA, (string) $itemB)
            ?: $this->compareDataKeys($a, $b);
    }

    private function compareDataKeys($a, $b): int {
        if (is_array($a->diff) && isset($a->diff['keys']) && is_array($b->diff) && isset($b->diff['keys'])) {
            return strcmp(json_encode($a->diff['keys']), json_encode($b->diff['keys']));
        }
        return 0;
    }
}
