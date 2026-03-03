<?php

use DBDiff\Migration\Command\DiffCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for DiffCommand.
 *
 * Only the error-path surface is tested here because the happy path requires a
 * live database connection (DBDiff::getDiffResult). All tested code paths run
 * entirely within resolveConnections(), parseInput(), applyServerUrls(), or
 * normaliseInclude() — before any DB interaction occurs.
 */
class DiffCommandTest extends TestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $app = new Application;
        $app->add(new DiffCommand);
        $cmd = $app->find('diff');
        $this->tester = new CommandTester($cmd);
    }

    // ── resolveConnections: missing input / URL ──────────────────────────────

    public function testMissingInputAndNoUrlsFails(): void
    {
        $exitCode = $this->tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Missing input argument',
            $this->tester->getDisplay()
        );
    }

    public function testServer1UrlWithoutServer2UrlFails(): void
    {
        $exitCode = $this->tester->execute([
            '--server1-url' => 'postgres://u:p@host/db1',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            '--server2-url is required',
            $this->tester->getDisplay()
        );
    }

    public function testServer2UrlWithoutServer1UrlFails(): void
    {
        $exitCode = $this->tester->execute([
            '--server2-url' => 'postgres://u:p@host/db2',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            '--server1-url is required',
            $this->tester->getDisplay()
        );
    }

    // ── parseInput: malformed input strings ─────────────────────────────────

    public function testInputWithoutColonFails(): void
    {
        $exitCode = $this->tester->execute(['input' => 'server1.db1']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('source:target', $this->tester->getDisplay());
    }

    public function testInputWithMismatchedDepthFails(): void
    {
        // source has 2 parts, target has 3 — must be same kind
        $exitCode = $this->tester->execute(['input' => 'server1.db1:server2.db2.tbl']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('same kind', $this->tester->getDisplay());
    }

    public function testInputWithTooManyPartsFails(): void
    {
        // 4-part input is not supported (db or table — nothing else)
        $exitCode = $this->tester->execute(['input' => 'a.b.c.d:e.f.g.h']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown input format', $this->tester->getDisplay());
    }

    // ── applyServerUrls: invalid DSN scheme ─────────────────────────────────

    public function testInvalidServer1UrlSchemeFails(): void
    {
        $exitCode = $this->tester->execute([
            '--server1-url' => 'mongodb://u:p@host/db',
            '--server2-url' => 'postgres://u:p@host/db',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('mongodb', $this->tester->getDisplay());
    }

    public function testInvalidServer2UrlSchemeFails(): void
    {
        $exitCode = $this->tester->execute([
            '--server1-url' => 'postgres://u:p@host/db',
            '--server2-url' => 'redis://host/0',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('redis', $this->tester->getDisplay());
    }

    // ── normaliseInclude (via reflection) ───────────────────────────────────

    /**
     * @dataProvider normaliseIncludeProvider
     */
    public function testNormaliseInclude(string $input, string $expected): void
    {
        $cmd    = new DiffCommand;
        $method = new \ReflectionMethod($cmd, 'normaliseInclude');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($cmd, $input));
    }

    public static function normaliseIncludeProvider(): array
    {
        return [
            'both literal'  => ['both',    'both'],
            'all alias'     => ['all',     'both'],
            'down'          => ['down',    'down'],
            'up explicit'   => ['up',      'up'],
            'unknown→up'    => ['invalid', 'up'],
            'uppercase BOTH'=> ['BOTH',    'both'],
            'uppercase DOWN'=> ['DOWN',    'down'],
            'uppercase UP'  => ['UP',      'up'],
        ];
    }

    // ── parseInput (via reflection): valid structures ────────────────────────

    public function testParseInputDbLevel(): void
    {
        $cmd    = new DiffCommand;
        $method = new \ReflectionMethod($cmd, 'parseInput');
        $method->setAccessible(true);

        $result = $method->invoke($cmd, 'server1.db1:server2.db2');

        $this->assertSame('db', $result['kind']);
        $this->assertSame('server1', $result['source']['server']);
        $this->assertSame('db1',     $result['source']['db']);
        $this->assertSame('server2', $result['target']['server']);
        $this->assertSame('db2',     $result['target']['db']);
    }

    public function testParseInputTableLevel(): void
    {
        $cmd    = new DiffCommand;
        $method = new \ReflectionMethod($cmd, 'parseInput');
        $method->setAccessible(true);

        $result = $method->invoke($cmd, 'server1.db1.users:server2.db2.users');

        $this->assertSame('table', $result['kind']);
        $this->assertSame('users', $result['source']['table']);
        $this->assertSame('users', $result['target']['table']);
    }

    // ── buildParams (via reflection) ─────────────────────────────────────────

    public function testBuildParamsDefaults(): void
    {
        $cmd = new DiffCommand;

        // Wire up the command into an Application so options are configured
        $app = new Application;
        $app->add($cmd);

        $method = new \ReflectionMethod($cmd, 'buildParams');
        $method->setAccessible(true);

        // Create a real input with default option values
        $cmdDef  = $cmd->getDefinition();
        $input   = new \Symfony\Component\Console\Input\ArrayInput([], $cmdDef);

        $params = $method->invoke($cmd, $input);

        $this->assertSame('native', $params->format);
        $this->assertSame('schema', $params->type);
        $this->assertSame('up',     $params->include);
        $this->assertFalse($params->nocomments);
        $this->assertFalse($params->debug);
    }

    public function testBuildParamsCustomValues(): void
    {
        $cmd = new DiffCommand;
        $app = new Application;
        $app->add($cmd);

        $method = new \ReflectionMethod($cmd, 'buildParams');
        $method->setAccessible(true);

        $cmdDef = $cmd->getDefinition();
        $input  = new \Symfony\Component\Console\Input\ArrayInput([
            '--format'      => 'flyway',
            '--type'        => 'all',
            '--include'     => 'both',
            '--description' => 'my desc',
            '--debug'       => true,
        ], $cmdDef);

        $params = $method->invoke($cmd, $input);

        $this->assertSame('flyway',   $params->format);
        $this->assertSame('all',      $params->type);
        $this->assertSame('both',     $params->include);  // normalised by buildParams
        $this->assertSame('my desc',  $params->description);
        $this->assertTrue($params->debug);
    }
}
