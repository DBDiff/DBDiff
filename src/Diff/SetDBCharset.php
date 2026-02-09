<?php namespace DBDiff\Diff;


class SetDBCharset {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;

    function __construct($db, $charset, $prevCharset) {
        $this->db = $db;
        $this->charset = $charset;
        $this->prevCharset = $prevCharset;

    }
}
