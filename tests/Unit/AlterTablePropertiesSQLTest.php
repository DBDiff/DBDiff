<?php

namespace Tests\Unit;

use DBDiff\Diff\AlterTableOptions;
use DBDiff\Diff\AlterTablePersistence;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\DiffToSQL\AlterTableOptionsSQL;
use DBDiff\SQLGen\DiffToSQL\AlterTablePersistenceSQL;
use PHPUnit\Framework\TestCase;

/**
 * Durability and storage parameters on an existing table (issue #229).
 *
 * Both were read for rendering a *new* table and never compared for one that
 * exists on both sides, so switching a table between LOGGED and UNLOGGED, or
 * changing its fillfactor, was reported as "Databases are identical". UNLOGGED
 * is not decoration: an unlogged table is not crash-safe and is emptied on
 * recovery.
 */
class AlterTablePropertiesSQLTest extends TestCase
{
    private function persistence(bool $source, bool $target): AlterTablePersistenceSQL
    {
        return new AlterTablePersistenceSQL(
            new AlterTablePersistence('t_unlogged', $source, $target),
            new PostgresDialect()
        );
    }

    private function options(?string $source, ?string $target): AlterTableOptionsSQL
    {
        return new AlterTableOptionsSQL(
            new AlterTableOptions('t_fill', $source, $target),
            new PostgresDialect()
        );
    }

    // ── LOGGED / UNLOGGED ────────────────────────────────────────────────────

    public function testAnUnloggedTargetIsSetLogged(): void
    {
        // Source logged, target unlogged: the UP makes the target durable.
        $this->assertSame(
            'ALTER TABLE "t_unlogged" SET LOGGED;',
            $this->persistence(false, true)->getUp()
        );
    }

    public function testTheReverseIsSetUnlogged(): void
    {
        $this->assertSame(
            'ALTER TABLE "t_unlogged" SET UNLOGGED;',
            $this->persistence(true, false)->getUp()
        );
    }

    public function testTheDownRestoresTheTargetsOwnPersistence(): void
    {
        $this->assertSame(
            'ALTER TABLE "t_unlogged" SET UNLOGGED;',
            $this->persistence(false, true)->getDown()
        );
    }

    // ── Storage parameters ───────────────────────────────────────────────────

    public function testAnOptionOnlyTheTargetHasIsReset(): void
    {
        // `RESET` returns it to the default; there is no way to replace the
        // whole list in one statement.
        $this->assertSame(
            'ALTER TABLE "t_fill" RESET (fillfactor);',
            $this->options(null, 'fillfactor = 70')->getUp()
        );
    }

    public function testAnOptionOnlyTheSourceHasIsSet(): void
    {
        $this->assertSame(
            'ALTER TABLE "t_fill" SET (fillfactor = 70);',
            $this->options('fillfactor = 70', null)->getUp()
        );
    }

    public function testAChangedValueIsSetToTheSources(): void
    {
        $this->assertSame(
            'ALTER TABLE "t_fill" SET (fillfactor = 90);',
            $this->options('fillfactor = 90', 'fillfactor = 70')->getUp()
        );
    }

    public function testSettingAndResettingCanBothHappen(): void
    {
        $sql = $this->options('fillfactor = 90', 'autovacuum_enabled = false')->getUp();

        $this->assertStringContainsString('SET (fillfactor = 90);', $sql);
        $this->assertStringContainsString('RESET (autovacuum_enabled);', $sql);
    }

    public function testSeveralOptionsAreSetTogether(): void
    {
        $sql = $this->options('fillfactor = 90, autovacuum_enabled = false', null)->getUp();

        $this->assertStringContainsString('fillfactor = 90', $sql);
        $this->assertStringContainsString('autovacuum_enabled = false', $sql);
        $this->assertSame(1, substr_count($sql, 'SET ('));
    }

    public function testAnOptionPresentAndEqualOnBothSidesIsLeftAlone(): void
    {
        $sql = $this->options('fillfactor = 70, autovacuum_enabled = false', 'fillfactor = 70')->getUp();

        $this->assertStringContainsString('autovacuum_enabled = false', $sql);
        $this->assertStringNotContainsString('fillfactor', $sql);
    }

    public function testTheDownDirectionIsTheMirrorImage(): void
    {
        $alter = $this->options(null, 'fillfactor = 70');

        $this->assertSame('ALTER TABLE "t_fill" RESET (fillfactor);', $alter->getUp());
        $this->assertSame('ALTER TABLE "t_fill" SET (fillfactor = 70);', $alter->getDown());
    }

    // ── Parsing ──────────────────────────────────────────────────────────────

    public function testOptionsAreParsedIntoPairs(): void
    {
        $this->assertSame(
            ['fillfactor' => '70', 'autovacuum_enabled' => 'false'],
            AlterTableOptionsSQL::parse('fillfactor = 70, autovacuum_enabled = false')
        );
    }

    public function testNoOptionsParsesToNothing(): void
    {
        $this->assertSame([], AlterTableOptionsSQL::parse(null));
        $this->assertSame([], AlterTableOptionsSQL::parse(''));
        $this->assertSame([], AlterTableOptionsSQL::parse('   '));
    }

    public function testAValueContainingAnEqualsSignSurvives(): void
    {
        $this->assertSame(
            ['check_option' => 'a=b'],
            AlterTableOptionsSQL::parse('check_option = a=b')
        );
    }
}
