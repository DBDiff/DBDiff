<?php

/**
 * pg_partman's partitions are its own to make and drop, and not compared.
 *
 * Two partition sets made on different days differ by a few partitions —
 * premade ahead, retired by retention — and the migration dropped the
 * target's extra ones, rows and all. Needs pg_partman on the server; skips
 * where it is not available.
 */
class PartmanPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_pm';

    protected function setUp(): void
    {
        parent::setUp();
        $available = $this->admin->query("SELECT count(*) FROM pg_available_extensions WHERE name = 'pg_partman'")->fetchColumn();
        if ((int) $available === 0) {
            $this->markTestSkipped('pg_partman is not available on this server.');
        }
    }

    private function partitioned(string $start): string
    {
        return "CREATE SCHEMA partman; CREATE EXTENSION pg_partman SCHEMA partman;
                CREATE TABLE events (id bigint, created_at timestamptz NOT NULL, body text) PARTITION BY RANGE (created_at);
                SELECT partman.create_parent('public.events', 'created_at', '1 day', p_premake => 2, p_start_partition => '$start');";
    }

    public function testPartitionsPgPartmanMadeAreNotCompared(): void
    {
        $source = $this->db('s', $this->partitioned('2026-10-10'));
        $target = $this->db('t', $this->partitioned('2026-10-05') . "INSERT INTO events VALUES (1, '2026-10-06 10:00', 'old row');");

        $this->assertNull($this->diff($source, $target), 'pg_partman partitions were compared');
    }

    public function testTheParentIsStillCompared(): void
    {
        $source = $this->db('ps', $this->partitioned('2026-10-10') . 'ALTER TABLE events ADD COLUMN source text;');
        $target = $this->db('pt', $this->partitioned('2026-10-10'));

        [$up] = $this->diff($source, $target);
        $this->assertStringContainsString('ADD COLUMN "source"', $up);
        $this->assertStringNotContainsString('events_p2026', $up);
    }
}
