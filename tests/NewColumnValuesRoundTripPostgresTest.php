<?php

/**
 * A column the source added and filled in, diffed through the CLI and
 * applied, schema and data.
 *
 * The data diff compared only the columns both sides had, so the target got
 * the column with its default in every row it already held: a row the
 * source had set to 'q' arrived as the default, and one the source had set
 * to NULL arrived as the default too. See NewColumnValuesSQLiteTest.
 */
class NewColumnValuesRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_ncv';

    private const TABLE = 'CREATE TABLE t (id int PRIMARY KEY, name text);';
    private const ROWS = "INSERT INTO t VALUES (1, 'one'), (2, 'two'), (3, 'three');";

    public function testANewColumnsValuesComeWithIt(): void
    {
        $source = $this->db('s', self::TABLE . self::ROWS . <<<'SQL'
            ALTER TABLE t ADD COLUMN status text DEFAULT 'active';
            UPDATE t SET status = 'archived' WHERE id = 1;
            UPDATE t SET status = NULL WHERE id = 3;
            INSERT INTO t VALUES (4, 'four', 'new');
            SQL);
        $target = $this->db('t', self::TABLE . self::ROWS);
        $before = $this->contents($target);

        [$up, $down] = $this->diff($source, $target, 'all');

        $this->connect($target)->exec($up);
        $this->assertSame($this->contents($source), $this->contents($target), "UP makes the target the source:\n$up");
        $this->connect($target)->exec($down);
        $this->assertSame($before, $this->contents($target), "DOWN restores the target:\n$down");
    }

    /** A renamed column keeps its data: it is not a new column for the data diff to fill. */
    public function testARenamedColumnIsNotRewritten(): void
    {
        $source = $this->db('rs', self::TABLE . self::ROWS . 'ALTER TABLE t RENAME COLUMN name TO title;');
        $target = $this->db('rt', self::TABLE . self::ROWS);

        [$up] = $this->diff($source, $target, 'all');

        $this->assertStringNotContainsString('UPDATE', $up);
        $this->connect($target)->exec($up);
        $this->assertSame($this->contents($source), $this->contents($target), "UP makes the target the source:\n$up");
    }

    private function contents(string $db): array
    {
        return $this->connect($db)->query('SELECT row_to_json(t)::text FROM t ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}
