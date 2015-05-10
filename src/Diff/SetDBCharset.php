<?php namespace DBDiff\Diff;


class SetDBCharset {

    function __construct($db, $charset, $prevCharset) {
        $this->db = $db;
        $this->charset = $charset;
        $this->prevCharset = $prevCharset;

    }
}
