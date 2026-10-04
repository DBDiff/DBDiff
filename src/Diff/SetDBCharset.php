<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class SetDBCharset {
    use InSchema;

    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;
    public $db;
    public $charset;
    public $prevCharset;

    function __construct($db, $charset, $prevCharset) {
        $this->db = $db;
        $this->charset = $charset;
        $this->prevCharset = $prevCharset;

    }
}
