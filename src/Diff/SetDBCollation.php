<?php namespace DBDiff\Diff;


class SetDBCollation {

    function __construct($db, $collation, $prevCollation) {
        $this->db = $db;
        $this->collation = $collation;
        $this->prevCollation = $prevCollation;
    }
}
