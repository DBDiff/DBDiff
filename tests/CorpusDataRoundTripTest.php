<?php

/**
 * Every case in @akalforge/pg-conformance's `data` corpus: the rows a data
 * migration must carry over, round-tripped against a live server.
 *
 * Both databases get the case's schema; the source its `after` rows and the
 * target its `before` rows. The UP must make the target's `compare` queries
 * return what the source's do, and the DOWN must make them return what they
 * did before. The values are the ones found written wrongly or not at all:
 * text needing quoting, JSON, bytea, a table with no key.
 *
 * Part of the `Corpus` suite, run once per PostgreSQL version; see
 * CorpusMigrationsRoundTripTest for PG_CONFORMANCE_DIR.
 */
class CorpusDataRoundTripTest extends PostgresRoundTripTestCase
{
    use CorpusKnownFailures;

    protected string $prefix = 'dbdiff_cdata';

    /** Every schema: the corpus has cases outside `public`. */
    protected string $schemas = '*';

    /** @return array<string, array{string, string, string, string, list<string>, int}> */
    public static function cases(): array
    {
        $dir = getenv('PG_CONFORMANCE_DIR') ?: dirname(__DIR__) . '/node_modules/@akalforge/pg-conformance';
        $path = "$dir/corpus/data.json";
        if (!is_file($path)) {
            throw new RuntimeException("$path not found. Run `npm ci` in the DBDiff checkout.");
        }

        $cases = [];
        foreach (json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) as $i => $c) {
            $cases[$c['id']] = ["d$i", $c['schema'], $c['before'], $c['after'], $c['compare'], $c['minPgVersion'] ?? 0];
        }
        return $cases;
    }

    /**
     * @dataProvider cases
     * @param list<string> $compare
     */
    public function testTheRowsRoundTrip(
        string $key,
        string $schema,
        string $before,
        string $after,
        array $compare,
        int $minPgVersion,
    ): void {
        if ($minPgVersion && intdiv($this->serverVersion, 10000) < $minPgVersion) {
            $this->markTestSkipped("needs PostgreSQL $minPgVersion");
        }
        $this->expectingKnownFailures('data', fn() => $this->roundTrip($key, $schema, $before, $after, $compare));
    }

    /** @param list<string> $compare */
    private function roundTrip(string $key, string $schema, string $before, string $after, array $compare): void
    {
        $rows = fn(string $db) => array_map(fn(string $q) => $this->rows($db, $q), $compare);

        $source = $this->db("{$key}_s", $schema . $after);
        $target = $this->db("{$key}_t", $schema . $before);
        $original = $rows($target);

        $migration = $this->diff($source, $target, 'data');
        $this->assertNotNull($migration, 'expected a difference');
        [$up, $down] = $migration;

        $this->apply($target, $up);
        $this->assertEquals($rows($source), $rows($target), "UP did not reach the source's rows:\n$up");

        $this->apply($target, $down);
        $this->assertEquals($original, $rows($target), "DOWN did not restore the target's rows:\n$down");
    }
}
