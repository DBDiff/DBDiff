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
        $order = array_flip($order);
        $reflectionA = new \ReflectionClass($a);
        $reflectionB = new \ReflectionClass($b);
        $sqlGenClassA = $reflectionA->getShortName();
        $sqlGenClassB = $reflectionB->getShortName();
        $indexA = $order[$sqlGenClassA];
        $indexB = $order[$sqlGenClassB];
        
        if ($indexA === $indexB) return 0;
        else if ($indexA > $indexB) return 1;
        return -1;
    }
}
