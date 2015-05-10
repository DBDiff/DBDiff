<?php namespace DBDiff\SQLGen\Schema;


class SchemaDiffSorter {

    private $order = [
        "SetDBCharset",
        "SetDBCollation",

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
    ];
    
    public function sort($diff) {
        usort($diff, [$this, 'compare']);
        return $diff;
    }

    public function compare($a, $b) {
        $order = array_flip($this->order);
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
