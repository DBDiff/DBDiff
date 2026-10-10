<?php

use DBDiff\DB\DBManager;
use DBDiff\Diff\AlterView;
use DBDiff\DB\Support\PostgresExpressionEquivalence;
use DBDiff\Migration\Config\DsnParser;
use DBDiff\Params\DefaultParams;

/**
 * Deciding whether changed views are the same one rendered twice costs a
 * fixed number of transactions, not several per view.
 *
 * Each rendering was its own begin, create, read and roll back, and across
 * major versions the target renders two per view: about 1,600 round trips for
 * 200 views, which over a 50 ms link nearly doubled a diff. Every view body is
 * now rendered in one transaction per connection.
 */
class ViewRenderingRoundTripsPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_vrr';

    private const VIEWS = 25;

    public function testManyViewsAreRenderedInOneTransactionAndJudgedAsBefore(): void
    {
        if ($this->serverVersion < 150000) {
            $this->markTestSkipped('pg_stat_force_next_flush() needs PostgreSQL 15.');
        }
        $tables = 'CREATE TABLE t (id int, n int, label text);';
        $source = $this->db('s', $tables);
        $target = $this->db('t', $tables);

        $manager = new DBManager();
        $params = new DefaultParams();
        $params->driver = 'pgsql';
        $s = DsnParser::toServerAndDb(DsnParser::parse($this->url($source)));
        $t = DsnParser::toServerAndDb(DsnParser::parse($this->url($target)));
        $params->server1 = $s['server'];
        $params->server2 = $t['server'];
        $params->input = ['kind' => 'db', 'source' => ['server' => 'server1', 'db' => $s['db']],
                          'target' => ['server' => 'server2', 'db' => $t['db']]];
        $manager->connect($params);
        $src = $manager->getDB('source');
        $tgt = $manager->getDB('target');

        // Half the views are the same query written two ways (equivalent), half
        // genuinely differ, and one cannot be built at all.
        $diffs = [];
        for ($i = 0; $i < self::VIEWS; $i++) {
            $same = $i % 2 === 0;
            $diffs[] = new AlterView("v$i",
                "CREATE VIEW \"v$i\" AS SELECT t.id FROM t WHERE (t.n > $i)",
                $same ? "CREATE VIEW \"v$i\" AS SELECT id FROM t WHERE n > $i" : "CREATE VIEW \"v$i\" AS SELECT id FROM t WHERE n < $i");
        }
        $diffs[] = new AlterView('broken', 'CREATE VIEW "broken" AS SELECT nope FROM missing', 'CREATE VIEW "broken" AS SELECT 1');

        // The flush happens once the statement asking for it has finished, so
        // the count is read by a second one.
        $rollbacks = function () use ($tgt): int {
            $tgt->select('SELECT pg_stat_force_next_flush()');
            return (int) $tgt->selectOne(
                'SELECT xact_rollback FROM pg_stat_database WHERE datname = current_database()')['xact_rollback'];
        };
        $before = $rollbacks();
        $kept = PostgresExpressionEquivalence::dropEquivalent($diffs, $src, $tgt);
        $after = $rollbacks();

        $this->assertSame(
            array_merge(array_map(fn($i) => "v$i", range(1, self::VIEWS - 1, 2)), ['broken']),
            array_map(fn($d) => $d->name, $kept)
        );
        $this->assertLessThan(5, $after - $before, 'transactions rolled back on the target while rendering');
    }
}
