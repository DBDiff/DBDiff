<?php namespace DBDiff\SQLGen\DiffToSQL;


/**
 * ALTER TYPE can append an enum label but cannot remove or reorder one, so the type is replaced.
 */
class AlterEnumSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'TYPE';
    }
}
