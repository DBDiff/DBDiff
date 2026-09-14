<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;

/**
 * Empty answers for the object kinds only PostgreSQL has.
 *
 * MySQL and SQLite have no composite types, no domains, no materialised views,
 * no row level security, and no standalone sequence objects — an auto-increment
 * column is an attribute of the column in both, not a separate relation. The
 * diff layer therefore finds nothing to do for these kinds on those drivers.
 *
 * They are declared on DBAdapterInterface rather than discovered by probing the
 * adapter, so every driver has to answer for every kind. That keeps the schema
 * diff free of per-driver conditionals, at the cost of these stubs — which live
 * here rather than being written out twice, identically, in each adapter.
 */
trait UnsupportedObjectKindsTrait {

    public function getSequences(Connection $connection): array {
        return [];
    }

    public function getCompositeTypes(Connection $connection): array {
        return [];
    }

    public function getDomains(Connection $connection): array {
        return [];
    }

    public function getMaterializedViews(Connection $connection): array {
        return [];
    }

    public function getPolicies(Connection $connection): array {
        return [];
    }

    public function getRowSecurity(Connection $connection): array {
        return [];
    }
}
