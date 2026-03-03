<?php

use DBDiff\Migration\Format\LiquibaseXmlFormat;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LiquibaseXmlFormat.
 *
 * Produces a single XML string with a Liquibase changeLog wrapper.
 */
class LiquibaseXmlFormatTest extends TestCase
{
    private LiquibaseXmlFormat $fmt;

    protected function setUp(): void
    {
        $this->fmt = new LiquibaseXmlFormat();
    }

    public function testRenderReturnsString(): void
    {
        $result = $this->fmt->render('CREATE TABLE t (id INT);', '');
        $this->assertIsString($result);
    }

    public function testOutputIsValidXml(): void
    {
        $result = $this->fmt->render('SELECT 1;', 'SELECT 0;', 'test', '20260101120000');
        $xml = @simplexml_load_string($result);
        $this->assertNotFalse($xml, "render() must produce well-formed XML.\nActual output:\n{$result}");
    }

    public function testXmlDeclarationPresent(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        $this->assertStringStartsWith('<?xml', $result);
    }

    public function testChangeSetIdIsVersion(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'my_migration', '20260303120000');
        $xml = simplexml_load_string($result);
        $this->assertNotFalse($xml);
        $changeSet = $xml->changeSet;
        $this->assertSame('20260303120000', (string) $changeSet['id']);
    }

    public function testChangeSetCommentIsDescription(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'add_users_table', '20260303120000');
        $xml = simplexml_load_string($result);
        $changeSet = $xml->changeSet;
        $this->assertSame('add_users_table', (string) $changeSet['comment']);
    }

    public function testUpSqlInOutput(): void
    {
        $result = $this->fmt->render('CREATE TABLE users (id INT);', '', 'test', '20260101120000');
        $this->assertStringContainsString('CREATE TABLE users', $result);
    }

    public function testDownSqlInRollback(): void
    {
        $result = $this->fmt->render(
            'CREATE TABLE users (id INT);',
            'DROP TABLE users;',
            'test',
            '20260101120000'
        );
        $this->assertStringContainsString('DROP TABLE users', $result);
        $this->assertStringContainsString('<rollback>', $result);
    }

    public function testEmptyDownUsesPlaceholder(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        // rollback block should still be present, with empty placeholder comment
        $this->assertStringContainsString('<rollback>', $result);
        $this->assertStringContainsString('-- (empty)', $result);
    }

    public function testGetExtension(): void
    {
        $this->assertSame('xml', $this->fmt->getExtension());
    }

    public function testGetLabel(): void
    {
        $this->assertNotEmpty($this->fmt->getLabel());
    }
}
