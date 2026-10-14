<?php namespace DBDiff\SQLGen\DiffToSQL;

abstract class CreateSchemaObjectSQL extends SchemaObjectSQL {

    public function getUp(): string {
        return self::statement($this->obj->definition);
    }

    public function getDown(): string {
        return self::statement($this->obj->drop);
    }
}
