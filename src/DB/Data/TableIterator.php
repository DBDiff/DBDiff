<?php namespace DBDiff\DB\Data;


class TableIterator {

    /**
     * @param string[] $order   Columns to page in order of: the key, or every
     *                          column of a table without one.
     * @param string[] $columns Columns to read; all of them when empty. The
     *                          data diff leaves out generated columns.
     */
    function __construct($connection, $table, array $order = [], array $columns = []) {
        $this->connection = $connection;
        $this->table = $table;
        $this->order = $order;
        $this->columns = $columns;
        $this->offset = 0;
        $this->size = $connection->table($table)->count();
    }

    public function hasNext() {
        return $this->offset < $this->size;
    }

    public function next($size) {
        // Pages need a fixed order. Without one PostgreSQL may return the rows
        // in a different order for each query, so paging with OFFSET could
        // repeat some rows and miss others once a table outgrew one page.
        $query = $this->connection->table($this->table);
        if ($this->columns) {
            $query->select($this->columns);
        }
        foreach ($this->order as $column) {
            $query->orderBy($column);
        }
        $data = $query->skip($this->offset)->take($size)->get()->toArray();
        $this->offset += $size;
        // Normalise stdClass rows to associative arrays, and binary streams to values.
        return array_map(fn($row) => BinaryValue::fromStreams(is_array($row) ? $row : (array) $row), $data);
    }

}
