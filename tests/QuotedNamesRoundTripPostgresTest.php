<?php

/**
 * Tables and columns whose names hold a double quote, diffed through the CLI
 * and applied, schema and data.
 *
 * The data diff wrapped each name in quotes without doubling the ones inside
 * it, so a key column named `i"d` broke its query outright. See
 * RoundTripMySQLTest for backticks on MySQL and QuotedNamesSQLiteTest for
 * SQLite.
 */
class QuotedNamesRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_qn';

    private const TABLE = 'CREATE TABLE "we""ird" ("i""d" int PRIMARY KEY, "x""y" text, "a;b" int);';

    public function testNamesHoldingQuotesRoundTrip(): void
    {
        $source = $this->db('s', self::TABLE . <<<'SQL'
            ALTER TABLE "we""ird" ADD COLUMN "n""ew" text;
            CREATE TABLE "only""here" ("k""ey" text PRIMARY KEY);
            INSERT INTO "only""here" VALUES ('a"b');
            INSERT INTO "we""ird" VALUES (1, 'one', 1, NULL), (2, 'two"', 2, NULL), (4, 'four', NULL, NULL);
            SQL);
        $target = $this->db('t', self::TABLE . <<<'SQL'
            INSERT INTO "we""ird" VALUES (1, 'changed', 1), (3, 'gone', 3), (4, 'four', NULL);
            SQL);
        $before = $this->contents($target);

        [$up, $down] = $this->diff($source, $target, 'all');

        $this->connect($target)->exec($up);
        $this->assertSame($this->contents($source), $this->contents($target), "UP makes the target the source:\n$up");
        $this->connect($target)->exec($down);
        $this->assertSame($before, $this->contents($target), "DOWN restores the target:\n$down");
    }

    /** Every table's rows, as JSON: the column names are the keys. */
    private function contents(string $db): array
    {
        $pdo = $this->connect($db);
        $out = [];
        $tables = $pdo->query("SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind = 'r' ORDER BY 1")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $quoted = '"' . str_replace('"', '""', $table) . '"';
            $out[$table] = $pdo->query("SELECT row_to_json(t)::text FROM $quoted t ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        }
        return $out;
    }
}
