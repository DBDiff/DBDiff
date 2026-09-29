<?php

namespace Tests\Unit;

use DBDiff\Diff\AlterEnum;
use DBDiff\SQLGen\DiffToSQL\AlterEnumSQL;
use PHPUnit\Framework\TestCase;

/**
 * Changing the labels of an enum (issue #228).
 *
 * The type used to be dropped and recreated in both directions, which
 * PostgreSQL refuses while any column still uses it:
 *
 *     ERROR:  cannot drop type order_state because other objects depend on it
 *
 * A column of that type is the normal case, so the generated migration could
 * not run. Adding a label needs no drop at all.
 */
class AlterEnumSQLTest extends TestCase
{
    private function enum(string $name, array $labels): string
    {
        $quoted = array_map(static fn($l) => "'" . str_replace("'", "''", $l) . "'", $labels);
        return 'CREATE TYPE "' . $name . '" AS ENUM (' . implode(', ', $quoted) . ')';
    }

    private function sqlFor(array $sourceLabels, array $targetLabels): AlterEnumSQL
    {
        return new AlterEnumSQL(new AlterEnum(
            'order_state',
            $this->enum('order_state', $sourceLabels),
            $this->enum('order_state', $targetLabels)
        ));
    }

    // ── Adding a label ───────────────────────────────────────────────────────

    public function testAppendedLabelIsAddedRatherThanReplacingTheType(): void
    {
        // The target is behind; making it match the source appends one label.
        $sql = $this->sqlFor(['new', 'paid', 'shipped'], ['new', 'paid'])->getUp();

        $this->assertStringContainsString("ADD VALUE IF NOT EXISTS 'shipped'", $sql);
        $this->assertStringNotContainsString('DROP TYPE', $sql);
        $this->assertStringNotContainsString('CREATE TYPE', $sql);
    }

    public function testAppendedLabelIsPositionedAfterItsNeighbour(): void
    {
        $sql = $this->sqlFor(['new', 'paid', 'shipped'], ['new', 'paid'])->getUp();

        $this->assertStringContainsString("ADD VALUE IF NOT EXISTS 'shipped' AFTER 'paid';", $sql);
    }

    public function testLabelInsertedInTheMiddleKeepsItsPlaceInTheOrdering(): void
    {
        // BEFORE/AFTER has been available since PostgreSQL 12, so a label does
        // not have to go on the end.
        $sql = $this->sqlFor(['a', 'b', 'c'], ['a', 'c'])->getUp();

        $this->assertStringContainsString("ADD VALUE IF NOT EXISTS 'b' AFTER 'a';", $sql);
        $this->assertStringNotContainsString('DROP TYPE', $sql);
    }

    public function testLabelAddedAtTheFrontAnchorsToTheOneAfterIt(): void
    {
        $sql = $this->sqlFor(['first', 'a', 'b'], ['a', 'b'])->getUp();

        $this->assertStringContainsString("ADD VALUE IF NOT EXISTS 'first' BEFORE 'a';", $sql);
    }

    public function testSeveralAddedLabelsEachGetAStatement(): void
    {
        $sql = $this->sqlFor(['a', 'b', 'c', 'd'], ['a', 'c'])->getUp();

        $this->assertSame(2, substr_count($sql, 'ADD VALUE'));
        $this->assertStringContainsString("'b' AFTER 'a'", $sql);
        $this->assertStringContainsString("'d' AFTER 'c'", $sql);
        $this->assertStringNotContainsString('DROP TYPE', $sql);
    }

    public function testAddedLabelsAreIdempotent(): void
    {
        // IF NOT EXISTS, so re-running a migration is not an error.
        $sql = $this->sqlFor(['a', 'b'], ['a'])->getUp();

        $this->assertStringContainsString('ADD VALUE IF NOT EXISTS', $sql);
    }

    public function testALabelWithAQuoteIsEscaped(): void
    {
        $sql = $this->sqlFor(['a', "it's"], ['a'])->getUp();

        $this->assertStringContainsString("ADD VALUE IF NOT EXISTS 'it''s'", $sql);
    }

    // ── Removing or reordering, which ADD VALUE cannot do ────────────────────

    public function testRemovedLabelStillReplacesTheType(): void
    {
        // There is no DROP VALUE. Doing this properly means migrating every
        // dependent column, which cannot be generated blind.
        $sql = $this->sqlFor(['a'], ['a', 'b'])->getUp();

        $this->assertStringContainsString('DROP TYPE', $sql);
        $this->assertStringContainsString('CREATE TYPE', $sql);
    }

    public function testReplacementSaysWhyItMayNotApply(): void
    {
        $sql = $this->sqlFor(['a'], ['a', 'b'])->getUp();

        $this->assertStringContainsString('while any column still uses it', $sql);
    }

    public function testReorderedLabelsReplaceTheType(): void
    {
        // ADD VALUE cannot move a label that already exists.
        $sql = $this->sqlFor(['b', 'a'], ['a', 'b'])->getUp();

        $this->assertStringContainsString('DROP TYPE', $sql);
    }

    // ── The two directions are decided separately ────────────────────────────

    public function testRevertingAnAdditionReplacesTheType(): void
    {
        // UP adds a label; DOWN would have to remove it, which it cannot.
        $alter = $this->sqlFor(['new', 'paid', 'shipped'], ['new', 'paid']);

        $this->assertStringContainsString('ADD VALUE', $alter->getUp());
        $this->assertStringContainsString('DROP TYPE', $alter->getDown());
    }

    public function testDownIsAdditiveWhenTheTargetIsTheOneAhead(): void
    {
        // The source is behind here, so restoring the target appends.
        $alter = $this->sqlFor(['new'], ['new', 'paid']);

        $this->assertStringContainsString('DROP TYPE', $alter->getUp());
        $this->assertStringContainsString("ADD VALUE IF NOT EXISTS 'paid'", $alter->getDown());
    }

    // ── Parsing ──────────────────────────────────────────────────────────────

    public function testLabelsAreReadOutOfADefinition(): void
    {
        $this->assertSame(
            ['new', 'paid'],
            AlterEnumSQL::labelsOf($this->enum('t', ['new', 'paid']))
        );
    }

    public function testADoubledQuoteIsOneEscapedQuote(): void
    {
        $this->assertSame(["it's"], AlterEnumSQL::labelsOf("CREATE TYPE \"t\" AS ENUM ('it''s')"));
    }

    public function testACommaInsideALabelIsNotASeparator(): void
    {
        $this->assertSame(['a,b'], AlterEnumSQL::labelsOf("CREATE TYPE \"t\" AS ENUM ('a,b')"));
    }

    public function testAnUnparseableDefinitionFallsBackRatherThanGuessing(): void
    {
        $this->assertNull(AlterEnumSQL::labelsOf('CREATE DOMAIN "d" AS integer'));

        // And the generator takes the replacement path for it.
        $sql = (new AlterEnumSQL(new AlterEnum('d', 'CREATE DOMAIN "d" AS integer', 'CREATE DOMAIN "d" AS bigint')))->getUp();
        $this->assertStringContainsString('DROP TYPE', $sql);
    }

    // ── The positioning helper ───────────────────────────────────────────────

    public function testAdditionsReturnsNullWhenALabelWasRemoved(): void
    {
        $this->assertNull(AlterEnumSQL::additions(['a', 'b'], ['a']));
    }

    public function testAdditionsReturnsEmptyWhenNothingChanged(): void
    {
        $this->assertSame([], AlterEnumSQL::additions(['a', 'b'], ['a', 'b']));
    }

    public function testAdditionsAnchorsEachNewLabelToOneThatExists(): void
    {
        $this->assertSame(
            [['b', 'AFTER', 'a'], ['d', 'AFTER', 'c']],
            AlterEnumSQL::additions(['a', 'c'], ['a', 'b', 'c', 'd'])
        );
    }
}
