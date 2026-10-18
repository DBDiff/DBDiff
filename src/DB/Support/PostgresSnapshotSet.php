<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;

/**
 * The snapshot each side of a diff is read as of — see PostgresSnapshot —
 * and the connections that hold them.
 */
final class PostgresSnapshotSet {

    /** @var array<string, string> by side */
    private array $ids = [];

    /**
     * Read `$side`'s connection as of a snapshot: the one another session
     * exported and holds, when the params name it (--source-snapshot,
     * --target-snapshot), so a larger read sees one instant throughout;
     * otherwise one a second connection exports and keeps open.
     */
    public function take(Capsule $capsule, string $side, array $config, object $params): void {
        $id = $side === 'source' ? ($params->sourceSnapshot ?? null) : ($params->targetSnapshot ?? null);
        if ($id === null) {
            $anchor = $side . '__snapshot';
            $capsule->addConnection($config, $anchor);
            PostgresSessionOptions::apply($capsule->getConnection($anchor));
            $id = PostgresSnapshot::export($capsule->getConnection($anchor), $side);
        }
        if ($id !== null && PostgresSnapshot::join($capsule->getConnection($side), $id, $side)) {
            $this->ids[$side] = $id;
        }
    }

    /** A connection made again for `$side` reads as of the same instant as the last. */
    public function rejoin(Connection $connection, string $side): void {
        if (isset($this->ids[$side]) && !PostgresSnapshot::join($connection, $this->ids[$side], $side)) {
            unset($this->ids[$side]);
        }
    }

    /** Whether `$side` is read as of one snapshot. */
    public function holds(string $side): bool {
        return isset($this->ids[$side]);
    }
}
