<?php

/**
 * Values read and written under a database's own output settings.
 *
 * DBDiff compares values as PostgreSQL prints them and writes that text into
 * its SQL. A source database set to print dates day first, or doubles with
 * fewer digits, or in another time zone, made the migration silently wrong:
 * 3 April arrived as 4 March and 0.30000000000000004 as 0.3, and the same
 * instant in two time zones compared unequal. See SessionFormats.
 */
class SessionFormatsRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_fmt';

    private const TABLE = 'CREATE TABLE t (id int PRIMARY KEY, d date, ts timestamp, tz timestamptz, f float8, r real, iv interval, b bytea);';
    private const ROWS = <<<'SQL'
        INSERT INTO t VALUES
          (1, '2026-04-03', '2026-04-03 10:00', '2026-04-03 10:00+00', 0.30000000000000004, 1.1, '-1 days +02:03:04', '\x00ff5c'),
          (2, '2026-12-01', '2026-01-12 23:59:59.5', '2026-06-30 23:00+00', 1/3::float8, 16777217, '1 year -2 mons +3 days -04:05:06', '\x5c7830'),
          (3, '4713-01-01 BC', '4713-01-01 00:00 BC', '1800-01-01 00:00+00', 5e-324, 1.4e-45, '-178000000 years', '\x');
        SQL;

    /** Every setting that changes how a value prints, the other way from the target's. */
    private const SKEWED = [
        "DateStyle = 'SQL, DMY'",
        'extra_float_digits = 0',
        "IntervalStyle = 'sql_standard'",
        "TimeZone = 'Pacific/Chatham'",
        "bytea_output = 'escape'",
    ];

    public function testASourcesOwnOutputSettingsDoNotChangeItsValues(): void
    {
        $source = $this->skewed($this->db('s', self::TABLE . self::ROWS));
        $target = $this->db('t', self::TABLE);

        [$up] = $this->diff($source, $target, 'data');

        $this->connect($target)->exec($up);
        $this->assertSame($this->contents($source), $this->contents($target), "UP makes the target the source:\n$up");
        $this->assertNull($this->diff($source, $target, 'data'), 'and a second diff finds nothing');
    }

    /** The same rows on two databases printing them differently are identical. */
    public function testTheSameRowsPrintedDifferentlyAreIdentical(): void
    {
        $source = $this->skewed($this->db('ss', self::TABLE . self::ROWS));
        $target = $this->db('st', self::TABLE . self::ROWS);

        $this->assertNull($this->diff($source, $target, 'data'), $this->lastLog);
    }

    private function skewed(string $db): string
    {
        foreach (self::SKEWED as $setting) {
            $this->connect($db)->exec("ALTER DATABASE $db SET $setting");
        }
        return $db;
    }

    /** The rows, printed the same way whichever database they are in. */
    private function contents(string $db): array
    {
        $pdo = $this->connect($db);
        $pdo->exec("SET DateStyle = 'ISO, MDY'; SET IntervalStyle = 'postgres'; SET extra_float_digits = 3; SET TimeZone = 'UTC'; SET bytea_output = 'hex'");
        return $pdo->query('SELECT t::text FROM t ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}
