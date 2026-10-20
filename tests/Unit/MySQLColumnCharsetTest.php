<?php

use DBDiff\DB\Support\MySQLColumns;
use DBDiff\DB\DBManager;
use DBDiff\DB\Schema\TableSchema;
use DBDiff\Diff\AlterTableChangeColumn;
use DBDiff\Params\ParamsFactory;
use PHPUnit\Framework\TestCase;

/**
 * MySQL prints a column's character set beside its collation or not
 * depending on how the column came to have it: `COLLATE utf8mb4_unicode_ci`
 * on one that took its table's, `CHARACTER SET utf8mb4 COLLATE
 * utf8mb4_unicode_ci` once it was altered to it. The two were read as
 * different, so the CHANGE that set the collation was offered again after
 * it ran — between two servers, whose tables the pre-scan does not skip.
 */
class MySQLColumnCharsetTest extends TestCase
{
    protected function setUp(): void
    {
        ParamsFactory::set((object) []);
    }

    protected function tearDown(): void
    {
        ParamsFactory::reset();
    }

    private static function schema(array $columns): array
    {
        return ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci', 'columns' => $columns, 'keys' => [], 'constraints' => []];
    }

    private function diff(array $source, array $target): array
    {
        $manager = $this->createMock(DBManager::class);
        $manager->method('getDriver')->willReturn('mysql');
        return (new TableSchema($manager))->getDiff('t', self::schema($source), self::schema($target));
    }

    public function testACollationWithOrWithoutItsCharacterSetIsTheSame(): void
    {
        $this->assertSame([], $this->diff(
            ['name' => '`name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL'],
            ['name' => '`name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'],
        ));
    }

    public function testAChangeIsStillWrittenAsTheSourcePrintsIt(): void
    {
        $diffs = $this->diff(
            ['name' => '`name` varchar(255) CHARACTER SET latin1 COLLATE latin1_spanish_ci NOT NULL'],
            ['name' => '`name` varchar(255) COLLATE latin1_swedish_ci NOT NULL'],
        );
        $this->assertCount(1, $diffs);
        $this->assertInstanceOf(AlterTableChangeColumn::class, $diffs[0]);
        $this->assertSame('`name` varchar(255) CHARACTER SET latin1 COLLATE latin1_spanish_ci NOT NULL', $diffs[0]->diff->getNewValue());
    }

    public function testOnlyACharacterSetItsCollationNamesIsLeftOut(): void
    {
        $this->assertSame('`a` text COLLATE utf8mb4_bin', MySQLColumns::comparable('`a` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'));
        $this->assertSame('`a` text CHARACTER SET latin1 COLLATE utf8mb4_bin', MySQLColumns::comparable('`a` text CHARACTER SET latin1 COLLATE utf8mb4_bin'));
        $this->assertSame('`a` text CHARACTER SET latin1', MySQLColumns::comparable('`a` text CHARACTER SET latin1'));
    }
}
