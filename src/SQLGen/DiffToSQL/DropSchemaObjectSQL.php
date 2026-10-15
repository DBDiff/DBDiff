<?php namespace DBDiff\SQLGen\DiffToSQL;

abstract class DropSchemaObjectSQL extends SchemaObjectSQL {

    public function getUp(): string {
        return self::statement($this->obj->drop);
    }

    public function getDown(): string {
        return self::statement($this->obj->definition);
    }
}
