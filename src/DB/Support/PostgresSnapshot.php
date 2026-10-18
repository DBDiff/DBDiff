<?php namespace DBDiff\DB\Support;

use DBDiff\Logger;
use Illuminate\Database\Connection;

/**
 * Each side of a PostgreSQL diff read as of one instant.
 *
 * A diff reads a database in many queries, over seconds or minutes. A change
 * made in between — a migration that runs, a table someone adds — would be
 * seen by some of them and not by others, and the diff would describe a
 * database that never existed: a foreign key to a table it did not read, a
 * view on a column it read before the column was dropped.
 *
 * So each side opens a REPEATABLE READ, READ ONLY transaction that exports
 * its snapshot and stays open for the run, and every connection DBDiff reads
 * that side with joins it with SET TRANSACTION SNAPSHOT — including the one
 * made again for each schema of a multi-schema diff. Their transaction is
 * REPEATABLE READ too, but not read only: definitions are rendered with
 * temporary objects, made and thrown away inside a savepoint (scratch()). A
 * query that may fail runs inside one as well (attempt()), or its failure
 * would end the transaction for every query after it.
 *
 * The cost is pg_dump's: what the reads lock (a view's tables, while its
 * definition is printed; a table, while its rows are read) stays locked until
 * the run ends, so DDL on that side waits for it. --no-snapshot reads as
 * before, each query on its own.
 */
final class PostgresSnapshot {

    private const SCRATCH = 'dbdiff_scratch';
    private const ATTEMPT = 'dbdiff_attempt';

    /**
     * Export `$anchor`'s snapshot, for the connections reading `$side` to
     * join. Null when the server will not (a standby before PostgreSQL 10, a
     * pooler in statement mode): that side is then read as before, and the
     * log says so.
     */
    public static function export(Connection $anchor, string $side): ?string {
        $pdo = $anchor->getPdo();
        try {
            $pdo->beginTransaction();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            return (string) $pdo->query('SELECT pg_export_snapshot()')->fetchColumn();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::info("Reading the $side without a consistent snapshot: " . self::firstLine($e));
            return null;
        }
    }

    /** Read `$connection` as of snapshot `$id`. False, and logged, when it cannot. */
    public static function join(Connection $connection, string $id, string $side): bool {
        $pdo = $connection->getPdo();
        try {
            $pdo->beginTransaction();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec("SET TRANSACTION SNAPSHOT '" . str_replace("'", "''", $id) . "'");
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::info("Reading the $side without a consistent snapshot: " . self::firstLine($e));
            return false;
        }
    }

    /**
     * Run `$work` and undo whatever it did: the temporary objects a rendering
     * makes. Its own transaction, rolled back, on a connection not in one; a
     * savepoint, rolled back to, on one that is.
     */
    public static function scratch(\PDO $pdo, callable $work): mixed {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            try {
                return $work();
            } finally {
                $pdo->rollBack();
            }
        }
        $pdo->exec('SAVEPOINT ' . self::SCRATCH);
        try {
            return $work();
        } finally {
            self::undo($pdo, self::SCRATCH);
        }
    }

    /**
     * Run a read that may fail, keeping the transaction usable when it does.
     * Outside a transaction it simply runs.
     */
    public static function attempt(Connection $connection, callable $read): mixed {
        $pdo = $connection->getPdo();
        if ($connection->getDriverName() !== 'pgsql' || !$pdo->inTransaction()) {
            return $read();
        }
        $pdo->exec('SAVEPOINT ' . self::ATTEMPT);
        try {
            $result = $read();
        } catch (\Throwable $e) {
            self::undo($pdo, self::ATTEMPT);
            throw $e;
        }
        $pdo->exec('RELEASE SAVEPOINT ' . self::ATTEMPT);
        return $result;
    }

    /** Back to the savepoint, and let it go. */
    private static function undo(\PDO $pdo, string $savepoint): void {
        $pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
        $pdo->exec("RELEASE SAVEPOINT $savepoint");
    }

    private static function firstLine(\Throwable $e): string {
        return strtok($e->getMessage(), "\n") ?: get_class($e);
    }
}
