<?php

use DBDiff\DB\DBManager;
use DBDiff\Migration\Config\DsnParser;
use DBDiff\Params\DefaultParams;

/**
 * DBDiff's own sessions carry the URL's `options`.
 *
 * It dropped them, so a source made read-only through its URL — the guard
 * people put on a production connection — was an ordinary session for
 * DBDiff while psql and pg_dump given the same URL respected it.
 */
class UrlSessionOptionsPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_uso';

    public function testTheSourceSessionHasTheUrlsSettingsAndTheTargetItsOwn(): void
    {
        $source = $this->db('s', 'CREATE TABLE t (id int);');
        $target = $this->db('t', 'CREATE TABLE t (id int);');
        $s = DsnParser::toServerAndDb(DsnParser::parse(
            $this->url($source) . '?options=' . rawurlencode('-c default_transaction_read_only=on -c statement_timeout=4321')));
        $t = DsnParser::toServerAndDb(DsnParser::parse($this->url($target)));

        $params = new DefaultParams();
        $params->driver = 'pgsql';
        $params->server1 = $s['server'];
        $params->server2 = $t['server'];
        $params->input = ['kind' => 'db', 'source' => ['server' => 'server1', 'db' => $s['db']], 'target' => ['server' => 'server2', 'db' => $t['db']]];

        $manager = new DBManager();
        $manager->connect($params);
        $show = fn (string $conn, string $setting) => $manager->getDB($conn)->select("SELECT current_setting('$setting') AS v")[0]['v'];

        $this->assertSame('on', $show('source', 'default_transaction_read_only'));
        $this->assertSame('4321ms', $show('source', 'statement_timeout'));
        $this->assertSame('off', $show('target', 'default_transaction_read_only'));

        // And still after the connections are rebuilt for another schema.
        $manager->useSchema('public_again');
        $this->assertSame('on', $show('source', 'default_transaction_read_only'));
    }
}
