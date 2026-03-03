<?php

use DBDiff\Migration\Format\LiquibaseYamlFormat;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LiquibaseYamlFormat.
 *
 * Produces a single YAML string (via symfony/yaml) with a Liquibase changeLog
 * structure.
 */
class LiquibaseYamlFormatTest extends TestCase
{
    private LiquibaseYamlFormat $fmt;

    protected function setUp(): void
    {
        $this->fmt = new LiquibaseYamlFormat();
    }

    public function testRenderReturnsString(): void
    {
        $result = $this->fmt->render('SELECT 1;', '');
        $this->assertIsString($result);
    }

    public function testOutputContainsDatabaseChangeLog(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        $this->assertStringContainsString('databaseChangeLog', $result);
    }

    public function testOutputContainsChangeSet(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        $this->assertStringContainsString('changeSet', $result);
    }

    public function testVersionAppearsAsId(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260303120000');
        $this->assertStringContainsString('20260303120000', $result);
    }

    public function testDescriptionAppearsAsComment(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'add_users_table', '20260303120000');
        $this->assertStringContainsString('add_users_table', $result);
    }

    public function testUpSqlInOutput(): void
    {
        $result = $this->fmt->render('CREATE TABLE t (id INT);', '', 'test', '20260101120000');
        $this->assertStringContainsString('CREATE TABLE t', $result);
    }

    public function testDownSqlInRollback(): void
    {
        $result = $this->fmt->render(
            'CREATE TABLE t (id INT);',
            'DROP TABLE t;',
            'test',
            '20260101120000'
        );
        $this->assertStringContainsString('DROP TABLE t', $result);
        $this->assertStringContainsString('rollback', $result);
    }

    public function testEmptyDownUsesPlaceholder(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        $this->assertStringContainsString('-- (empty)', $result);
    }

    public function testHeaderCommentPresent(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        // The format prepends a header comment block
        $this->assertStringStartsWith('#', $result);
    }

    public function testGetExtension(): void
    {
        $this->assertSame('yaml', $this->fmt->getExtension());
    }

    public function testGetLabel(): void
    {
        $this->assertNotEmpty($this->fmt->getLabel());
    }
}
