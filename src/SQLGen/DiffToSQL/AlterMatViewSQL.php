<?php namespace DBDiff\SQLGen\DiffToSQL;


/**
 * PostgreSQL has no CREATE OR REPLACE for a materialised view.
 */
class AlterMatViewSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'MATERIALIZED VIEW';
    }
}
