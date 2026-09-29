<?php

namespace Tests\Unit;

use DBDiff\DB\Support\PgDumpRenderer;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The pg_dump invocation the renderer builds.
 *
 * Issue #220 narrowed the dump to the schema being diffed. That is a
 * performance property and nothing else: pg_dump issues a query per function,
 * so dumping a whole Supabase database charged a round trip for every function
 * in auth, storage, realtime and the rest — schemas DBDiff never reads. The
 * rendered SQL is byte-identical either way, because the table of contents is
 * filtered by schema when it is read, so no output test would fail if the flag
 * were dropped and the cost would come back unnoticed. Hence these.
 *
 * No database and no pg_dump binary are needed: the command is assembled from
 * connection config, so it can be asserted on directly.
 */
class PgDumpCommandTest extends TestCase
{
    /**
     * @param array<string, string> $overrides
     * @return list<string>
     */
    private function command(array $overrides = [], string $archive = '/tmp/archive'): array
    {
        $connection = new Connection(
            static fn () => null,
            $overrides['database'] ?? 'thedb',
            '',
            array_merge([
                'driver'   => 'pgsql',
                'host'     => 'db.example.invalid',
                'port'     => '5432',
                'database' => 'thedb',
                'username' => 'someone',
                'password' => 'secret',
                'schema'   => 'public',
            ], $overrides)
        );

        $method = new ReflectionMethod(PgDumpRenderer::class, 'dumpCommand');
        $method->setAccessible(true);

        /** @var list<string> $command */
        $command = $method->invoke(null, $connection, $archive);

        return $command;
    }

    public function testTheDumpIsNarrowedToOneSchema(): void
    {
        $this->assertContains('--schema=public', $this->command());
    }

    public function testItNarrowsToTheConfiguredSchema(): void
    {
        // renderFor() reads the TOC with this same config value, so the dump
        // and the filter cannot disagree about which schema is being diffed.
        $this->assertContains('--schema=reporting', $this->command(['schema' => 'reporting']));
    }

    public function testAnAbsentSchemaFallsBackToPublic(): void
    {
        $this->assertContains('--schema=public', $this->command(['schema' => '']));
    }

    public function testItDumpsStructureOnlyInTheCustomFormat(): void
    {
        $command = $this->command();

        // --schema-only: this renders DDL, and the data diff is a separate
        // path. --format=custom: pg_restore -L needs an archive to select
        // single objects out of, which a plain SQL dump cannot provide.
        $this->assertContains('--schema-only', $command);
        $this->assertContains('--format=custom', $command);
    }

    public function testTheArchivePathIsPassedAsGiven(): void
    {
        $this->assertContains('--file=/tmp/somewhere-else', $this->command([], '/tmp/somewhere-else'));
    }

    public function testThePasswordNeverReachesTheCommandLine(): void
    {
        // It travels in the environment instead, where `ps` cannot read it.
        foreach ($this->command() as $argument) {
            $this->assertStringNotContainsString('secret', $argument);
        }
    }

    public function testTheConnectionIsAddressedAsAUrl(): void
    {
        $command = $this->command();

        $this->assertContains('--dbname=postgresql://someone@db.example.invalid:5432/thedb', $command);
    }

    public function testPgDumpIsTheFirstArgument(): void
    {
        // And is overridable, so a pinned client can be used against an older
        // server without changing PATH.
        $this->assertSame('pg_dump', $this->command()[0]);
    }

    public function testNothingElseIsPassed(): void
    {
        // A dump that quietly grows a flag is a dump whose cost or output has
        // changed. Six arguments, no more.
        $this->assertCount(6, $this->command());
    }
}
