<?php namespace DBDiff\DB\Data;


class TableIterator {

    function __construct($connection, $table) {
        $this->connection = $connection;
        $this->table = $table;
        $this->offset = 0;
        $this->size = $connection->table($table)->count();
    }

    public function hasNext() {
        return $this->offset < $this->size;
    }

    public function next($size) {
        $data = $this->connection->table($this->table)
                     ->skip($this->offset)->take($size)->get()->toArray();
        $this->offset += $size;
        // Normalise stdClass rows to associative arrays
        return array_map(fn($row) => is_array($row) ? $row : (array) $row, $data);
    }

}
