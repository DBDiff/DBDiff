<?php namespace DBDiff\SQLGen\DiffToSQL;


/**
 * A composite type's attributes cannot be retyped in place, so the type is replaced.
 */
class AlterCompositeTypeSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'TYPE';
    }
}
