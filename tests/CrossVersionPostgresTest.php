<?php

/**
 * The same objects on two PostgreSQL versions are not a change.
 *
 * PostgreSQL 16 and later print a stored view without the table qualifiers 15
 * writes (`SELECT id` against `SELECT vt.id`), so every unchanged view read as
 * changed between a 15 and a 17 database, and the migration dropped and
 * recreated it. Each case builds the same object on both servers and requires
 * "identical"; a changed copy must still be reported.
 *
 * Needs a second server of another major version: DB_HOST_POSTGRES_OTHER and
 * DB_PORT_POSTGRES_OTHER. Skips without one.
 */
class CrossVersionPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_xv';

    private string $otherHost;
    private string $otherPort;
    private ?PDO $otherAdmin = null;
    /** @var string[] */
    private array $otherCreated = [];

    private const BASE = 'CREATE TABLE vt (id int PRIMARY KEY, name text, a int, b int, status varchar(20));';

    /** Each kind, written once and built the same way on both servers. */
    private const KINDS = [
        'view'    => 'CREATE VIEW vt_v AS SELECT vt.id, vt.name FROM vt WHERE vt.a = 1 AND vt.b = 2;',
        'join'    => 'CREATE TABLE vu (id int PRIMARY KEY, vt_id int); CREATE VIEW vj AS SELECT vt.id, vu.id AS u FROM vt JOIN vu ON vu.vt_id = vt.id WHERE vt.a > 0;',
        'matview' => 'CREATE MATERIALIZED VIEW vt_m AS SELECT vt.id FROM vt WHERE (vt.a = 1);',
        'check'   => "ALTER TABLE vt ADD CONSTRAINT c CHECK (status IN ('draft', 'active') AND a > b);",
        'policy'  => 'ALTER TABLE vt ENABLE ROW LEVEL SECURITY; CREATE POLICY p ON vt USING (a = 1 AND b = 2);',
        'index'   => 'CREATE INDEX i ON vt (id) WHERE a = 1 AND b = 2;',
        // An ICU collation's locale moved between catalog columns (collcollate
        // before 15, colliculocale in 15 and 16, colllocale from 17).
        'collation' => "CREATE COLLATION vt_ci (provider = icu, locale = 'und-u-ks-level2', deterministic = false);
                        ALTER TABLE vt ADD COLUMN folded text COLLATE vt_ci;",
        // From PostgreSQL 18 a NOT NULL is a constraint, and a partition's is
        // inherited under its parent's name: read as a constraint of its own,
        // each partition, and the parent, differed from an older server.
        'partitions' => "CREATE TABLE ev (id int, at date NOT NULL, a int NOT NULL) PARTITION BY RANGE (at);
                         CREATE TABLE ev_2026 PARTITION OF ev FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
                         CREATE TABLE ev_2027 PARTITION OF ev FOR VALUES FROM ('2027-01-01') TO ('2028-01-01');
                         CREATE INDEX ev_i ON ev (id) WHERE a = 1;",
        // A view recreated for nothing had its comments set again, which
        // read as comments changed although they were identical.
        'comments' => "CREATE VIEW vt_c AS SELECT vt.id, vt.name FROM vt WHERE vt.a = 1;
                       CREATE MATERIALIZED VIEW vt_cm AS SELECT vt.id FROM vt WHERE vt.a = 1;
                       COMMENT ON VIEW vt_c IS 'v'; COMMENT ON COLUMN vt_c.name IS 'vn'; COMMENT ON MATERIALIZED VIEW vt_cm IS 'm';",
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $host = getenv('DB_HOST_POSTGRES_OTHER') ?: null;
        $port = getenv('DB_PORT_POSTGRES_OTHER') ?: null;
        if (!$host || !$port) {
            $this->markTestSkipped('DB_HOST_POSTGRES_OTHER and DB_PORT_POSTGRES_OTHER are not set.');
        }
        $this->otherHost = $host;
        $this->otherPort = $port;
        $this->otherAdmin = new PDO("pgsql:host=$host;port=$port;dbname=diff1", $this->user, $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other = (int) $this->otherAdmin->query('SHOW server_version_num')->fetchColumn();
        if (intdiv($other, 10000) === intdiv($this->serverVersion, 10000)) {
            $this->markTestSkipped('Both servers run the same major version.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->otherCreated as $db) {
            $this->otherAdmin->exec("DROP DATABASE IF EXISTS $db WITH (FORCE)");
        }
        $this->otherAdmin = null;
        parent::tearDown();
    }

    /** A database on the second server. */
    private function otherDb(string $name, string $sql): string
    {
        $db = "{$this->prefix}_$name";
        $this->otherAdmin->exec("DROP DATABASE IF EXISTS $db WITH (FORCE)");
        $this->otherAdmin->exec("CREATE DATABASE $db");
        $this->otherCreated[] = $db;
        (new PDO("pgsql:host={$this->otherHost};port={$this->otherPort};dbname=$db", $this->user, $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec($sql);
        return $db;
    }

    /** The UP from `$source` on this server to `$target` on the other, or null when identical. */
    private function upAcross(string $source, string $target, bool $targetReadOnly = false): ?string
    {
        $out = tempnam(sys_get_temp_dir(), $this->prefix . '_') . '.sql';
        $otherUrl = "postgres://{$this->user}:{$this->pass}@{$this->otherHost}:{$this->otherPort}/$target"
            . ($targetReadOnly ? '?options=-c%20default_transaction_read_only%3Don' : '');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/dbdiff.php')
            . ' diff --nocomments --allow-destructive'
            . ' --server1-url=' . escapeshellarg($this->url($source))
            . ' --server2-url=' . escapeshellarg($otherUrl)
            . ' --output=' . escapeshellarg($out) . ' 2>&1', $lines, $status);
        $this->assertSame(0, $status, "dbdiff failed:\n" . implode("\n", $lines));
        if (!file_exists($out)) {
            return null;
        }
        $sql = trim(preg_replace('/^--.*$/m', '', file_get_contents($out)));
        unlink($out);
        return $sql === '' ? null : $sql;
    }

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return array_combine(array_keys(self::KINDS), array_map(fn($k) => [$k], array_keys(self::KINDS)));
    }

    /** @dataProvider kinds */
    public function testTheSameObjectOnAnotherVersionIsIdentical(string $kind): void
    {
        $sql = self::BASE . self::KINDS[$kind];
        $source = $this->db("{$kind}_s", $sql);
        $target = $this->otherDb("{$kind}_t", $sql);
        $this->assertNull($this->upAcross($source, $target), "$kind: the same definition on another version was reported as a change");
    }

    /** @dataProvider kinds */
    public function testARealChangeIsStillReported(string $kind): void
    {
        $source = $this->db("{$kind}_rs", self::BASE . self::KINDS[$kind]);
        $target = $this->otherDb("{$kind}_rt", self::BASE . str_replace(['a = 1', 'a > 0', 'a > b', 'ks-level2'], ['a = 9', 'a > 9', 'a < b', 'ks-level1'], self::KINDS[$kind]));
        $this->assertNotNull($this->upAcross($source, $target), "$kind: a real change was hidden as equivalent");
    }

    /**
     * A read-only server cannot render a definition, so the other renders
     * both: the target, or here the source when it is the target that is
     * read-only.
     *
     * @dataProvider kinds
     */
    public function testTheSameObjectOverAReadOnlyTargetIsIdentical(string $kind): void
    {
        $sql = self::BASE . self::KINDS[$kind];
        $source = $this->db("{$kind}_ros", $sql);
        $target = $this->otherDb("{$kind}_rot", $sql);
        $this->assertNull($this->upAcross($source, $target, true), "$kind: a read-only target turned the same definition into a change");
    }

    /** @dataProvider kinds */
    public function testTheSameObjectOverAReadOnlySourceIsIdentical(string $kind): void
    {
        $sql = self::BASE . self::KINDS[$kind];
        $source = $this->db("{$kind}_rss", $sql);
        $target = $this->otherDb("{$kind}_rst", $sql);
        $this->readOnly = [$source];
        $this->assertNull($this->upAcross($source, $target), "$kind: a read-only source turned the same definition into a change");
    }

    /** @dataProvider kinds */
    public function testARealChangeIsStillReportedOverAReadOnlyTarget(string $kind): void
    {
        $source = $this->db("{$kind}_rrs", self::BASE . self::KINDS[$kind]);
        $target = $this->otherDb("{$kind}_rrt", self::BASE . str_replace(['a = 1', 'a > 0', 'a > b', 'ks-level2'], ['a = 9', 'a > 9', 'a < b', 'ks-level1'], self::KINDS[$kind]));
        $this->assertNotNull($this->upAcross($source, $target, true), "$kind: a real change was hidden as equivalent");
    }
}
