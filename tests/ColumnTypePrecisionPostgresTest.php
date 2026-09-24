<?php

use DBDiff\DB\Adapters\PostgresAdapter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Column types that take an optional precision, against a live server
 * (issue #215).
 *
 * `information_schema.columns.datetime_precision` reports 6 both for a column
 * declared `timestamptz` and for one declared `timestamptz(6)`, so a generator
 * that reads it cannot tell a precision that was asked for from one that was
 * merely defaulted. Reading it emitted `timestamptz(6)` for a bare column:
 * behaviourally identical, a different type in the catalog, and therefore a
 * migration that did not reproduce the column it came from.
 *
 * The same test was wrong in the other direction — it dropped a declared
 * `timestamp(0)`, 0 being a legal precision — and `time`/`timetz` lost any
 * precision at all. So the assertions here run in both directions: a precision
 * that was never declared must not appear, and one that was must survive.
 *
 * The final test is the one that matters most. It applies the generated column
 * DDL and compares `format_type` on both sides, which is the property a
 * consumer verifying a migration actually checks — and is what the reported
 * failure looked like from the outside.
 *
 * Skips automatically when pdo_pgsql is missing or DB_HOST_POSTGRES is unset.
 */
class ColumnTypePrecisionPostgresTest extends TestCase
{
    private string $db = 'dbdiff_column_precision';
    private ?PDO $adminDb = null;
    private $connection;
    private PostgresAdapter $adapter;

    /** Declared type => what the generated DDL must say. */
    private const CASES = [
        // No modifier given: nothing may be invented.
        'c_timestamptz' => ['timestamptz',     'timestamptz'],
        'c_timestamp'   => ['timestamp',       'timestamp'],
        'c_time'        => ['time',            'time'],
        'c_timetz'      => ['timetz',          'timetz'],
        'c_interval'    => ['interval',        'interval'],
        // A modifier given: it must survive, including a zero.
        'p_timestamptz' => ['timestamptz(3)',  'timestamptz(3)'],
        'p_timestamp'   => ['timestamp(0)',    'timestamp(0)'],
        'p_time'        => ['time(3)',         'time(3)'],
        'p_timetz'      => ['timetz(2)',       'timetz(2)'],
        // An explicit 6 is not the same statement as no modifier, so it stays.
        'e_timestamptz' => ['timestamptz(6)',  'timestamptz(6)'],
        // interval's modifier carries a field range as well as a precision,
        // which no single number can express.
        'i_fields'      => ['interval day to second',    'interval day to second'],
        'i_both'        => ['interval day to second(3)', 'interval day to second(3)'],
        'i_year'        => ['interval year to month',    'interval year to month'],
        // Types whose optional modifier already worked, kept as guards.
        'n_numeric'     => ['numeric',         'numeric'],
        'n_numeric_ps'  => ['numeric(10,2)',   'numeric(10,2)'],
        'v_varchar'     => ['varchar',         'varchar'],
        'v_varchar_len' => ['varchar(50)',     'varchar(50)'],
    ];

    private function dropScratchDb(): void
    {
        try {
            $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db} WITH (FORCE)");
        } catch (PDOException $e) {
            $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db}");
        }
    }

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded.');
        }
        $host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set.');
        }

        // Same convention as PgDumpRendererPostgresTest: CI sets both, and a
        // local server on another port needs only the env var.
        $port = getenv('DB_PORT_POSTGRES') ?: '5432';
        $user = getenv('DB_USER_POSTGRES') ?: 'dbdiff';
        $pass = getenv('DB_PASS_POSTGRES') ?: 'rootpass';

        $this->adminDb = new PDO(
            "pgsql:host=$host;port=$port;dbname=diff1",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->dropScratchDb();
        $this->adminDb->exec("CREATE DATABASE {$this->db}");

        $capsule    = new Capsule;
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, function ($event) {
            $event->statement->setFetchMode(PDO::FETCH_ASSOC);
        });
        $capsule->setEventDispatcher($dispatcher);
        $capsule->addConnection([
            'driver'   => 'pgsql',
            'host'     => $host,
            'port'     => $port,
            'database' => $this->db,
            'username' => $user,
            'password' => $pass,
            'charset'  => 'utf8',
            'schema'   => 'public',
        ], 'precision');

        $this->connection = $capsule->getConnection('precision');
        $this->adapter    = new PostgresAdapter();

        $cols = [];
        foreach (self::CASES as $name => [$declared, $_expected]) {
            $cols[] = "$name $declared";
        }

        $this->connection->unprepared(
            "CREATE TABLE declared (id integer PRIMARY KEY, " . implode(', ', $cols) . ");\n"
            . "CREATE TABLE empty_copy (id integer PRIMARY KEY);"
        );
    }

    protected function tearDown(): void
    {
        if ($this->adminDb) {
            $this->dropScratchDb();
            $this->adminDb = null;
        }
    }

    /** The generated column DDL, keyed by column name. */
    private function columnDdl(): array
    {
        return $this->adapter->getTableSchema($this->connection, 'declared')['columns'];
    }

    public function testEmitsNoPrecisionThatWasNeverDeclared(): void
    {
        $columns = $this->columnDdl();

        foreach (['c_timestamptz', 'c_timestamp', 'c_time', 'c_timetz', 'c_interval'] as $name) {
            $this->assertSame(
                '"' . $name . '" ' . self::CASES[$name][1],
                $columns[$name],
                "$name was declared without a precision, so none may be emitted"
            );
        }
    }

    public function testKeepsAPrecisionThatWasDeclared(): void
    {
        $columns = $this->columnDdl();

        foreach (['p_timestamptz', 'p_timestamp', 'p_time', 'p_timetz', 'e_timestamptz'] as $name) {
            $this->assertSame(
                '"' . $name . '" ' . self::CASES[$name][1],
                $columns[$name],
                "$name declared a precision, which must survive"
            );
        }
    }

    public function testKeepsAPrecisionOfZero(): void
    {
        // Guarded on its own because the previous implementation tested
        // `datetime_precision > 0`, which silently discarded this one.
        $this->assertSame('"p_timestamp" timestamp(0)', $this->columnDdl()['p_timestamp']);
    }

    public function testKeepsAnIntervalFieldRange(): void
    {
        $columns = $this->columnDdl();

        foreach (['i_fields', 'i_both', 'i_year'] as $name) {
            $this->assertSame(
                '"' . $name . '" ' . self::CASES[$name][1],
                $columns[$name],
                "$name carries a field range, not just a precision"
            );
        }
    }

    public function testEveryCaseMatchesItsDeclaration(): void
    {
        $columns = $this->columnDdl();

        foreach (self::CASES as $name => [$_declared, $expected]) {
            $this->assertSame('"' . $name . '" ' . $expected, $columns[$name], $name);
        }
    }

    /**
     * The property the issue was really about: applying the generated DDL has to
     * produce the same catalog type, or a consumer that verifies convergence
     * rejects an otherwise valid migration.
     */
    public function testGeneratedDdlReproducesTheCatalogType(): void
    {
        $columns = $this->columnDdl();

        $adds = [];
        foreach (array_keys(self::CASES) as $name) {
            $adds[] = "ALTER TABLE empty_copy ADD COLUMN {$columns[$name]};";
        }
        $this->connection->unprepared(implode("\n", $adds));

        $typesOf = function (string $table): array {
            $rows = $this->connection->select(
                "SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS rendered
                   FROM pg_attribute a
                  WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped
                  ORDER BY a.attname",
                [$table]
            );
            $out = [];
            foreach ($rows as $row) {
                $out[$row['attname']] = $row['rendered'];
            }
            return $out;
        };

        $source = $typesOf('declared');
        $applied = $typesOf('empty_copy');

        foreach (array_keys(self::CASES) as $name) {
            $this->assertSame(
                $source[$name],
                $applied[$name] ?? null,
                "applying the generated DDL for $name did not reproduce its type"
            );
        }
    }
}
