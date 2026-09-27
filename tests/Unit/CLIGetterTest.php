<?php

namespace Tests\Unit;

use DBDiff\Exceptions\CLIException;
use DBDiff\Params\CLIGetter;
use PHPUnit\Framework\TestCase;

/**
 * What the legacy CLI params path accepts, and exactly what it makes of it.
 *
 * These tests were written against the aura/cli implementation and describe its
 * behaviour rather than an idealised version of it — quirks included, because
 * the point is that replacing the parser changes nothing a caller can observe.
 * Several of the expectations below are odd on purpose:
 *
 *   - `--type=` with an empty value yields `true`, not `''`
 *   - `--nocomments=0` leaves the property unset, because getParams() guards
 *     every option with a truthiness test and `'0'` is falsy in PHP
 *   - `--type schema` sets `type` to `true` and leaves `schema` unconsumed: an
 *     optional-value option never eats the following token
 *   - an unrecognised option is ignored in silence
 *
 * This path is reached when nobody has called ParamsFactory::set() — the
 * Symfony commands always do, so it serves library callers and the older
 * argv-driven flow.
 */
class CLIGetterTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $globals = [];

    protected function setUp(): void
    {
        $this->globals = [
            'argv' => $GLOBALS['argv'] ?? null,
            'argc' => $GLOBALS['argc'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->globals as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
    }

    /** Run getParams() with this argv, as the real entry point would. */
    private function paramsFor(array $argv): array
    {
        $GLOBALS['argv'] = $argv;
        $GLOBALS['argc'] = count($argv);

        return (array) (new CLIGetter)->getParams();
    }

    private const DB_INPUT = [
        'kind'   => 'db',
        'source' => ['server' => 'srv1', 'db' => 'db1'],
        'target' => ['server' => 'srv2', 'db' => 'db2'],
    ];

    // ── The positional argument ───────────────────────────────────────────────

    public function testReadsTheComparisonFromTheFirstPositionalArgument(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2']);

        $this->assertSame(self::DB_INPUT, $params['input']);
        // Always present, and false unless asked for.
        $this->assertFalse($params['allowDestructive']);
    }

    public function testReadsATableComparison(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1.orders:srv2.db2.orders']);

        $this->assertSame([
            'kind'   => 'table',
            'source' => ['server' => 'srv1', 'db' => 'db1', 'table' => 'orders'],
            'target' => ['server' => 'srv2', 'db' => 'db2', 'table' => 'orders'],
        ], $params['input']);
    }

    public function testFindsThePositionalAfterLeadingOptions(): void
    {
        // Options may precede the comparison; it is still the first argument
        // that is not an option.
        $params = $this->paramsFor(['dbdiff', '--debug', '--type=data', 'srv1.db1:srv2.db2']);

        $this->assertSame(self::DB_INPUT, $params['input']);
        $this->assertSame('data', $params['type']);
        $this->assertTrue($params['debug']);
    }

    public function testTreatsADoubleDashAsNeitherOptionNorArgument(): void
    {
        $params = $this->paramsFor(['dbdiff', '--', 'srv1.db1:srv2.db2']);

        $this->assertSame(self::DB_INPUT, $params['input']);
    }

    // ── Option value forms ────────────────────────────────────────────────────

    public function testTakesAValueFromEquals(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--type=schema']);

        $this->assertSame('schema', $params['type']);
    }

    public function testABareOptionIsTrue(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--nocomments']);

        $this->assertTrue($params['nocomments']);
    }

    public function testAnEmptyValueIsAlsoTrue(): void
    {
        // `--type=` is indistinguishable from `--type` here.
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--type=']);

        $this->assertTrue($params['type']);
    }

    public function testDoesNotConsumeTheFollowingTokenAsAValue(): void
    {
        // `--type schema` is not `--type=schema`: the option is a bare flag and
        // `schema` becomes a further positional argument, which nothing reads.
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--type', 'schema']);

        $this->assertTrue($params['type']);
        $this->assertSame(self::DB_INPUT, $params['input']);
    }

    public function testKeepsAValueThatLooksLikeAnOption(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--output=--weird']);

        $this->assertSame('--weird', $params['output']);
    }

    public function testTheLastOccurrenceOfAnOptionWins(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--type=schema', '--type=data']);

        $this->assertSame('data', $params['type']);
    }

    public function testIgnoresAnUnrecognisedOption(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--bogus=1']);

        $this->assertArrayNotHasKey('bogus', $params);
        $this->assertSame(self::DB_INPUT, $params['input']);
    }

    public function testAFalsyValueLeavesThePropertyUnset(): void
    {
        // Every option is guarded by a truthiness test, and '0' is falsy in PHP,
        // so `--nocomments=0` reads as not passing the option at all.
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--nocomments=0']);

        $this->assertArrayNotHasKey('nocomments', $params);
    }

    public function testCarriesAStringValueThroughUnchanged(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--nocomments=1']);

        $this->assertSame('1', $params['nocomments']);
    }

    // ── Every option the parser knows ─────────────────────────────────────────

    public function testAcceptsEveryDeclaredOption(): void
    {
        $params = $this->paramsFor([
            'dbdiff', 'srv1.db1:srv2.db2',
            '--server1=u1:p1@h1:1111',
            '--server2=u2:p2@h2:2222',
            '--format=fmt',
            '--template=tpl',
            '--type=schema',
            '--include=up',
            '--nocomments=yes',
            '--config=cfg.yml',
            '--output=out.sql',
            '--debug=1',
            '--driver=mysql',
        ]);

        $this->assertSame(['user' => 'u1', 'password' => 'p1', 'host' => 'h1', 'port' => '1111'], $params['server1']);
        $this->assertSame(['user' => 'u2', 'password' => 'p2', 'host' => 'h2', 'port' => '2222'], $params['server2']);
        $this->assertSame('fmt', $params['format']);
        $this->assertSame('tpl', $params['template']);
        $this->assertSame('schema', $params['type']);
        $this->assertSame('up', $params['include']);
        $this->assertSame('yes', $params['nocomments']);
        $this->assertSame('cfg.yml', $params['config']);
        $this->assertSame('out.sql', $params['output']);
        $this->assertSame('1', $params['debug']);
        $this->assertSame('mysql', $params['driver']);
    }

    public function testLowercasesTheDriver(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--driver=PGSQL']);

        $this->assertSame('pgsql', $params['driver']);
    }

    public function testSupabaseImpliesPostgresOverSsl(): void
    {
        $params = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--supabase']);

        $this->assertSame('pgsql', $params['driver']);
        $this->assertSame('require', $params['sslmode']);
    }

    public function testAllowDestructiveIsAlwaysABoolean(): void
    {
        $off = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2']);
        $on  = $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2', '--allow-destructive']);

        $this->assertFalse($off['allowDestructive']);
        $this->assertTrue($on['allowDestructive']);
    }

    // ── Refusals ──────────────────────────────────────────────────────────────

    public function testRefusesAnEmptyCommandLine(): void
    {
        $this->expectException(CLIException::class);
        $this->expectExceptionMessage('Missing input');
        $this->paramsFor(['dbdiff']);
    }

    public function testRefusesOptionsWithNoComparison(): void
    {
        $this->expectException(CLIException::class);
        $this->expectExceptionMessage('Missing input');
        $this->paramsFor(['dbdiff', '--debug']);
    }

    public function testRefusesASingleResource(): void
    {
        $this->expectException(CLIException::class);
        $this->expectExceptionMessage('You need two resources to compare');
        $this->paramsFor(['dbdiff', 'srv1.db1']);
    }

    public function testRefusesResourcesOfDifferentDepth(): void
    {
        $this->expectException(CLIException::class);
        $this->expectExceptionMessage('The two resources must be of the same kind');
        $this->paramsFor(['dbdiff', 'srv1.db1:srv2.db2.orders']);
    }

    public function testRefusesADepthItDoesNotUnderstand(): void
    {
        $this->expectException(CLIException::class);
        $this->paramsFor(['dbdiff', 'a.b.c.d:e.f.g.h']);
    }
}
