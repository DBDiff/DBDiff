<?php

use DBDiff\Migration\Format\NativeFormat;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NativeFormat.
 *
 * NativeFormat produces a single SQL string with clearly labelled UP and DOWN
 * sections — the default DBDiff output.
 */
class NativeFormatTest extends TestCase
{
    private NativeFormat $fmt;

    protected function setUp(): void
    {
        $this->fmt = new NativeFormat();
    }

    public function testRenderReturnsString(): void
    {
        $result = $this->fmt->render('SELECT 1;', '');
        $this->assertIsString($result);
    }

    public function testRenderContainsUpSection(): void
    {
        $result = $this->fmt->render('CREATE TABLE t (id INT);', '', 'create_t', '20260101120000');
        $this->assertStringContainsString('== UP ==', $result);
        $this->assertStringContainsString('CREATE TABLE t', $result);
    }

    public function testRenderContainsDownSection(): void
    {
        $result = $this->fmt->render(
            'CREATE TABLE t (id INT);',
            'DROP TABLE t;',
            'create_t',
            '20260101120000'
        );
        $this->assertStringContainsString('== DOWN ==', $result);
        $this->assertStringContainsString('DROP TABLE t', $result);
    }

    public function testEmptyUpShowsPlaceholder(): void
    {
        $result = $this->fmt->render('', '', 'noop', '20260101120000');
        $this->assertStringContainsString('-- (empty)', $result);
    }

    public function testEmptyDownShowsPlaceholder(): void
    {
        $result = $this->fmt->render('CREATE TABLE t (id INT);', '', 'create_t', '20260101120000');
        // UP has content, DOWN is empty — placeholder appears at least once
        $this->assertStringContainsString('-- (empty)', $result);
    }

    public function testRenderIncludesVersion(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'desc', '20260101120000');
        $this->assertStringContainsString('20260101120000', $result);
    }

    public function testRenderIncludesDescription(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'my_migration_desc', '20260101120000');
        $this->assertStringContainsString('my_migration_desc', $result);
    }

    public function testGetExtension(): void
    {
        $this->assertSame('sql', $this->fmt->getExtension());
    }

    public function testGetLabel(): void
    {
        $this->assertNotEmpty($this->fmt->getLabel());
    }

    public function testUpSqlIsTrimmmed(): void
    {
        $result = $this->fmt->render("   SELECT 1;\n\n\n", '');
        $this->assertStringContainsString('SELECT 1;', $result);
        // Should not have trailing blank lines inside the UP block
        $this->assertStringNotContainsString("SELECT 1;\n\n\n", $result);
    }
}
