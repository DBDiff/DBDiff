<?php namespace DBDiff\SQLGen\DiffToSQL;


/**
 * A collation's provider, locale, rules and determinism are fixed when it is
 * created; ALTER COLLATION can only rename it or refresh its version. So a
 * changed collation is replaced — which PostgreSQL refuses while a column or
 * an index still uses it, saying so, rather than leaving it half changed.
 */
class AlterCollationSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'COLLATION';
    }
}
