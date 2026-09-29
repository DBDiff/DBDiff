<?php

namespace Tests\Unit;

use DBDiff\DB\Adapters\PostgresAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The PDO options the Postgres adapter connects with.
 *
 * Issue #220: with PDO's defaults every catalog query costs three round trips —
 * PREPARE, EXECUTE, DEALLOCATE — and a diff makes dozens of them, so over a
 * link with any latency that is most of the wall clock. Disabling *named*
 * prepares makes each query a single round trip.
 *
 * The output of a diff is unchanged by this, which is the whole point and also
 * why nothing else in the suite would notice it being reverted: the schema
 * fixtures pass either way. These tests exist so the choice cannot be undone
 * silently, and so the *particular* choice is pinned — `ATTR_EMULATE_PREPARES`
 * would also remove the round trips, but by interpolating parameters
 * client-side, and the data diff sends values through these connections.
 */
class PostgresConnectionConfigTest extends TestCase
{
    /** The attribute under either spelling; both carry the same value. */
    private function disablePrepares(): int
    {
        return defined('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
            ? constant('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
            : \PDO::PGSQL_ATTR_DISABLE_PREPARES;
    }

    private function config(array $server = [], string $db = 'somedb'): array
    {
        return (new PostgresAdapter())->buildConnectionConfig($server, $db);
    }

    public function testNamedPreparesAreDisabled(): void
    {
        $config = $this->config();

        $this->assertArrayHasKey('options', $config, 'no PDO options were set at all');
        $this->assertArrayHasKey(
            $this->disablePrepares(),
            $config['options'],
            'named prepares are not disabled, so every query costs three round trips (#220)'
        );
        $this->assertTrue($config['options'][$this->disablePrepares()]);
    }

    public function testParametersAreStillBoundServerSide(): void
    {
        $config = $this->config();

        // ATTR_EMULATE_PREPARES would also avoid the round trips, by building
        // the statement client-side. The data diff sends values through these
        // connections, so binding must stay server-side.
        $this->assertArrayNotHasKey(
            \PDO::ATTR_EMULATE_PREPARES,
            $config['options'],
            'parameters would be interpolated client-side'
        );
    }

    public function testTheAttributeResolvesOnThisPhpVersion(): void
    {
        // PHP 8.4 moved the PDO_PGSQL constants onto a Pdo\Pgsql class and
        // deprecated the PDO::PGSQL_* spellings. Whichever this runtime has,
        // the adapter must produce a usable integer attribute rather than null.
        $config = $this->config();
        $keys   = array_keys($config['options']);

        $this->assertCount(1, $keys);
        $this->assertIsInt($keys[0]);
        $this->assertSame($this->disablePrepares(), $keys[0]);
    }

    public function testTheRestOfTheConnectionIsUnchanged(): void
    {
        // The options were added to an existing config; nothing else about how
        // the adapter connects should have moved.
        $config = $this->config([
            'host'     => 'db.example.invalid',
            'port'     => '6543',
            'user'     => 'someone',
            'password' => 'secret',
            'sslmode'  => 'require',
        ], 'thedb');

        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame('db.example.invalid', $config['host']);
        $this->assertSame('6543', $config['port']);
        $this->assertSame('thedb', $config['database']);
        $this->assertSame('someone', $config['username']);
        $this->assertSame('secret', $config['password']);
        $this->assertSame('public', $config['schema']);
        $this->assertSame('require', $config['sslmode']);
    }

    public function testDefaultsSurvive(): void
    {
        $config = $this->config();

        $this->assertSame('localhost', $config['host']);
        $this->assertSame('5432', $config['port']);
        $this->assertSame('prefer', $config['sslmode']);
        $this->assertSame('public', $config['schema']);
    }
}
