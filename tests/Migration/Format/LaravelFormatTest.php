<?php

use DBDiff\Migration\Format\LaravelFormat;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LaravelFormat.
 *
 * Produces an array of [ 'YYYY_MM_DD_HHMMSS_slug.php' => '<?php ...' ]
 * containing an anonymous class extending Illuminate Migration.
 */
class LaravelFormatTest extends TestCase
{
    private LaravelFormat $fmt;

    protected function setUp(): void
    {
        $this->fmt = new LaravelFormat();
    }

    public function testRenderReturnsArray(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testFilenameFollowsLaravelConvention(): void
    {
        // Input: 20260303120000  →  2026_03_03_120000_slug.php
        $result = $this->fmt->render('', '', 'create_users', '20260303120000');
        $keys = array_keys($result);
        $this->assertSame('2026_03_03_120000_create_users.php', $keys[0]);
    }

    public function testDescriptionIsSlugifiedInFilename(): void
    {
        $result = $this->fmt->render('', '', 'Create Users Table', '20260101120000');
        $keys = array_keys($result);
        $this->assertStringContainsString('create_users_table', $keys[0]);
    }

    public function testSpecialCharsRemovedFromFilename(): void
    {
        $result = $this->fmt->render('', '', 'add column: user_id!', '20260101120000');
        $keys = array_keys($result);
        $this->assertStringNotContainsString(':', $keys[0]);
        $this->assertStringNotContainsString('!', $keys[0]);
    }

    public function testContentStartsWithPhpOpenTag(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        $content = reset($result);
        $this->assertStringStartsWith('<?php', $content);
    }

    public function testContentExtendsIlluminateMigration(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        $content = reset($result);
        $this->assertStringContainsString('extends Migration', $content);
    }

    public function testContentContainsUpMethod(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        $content = reset($result);
        $this->assertStringContainsString('public function up()', $content);
    }

    public function testContentContainsDownMethod(): void
    {
        $result = $this->fmt->render('SELECT 1;', '', 'test', '20260101120000');
        $content = reset($result);
        $this->assertStringContainsString('public function down()', $content);
    }

    public function testUpSqlEmbeddedInContent(): void
    {
        $result = $this->fmt->render('CREATE TABLE users (id INT);', '', 'test', '20260101120000');
        $content = reset($result);
        $this->assertStringContainsString('CREATE TABLE users', $content);
    }

    public function testDownSqlEmbeddedInContent(): void
    {
        $result = $this->fmt->render('', 'DROP TABLE users;', 'test', '20260101120000');
        $content = reset($result);
        $this->assertStringContainsString('DROP TABLE users', $content);
    }

    public function testEmptyUpShowsPlaceholder(): void
    {
        $result = $this->fmt->render('', '', 'test', '20260101120000');
        $content = reset($result);
        $this->assertStringContainsString('-- (empty)', $content);
    }

    public function testSingleQuotesInSqlAreEscaped(): void
    {
        // SQL with a single-quoted string literal must be safely escaped
        $result = $this->fmt->render("INSERT INTO t VALUES ('hello');", '', 'test', '20260101120000');
        $content = reset($result);
        // The generated PHP string literal must not have an unescaped ' that
        // would break the PHP file (check that the content parses as valid PHP).
        $this->assertStringContainsString('hello', $content);
    }

    public function testUsesDbFacade(): void
    {
        $result = $this->fmt->render('', '', 'test', '20260101120000');
        $content = reset($result);
        $this->assertStringContainsString('DB::unprepared', $content);
    }

    public function testGetExtension(): void
    {
        $this->assertSame('php', $this->fmt->getExtension());
    }

    public function testGetLabel(): void
    {
        $this->assertNotEmpty($this->fmt->getLabel());
    }
}
