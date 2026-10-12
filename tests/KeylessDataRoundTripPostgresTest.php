<?php

/**
 * A table with no primary key, diffed through the CLI and applied.
 *
 * Such a table is matched on whole rows. With a json column it could not be
 * diffed at all: rows were paged with ORDER BY every column, and json has no
 * ordering ("could not identify an ordering operator for type json"). Nor
 * could the DELETE that removes one of several identical rows have matched
 * it, json having no `=` either. See StreamingMergeServerTest for the same
 * on MySQL, and for memory.
 */
class KeylessDataRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_kl';

    private const TABLE = 'CREATE TABLE log (msg text, n int, b bytea, j json, at timestamptz);';

    public function testAKeylessTableWithJsonRoundTrips(): void
    {
        $dup = "('dup', 1, '\\x00ff', '{\"a\": 1}', '2026-01-01 00:00:00+00')";
        $source = $this->db('s', self::TABLE . "INSERT INTO log VALUES $dup, ('a', 2, NULL, NULL, NULL),
            ('withnull', NULL, NULL, NULL, NULL), ('quote''s', 5, '\\x01', '[1, 2]', NULL);");
        $target = $this->db('t', self::TABLE . "INSERT INTO log VALUES $dup, $dup, $dup, ('b', 3, NULL, NULL, NULL),
            ('withnull', NULL, NULL, NULL, NULL), ('extra', NULL, NULL, '{\"x\": [1]}', NULL);");
        $before = $this->contents($target);

        [$up, $down] = $this->diff($source, $target, 'data');

        $this->connect($target)->exec($up);
        $this->assertSame($this->contents($source), $this->contents($target), "UP makes the target the source:\n$up");
        $this->connect($target)->exec($down);
        $this->assertSame($before, $this->contents($target), "DOWN restores the target:\n$down");
    }

    /** Every row as text, sorted: a multiset that counts duplicates. */
    private function contents(string $db): array
    {
        $rows = $this->connect($db)->query(
            "SELECT concat_ws('|', msg, coalesce(n::text, 'N'), coalesce(encode(b, 'hex'), 'N'), coalesce(j::text, 'N'), coalesce(at::text, 'N')) FROM log"
        )->fetchAll(PDO::FETCH_COLUMN);
        sort($rows);
        return $rows;
    }
}
