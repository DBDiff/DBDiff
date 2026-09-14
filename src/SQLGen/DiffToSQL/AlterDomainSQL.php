<?php namespace DBDiff\SQLGen\DiffToSQL;


/**
 * ALTER DOMAIN can amend a default or a constraint but cannot change the base type, so the domain is replaced.
 */
class AlterDomainSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'DOMAIN';
    }
}
