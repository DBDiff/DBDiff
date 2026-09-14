<?php namespace DBDiff\SQLGen\DiffToSQL;


/**
 * CREATE OR REPLACE VIEW cannot change a column's name, type or order, so the view is replaced.
 */
class AlterViewSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'VIEW';
    }
}
