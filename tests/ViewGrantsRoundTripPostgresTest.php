<?php

/**
 * A view the migration replaces keeps its grants.
 *
 * A changed view is dropped and created again, which discards its grants, and
 * on a database with default privileges the new view picks those up instead.
 * On Supabase that is everything to `anon` and `authenticated`, so a view
 * narrowed to one role came back open to the Data API. The migration now
 * puts back exactly the source's grants, in both directions.
 */
class ViewGrantsRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_vg';

    private const ROLES = "DO \$\$ BEGIN CREATE ROLE dbdiff_vg_reader NOLOGIN; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;
        DO \$\$ BEGIN CREATE ROLE dbdiff_vg_api NOLOGIN; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;";

    /** The defaults a Supabase database has, for the role running the migration. */
    private const DEFAULTS = 'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO dbdiff_vg_api;';

    private const GRANTS = "SELECT coalesce(string_agg(format('%s %s %s', c.relname, a.grantee::regrole, a.privilege_type), ', '
                                     ORDER BY c.relname, a.grantee::regrole::text, a.privilege_type), '')
                              FROM pg_class c CROSS JOIN LATERAL aclexplode(c.relacl) a
                             WHERE c.relname IN ('v', 'm') AND a.grantee <> c.relowner";

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return ['view' => ['VIEW'], 'materialized view' => ['MATERIALIZED VIEW']];
    }

    /** @dataProvider kinds */
    public function testAReplacedViewKeepsItsGrants(string $kind): void
    {
        $name = $kind === 'VIEW' ? 'v' : 'm';
        $this->connect('diff1')->exec(self::ROLES);
        $source = $this->db('s_' . $name, self::DEFAULTS . "CREATE TABLE t (id int, n int);
            CREATE $kind $name AS SELECT id FROM t WHERE n > 0;
            REVOKE ALL ON $name FROM dbdiff_vg_api; GRANT SELECT ON $name TO dbdiff_vg_reader;");
        $target = $this->db('t_' . $name, self::DEFAULTS . "CREATE TABLE t (id int, n int);
            CREATE $kind $name AS SELECT id FROM t WHERE n > 5;
            REVOKE ALL ON $name FROM dbdiff_vg_api; GRANT SELECT, INSERT ON $name TO dbdiff_vg_reader;");
        $sourceGrants = $this->connect($source)->query(self::GRANTS)->fetchColumn();
        $targetGrants = $this->connect($target)->query(self::GRANTS)->fetchColumn();

        [$up, $down] = $this->diff($source, $target);
        $this->connect($target)->exec($up);
        $this->assertSame($sourceGrants, $this->connect($target)->query(self::GRANTS)->fetchColumn(), "UP:\n$up");

        $this->connect($target)->exec($down);
        $this->assertSame($targetGrants, $this->connect($target)->query(self::GRANTS)->fetchColumn(), "DOWN:\n$down");
    }
}
