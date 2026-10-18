<?php namespace DBDiff\DB\Data;

use PDO;
use PDOStatement;

/**
 * The rows of one query, read a batch at a time instead of all at once.
 *
 * PDO's pgsql and mysql drivers buffer a whole result on the client before
 * the first fetch() returns: libpq and libmysqlclient read every row into
 * memory as the statement executes. So a "streaming" pass over a table of N
 * rows still held all N rows at once — about 170 MB per million rows on
 * PostgreSQL, which crosses DBDiff's default 1G memory limit at a few million
 * rows. Memory now stays flat however large the table is.
 *
 *   pgsql  — a server-side cursor, fetched BATCH rows at a time. A cursor
 *            lives in a transaction, so one is opened if the connection is not
 *            already in one, and rolled back on close (nothing is written).
 *   mysql  — an unbuffered query, unless the other side of the diff shares
 *            this connection: MySQL allows one unbuffered result per
 *            connection at a time.
 *   other  — SQLite already reads a row at a time.
 *
 * A cursor is not free: PDO has no way to fetch the next batch while the
 * current one is being compared, so the server waits on PHP and PHP on the
 * server — at 2M rows a diff took about 50% longer. Pass $bounded = false
 * for a table small enough to buffer, and it is read the old, faster way.
 *
 * close() must be called, and for streams sharing a connection, in the
 * reverse of the order they were opened.
 */
class RowStream
{
    public const BATCH = 5000;

    private PDO $pdo;
    private ?PDOStatement $statement = null;
    private ?PDOStatement $fetch = null;
    private ?string $cursor = null;
    private bool $ownsTransaction = false;
    private ?bool $restoreBuffered = null;
    /** @var array<int, array<string, mixed>> */
    private array $batch = [];
    private int $position = 0;
    private int $size = 0;
    private bool $exhausted = false;

    /**
     * @param string $name A name for the cursor, unique among streams open on the connection.
     * @param bool   $exclusive Whether this stream has the connection to itself.
     * @param bool   $bounded   Read in batches, so memory does not grow with the result.
     */
    public function __construct(PDO $pdo, string $driver, string $sql, string $name, bool $exclusive = true, bool $bounded = true)
    {
        $this->pdo = $pdo;

        if ($driver === 'pgsql' && $bounded) {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $this->ownsTransaction = true;
            }
            $this->cursor = 'dbdiff_' . preg_replace('/\W/', '_', $name);
            $this->declare($pdo, "DECLARE {$this->cursor} NO SCROLL CURSOR FOR {$sql}");
            $this->fetch = $pdo->prepare("FETCH FORWARD " . self::BATCH . " FROM {$this->cursor}");
            return;
        }

        if ($driver === 'mysql' && $exclusive && $bounded) {
            $this->restoreBuffered = (bool) $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        $this->statement = $pdo->prepare($sql);
        $this->statement->execute();
    }

    /**
     * Declare the cursor. In a transaction this stream did not open — the
     * snapshot a diff reads as of — a failure would end that transaction for
     * every read after it, so it is undone to a savepoint instead.
     */
    private function declare(PDO $pdo, string $declare): void
    {
        if ($this->ownsTransaction) {
            $pdo->exec($declare);
            return;
        }
        $pdo->exec('SAVEPOINT dbdiff_declare');
        try {
            $pdo->exec($declare);
        } catch (\Throwable $e) {
            $this->cursor = null;
            $pdo->exec('ROLLBACK TO SAVEPOINT dbdiff_declare');
            $pdo->exec('RELEASE SAVEPOINT dbdiff_declare');
            throw $e;
        }
        $pdo->exec('RELEASE SAVEPOINT dbdiff_declare');
    }

    /** The next row, or false when there are no more. */
    public function fetch(): array|false
    {
        if ($this->statement !== null) {
            return $this->statement->fetch(PDO::FETCH_ASSOC);
        }
        // Called once per row of each table: the common case is one comparison.
        if ($this->position === $this->size && !$this->nextBatch()) {
            return false;
        }
        return $this->batch[$this->position++];
    }

    /** Fetch the cursor's next batch. False when there is none. */
    private function nextBatch(): bool
    {
        if ($this->exhausted || $this->fetch === null) {
            return false;
        }
        $this->fetch->execute();
        $this->batch = $this->fetch->fetchAll(PDO::FETCH_ASSOC);
        $this->fetch->closeCursor();
        $this->size = count($this->batch);
        $this->position = 0;
        $this->exhausted = $this->size < self::BATCH;
        return $this->size > 0;
    }

    /** Release the cursor, transaction or connection mode this stream took. Safe to call twice. */
    public function close(): void
    {
        $this->batch = [];
        $this->position = 0;
        $this->size = 0;
        $this->exhausted = true;
        $this->fetch = null;
        if ($this->statement !== null) {
            $this->statement->closeCursor();
            $this->statement = null;
        }
        if ($this->restoreBuffered !== null) {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $this->restoreBuffered);
            $this->restoreBuffered = null;
        }
        if ($this->cursor !== null) {
            $cursor = $this->cursor;
            $this->cursor = null;
            try {
                if ($this->ownsTransaction) {
                    // Rolling back closes the cursor with it.
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                } else {
                    $this->pdo->exec("CLOSE {$cursor}");
                }
            } finally {
                $this->ownsTransaction = false;
            }
        }
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // Already failing: the error that got here is the one to report.
        }
    }
}
