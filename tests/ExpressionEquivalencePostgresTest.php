<?php

use PHPUnit\Framework\TestCase;

/**
 * The same definition rendered twice is not a change (issue #234).
 *
 * `status IN ('draft', 'active')` on a varchar column renders one way as
 * written and another once recreated from that rendering — which is what a
 * dump, a restore or a generated migration does. Every kind that holds an
 * expression was affected. Each case builds the target from a pg_dump-style
 * rendering of the source and requires "identical"; and changes the list on
 * the target and requires it still to be reported, so equivalence never hides
 * a real difference.
 *
 * Skips automatically when pdo_pgsql is missing or DB_HOST_POSTGRES is unset.
 */
class ExpressionEquivalencePostgresTest extends TestCase
{
    private ?PDO $admin = null;
    private string $host;
    private string $port;
    private string $user;
    private string $pass;
    private array $created = [];

    /** Each kind written as a developer would, then as it renders. */
    private const AS_WRITTEN = [
        'check'   => "CREATE TABLE t (status varchar(20), n int, CONSTRAINT c CHECK (status IN ('draft', 'active')));",
        'index'   => "CREATE TABLE t (status varchar(20), n int); CREATE INDEX i ON t (n) WHERE status IN ('draft', 'active');",
        'policy'  => "CREATE TABLE t (status varchar(20)); ALTER TABLE t ENABLE ROW LEVEL SECURITY;
                      CREATE POLICY p ON t USING (status IN ('draft', 'active'));",
        'view'    => "CREATE TABLE t (status varchar(20)); CREATE VIEW v AS SELECT * FROM t WHERE status IN ('draft', 'active');",
        'matview' => "CREATE TABLE t (status varchar(20)); CREATE MATERIALIZED VIEW m AS SELECT * FROM t WHERE status IN ('draft', 'active');",
        'trigger' => "CREATE TABLE t (status varchar(20));
                      CREATE FUNCTION tf() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NEW; END \$\$;
                      CREATE TRIGGER tr BEFORE UPDATE ON t FOR EACH ROW WHEN (NEW.status IN ('draft', 'active')) EXECUTE FUNCTION tf();",
    ];

    /** The same objects as they render — how a dump or a migration recreates them. */
    private const AS_RENDERED = [
        'check'   => "CREATE TABLE t (status varchar(20), n int, CONSTRAINT c CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]))));",
        'index'   => "CREATE TABLE t (status varchar(20), n int); CREATE INDEX i ON t (n) WHERE ((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]));",
        'policy'  => "CREATE TABLE t (status varchar(20)); ALTER TABLE t ENABLE ROW LEVEL SECURITY;
                      CREATE POLICY p ON t USING (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[])));",
        'view'    => "CREATE TABLE t (status varchar(20)); CREATE VIEW v AS SELECT t.status FROM t WHERE ((t.status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]));",
        'matview' => "CREATE TABLE t (status varchar(20)); CREATE MATERIALIZED VIEW m AS SELECT t.status FROM t WHERE ((t.status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]));",
        'trigger' => "CREATE TABLE t (status varchar(20));
                      CREATE FUNCTION tf() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NEW; END \$\$;
                      CREATE TRIGGER tr BEFORE UPDATE ON t FOR EACH ROW WHEN (((new.status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]))) EXECUTE FUNCTION tf();",
    ];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded.');
        }
        $host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set.');
        }
        $this->host = $host;
        $this->port = getenv('DB_PORT_POSTGRES') ?: '5432';
        $this->user = getenv('DB_USER_POSTGRES') ?: 'dbdiff';
        $this->pass = getenv('DB_PASS_POSTGRES') ?: 'rootpass';
        $this->admin = new PDO("pgsql:host={$this->host};port={$this->port};dbname=diff1",
            $this->user, $this->pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $db) {
            $this->admin->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db' AND pid <> pg_backend_pid()");
            $this->admin->exec("DROP DATABASE IF EXISTS $db");
        }
        $this->admin = null;
    }

    private function db(string $name, string $sql): string
    {
        $db = "dbdiff_eq_$name";
        $this->admin->exec("DROP DATABASE IF EXISTS $db");
        $this->admin->exec("CREATE DATABASE $db");
        $this->created[] = $db;
        (new PDO("pgsql:host={$this->host};port={$this->port};dbname=$db", $this->user, $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec($sql);
        return $db;
    }

    /** The UP between two databases, or null when the CLI calls them identical. */
    private function up(string $source, string $target): ?string
    {
        $url = fn($db) => "postgres://{$this->user}:{$this->pass}@{$this->host}:{$this->port}/$db";
        $out = tempnam(sys_get_temp_dir(), 'dbdiff_eq_') . '.sql';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/dbdiff.php')
            . ' diff --type=schema --nocomments'
            . ' --server1-url=' . escapeshellarg($url($source))
            . ' --server2-url=' . escapeshellarg($url($target))
            . ' --output=' . escapeshellarg($out) . ' 2>&1', $lines, $status);
        $this->assertSame(0, $status, implode("\n", $lines));
        if (!file_exists($out)) {
            return null;
        }
        $sql = file_get_contents($out);
        unlink($out);
        return trim(preg_replace('/^--.*$/m', '', $sql)) === '' ? null : $sql;
    }

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return array_combine(array_keys(self::AS_WRITTEN), array_map(fn($k) => [$k], array_keys(self::AS_WRITTEN)));
    }

    /** @dataProvider kinds */
    public function testARenderedCopyIsIdentical(string $kind): void
    {
        $source = $this->db("{$kind}_s", self::AS_WRITTEN[$kind]);
        $target = $this->db("{$kind}_t", self::AS_RENDERED[$kind]);

        $this->assertNull($this->up($source, $target), "$kind: the same definition rendered twice was reported as a change");
    }

    /** @dataProvider kinds */
    public function testARealChangeIsStillReported(string $kind): void
    {
        $source = $this->db("{$kind}_rs", self::AS_WRITTEN[$kind]);
        $target = $this->db("{$kind}_rt", str_replace("'active'", "'archived'", self::AS_RENDERED[$kind]));

        $this->assertNotNull($this->up($source, $target), "$kind: a changed list was hidden as equivalent");
    }
}
