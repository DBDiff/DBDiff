<?php namespace DBDiff\SQLGen\DiffToSQL;

abstract class ReplaceSchemaObjectSQL extends SchemaObjectSQL {

    public function getUp(): string {
        return self::statement($this->obj->targetDrop) . "\n" . self::statement($this->obj->sourceDefinition);
    }

    public function getDown(): string {
        return self::statement($this->obj->sourceDrop) . "\n" . self::statement($this->obj->targetDefinition);
    }
}
