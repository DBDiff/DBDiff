<?php

use DBDiff\Migration\Format\FlywayFormat;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FlywayFormat.
 *
 * FlywayFormat produces an array of file => content entries:
 *   - Always a V{version}__{desc}.sql for the UP migration
 *   - Optionally a U{version}__{desc}.sql for the DOWN (undo) migration
 */
class FlywayFormatTest extends TestCase
{
    private FlywayFormat $fmt;

    protected function setUp(): void
    {
        $this->fmt = new FlywayFormat();
    }

    public function testRenderReturnsArray(): void
    {
        $result = $this->fmt->render('CREATE TABLE t (id INT);', '', 'create_t', '20260101120000');
        $this->assertIsArray($result);
    }

    public function testUpFilenameStartsWithV(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'create t', '20260101120000');
        $firstKey = array_key_first($result);
        $this->assertStringStartsWith('V', $firstKey);
    }

    public function testUpFilenameUsesVersion(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'desc', '20260303120000');
        $this->assertArrayHasKey('V20260303120000__desc.sql', $result);
    }

    public function testDescriptionIsSlugifiedInFilename(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'create users table', '20260101120000');
        $this->assertArrayHasKey('V20260101120000__create_users_table.sql', $result);
    }

    public function testNoUndoFileWhenDownIsEmpty(): void
    {
        $result = $this->fmt->render('CREATE TABLE t (id INT);', '', 'create_t', '20260101120000');
        $this->assertCount(1, $result);
        $firstKey = array_key_first($result);
        $this->assertStringStartsWith('V', $firstKey);
    }

    public function testUndoFileCreatedWhenDownProvided(): void
    {
        $result = $this->fmt->render(
            'CREATE TABLE t (id INT);',
            'DROP TABLE t;',
            'create_t',
            '20260101120000'
        );
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('V20260101120000__create_t.sql', $result);
        $this->assertArrayHasKey('U20260101120000__create_t.sql', $result);
    }

    public function testUndoFileContainsDownSql(): void
    {
        $result = $this->fmt->render(
            'CREATE TABLE t (id INT);',
            'DROP TABLE t;',
            'create_t',
            '20260101120000'
        );
        $this->assertStringContainsString('DROP TABLE t', $result['U20260101120000__create_t.sql']);
    }

    public function testUpFileContainsUpSql(): void
    {
        $result = $this->fmt->render('CREATE TABLE t (id INT);', '', 'create_t', '20260101120000');
        $content = reset($result);
        $this->assertStringContainsString('CREATE TABLE t', $content);
    }

    public function testSpecialCharsSlugified(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'add column: user_id!', '20260101120000');
        $firstKey = array_key_first($result);
        // No colons or exclamation marks in filename
        $this->assertStringNotContainsString(':', $firstKey);
        $this->assertStringNotContainsString('!', $firstKey);
    }

    public function testGetExtension(): void
    {
        $this->assertSame('sql', $this->fmt->getExtension());
    }

    public function testGetLabel(): void
    {
        $this->assertNotEmpty($this->fmt->getLabel());
    }
}
