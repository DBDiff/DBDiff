<?php

/**
 * Every case in @akal/pg-conformance's `migrations` corpus, round-tripped
 * against a live server in both directions. See PostgresRoundTripTestCase.
 *
 * The corpus is shared with SupaForge, which runs the same cases through its
 * own CLI. A case added there is exercised here on the next version bump,
 * with no test written for it, so DBDiff's own suites keep only what is
 * specific to DBDiff: the SQL it generates, the state it must carry along.
 *
 * Each direction is a full round trip: the UP must reach the source and the
 * DOWN restore the target, and the case's `preserve` queries must return the
 * same rows on the target throughout — the data a correct migration keeps.
 *
 * Not named *PostgresTest, so the PHP × PostgreSQL matrix does not run it
 * twenty-five times: it is the `Corpus` suite, run once per PostgreSQL
 * version. PG_CONFORMANCE_DIR points it at a checkout of the corpus instead
 * of the installed package, to try cases before they are released.
 */
class CorpusMigrationsRoundTripTest extends PostgresRoundTripTestCase
{
    use CorpusKnownFailures;
    use CorpusState;

    protected string $prefix = 'dbdiff_corpus';

    /** Every schema: the corpus has cases outside `public`. */
    protected string $schemas = '*';

    /** @return array<string, array{string, string, string, list<string>, int}> */
    public static function cases(): array
    {
        $dir = getenv('PG_CONFORMANCE_DIR') ?: dirname(__DIR__) . '/node_modules/@akal/pg-conformance';
        $path = "$dir/corpus/migrations.json";
        if (!is_file($path)) {
            // Failed, not skipped: a missing corpus would otherwise pass this
            // suite having run nothing.
            throw new RuntimeException("$path not found. Run `npm ci` in the DBDiff checkout.");
        }

        $cases = [];
        foreach (json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) as $i => $c) {
            $preserve = $c['preserve'] ?? [];
            $min = $c['minPgVersion'] ?? 0;
            $cases["{$c['id']} (before → after)"] = ["c{$i}f", $c['after'], $c['before'], $preserve, $min];
            $cases["{$c['id']} (after → before)"] = ["c{$i}r", $c['before'], $c['after'], $preserve, $min];
        }
        return $cases;
    }

    /**
     * @dataProvider cases
     * @param list<string> $preserve
     */
    public function testTheMigrationRoundTripsAndKeepsTheRows(
        string $key,
        string $sourceSql,
        string $targetSql,
        array $preserve,
        int $minPgVersion,
    ): void {
        if ($minPgVersion && intdiv($this->serverVersion, 10000) < $minPgVersion) {
            $this->markTestSkipped("needs PostgreSQL $minPgVersion");
        }
        $this->expectingKnownFailures('migrations', fn() => $this->roundTrip($key, $sourceSql, $targetSql, $preserve));
    }

    /** @param list<string> $preserve */
    private function roundTrip(string $key, string $sourceSql, string $targetSql, array $preserve): void
    {
        $rows = fn(string $db) => array_map(fn(string $q) => $this->rows($db, $q), $preserve);

        $source   = $this->db("{$key}_s", $sourceSql);
        $target   = $this->db("{$key}_t", $targetSql);
        $original = $this->db("{$key}_o", $targetSql);
        $kept = $rows($target);
        $sourceMeta = $this->viewMetadata($source);
        $targetMeta = $this->viewMetadata($target);

        $migration = $this->diff($source, $target);
        $this->assertNotNull($migration, 'expected a difference');
        [$up, $down] = $migration;

        $this->apply($target, $up);
        $this->assertNull($this->diff($source, $target), "UP left a difference behind:\n$up");
        $this->assertSameState($source, $target, "UP did not reach the source's schema:\n$up");
        $this->assertEquals($sourceMeta, $this->viewMetadata($target), "UP lost view metadata:\n$up");
        $this->assertEquals($kept, $rows($target), "UP lost rows:\n$up");

        $this->apply($target, $down);
        $this->assertNull($this->diff($original, $target), "DOWN did not restore the target:\n$down");
        $this->assertSameState($original, $target, "DOWN did not restore the target's schema:\n$down");
        $this->assertEquals($targetMeta, $this->viewMetadata($target), "DOWN lost view metadata:\n$down");
        $this->assertEquals($kept, $rows($target), "DOWN lost rows:\n$down");
    }
}
