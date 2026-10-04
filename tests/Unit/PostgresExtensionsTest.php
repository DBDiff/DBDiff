<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\Diff\CreateEnum;
use DBDiff\Diff\CreateExtension;
use DBDiff\Diff\CreateSchema;
use DBDiff\Diff\DropEnum;
use DBDiff\Diff\DropExtension;
use DBDiff\Diff\DropSchema;
use DBDiff\Linter\DestructiveLinter;
use DBDiff\SQLGen\DiffSorter;
use DBDiff\SQLGen\DiffToSQL\CreateExtensionSQL;
use DBDiff\SQLGen\DiffToSQL\DropExtensionSQL;
use DBDiff\SQLGen\Dialect\PostgresDialect;

/** Extensions in the compared schema: what is made, dropped and in which order. */
class PostgresExtensionsTest extends TestCase
{
    private const DEF = 'CREATE EXTENSION IF NOT EXISTS "btree_gist" WITH SCHEMA "public"';

    public function testAnExtensionIsInstalledAndRemoved(): void
    {
        $create = new CreateExtensionSQL(new CreateExtension('btree_gist', self::DEF), new PostgresDialect());
        $this->assertSame(self::DEF . ';', $create->getUp());
        $this->assertSame('DROP EXTENSION IF EXISTS "btree_gist";', $create->getDown());

        $drop = new DropExtensionSQL(new DropExtension('btree_gist', self::DEF), new PostgresDialect());
        $this->assertSame('DROP EXTENSION IF EXISTS "btree_gist";', $drop->getUp());
        $this->assertSame(self::DEF . ';', $drop->getDown());
    }

    // Made after its schema and before what may use it; dropped after what
    // used it and before its schema.
    public function testExtensionsSortBetweenSchemasAndTypes(): void
    {
        $sorter = new DiffSorter();
        $schema = new CreateSchema('app');
        $ext    = new CreateExtension('btree_gist', self::DEF);
        $enum   = new CreateEnum('e', "CREATE TYPE e AS ENUM ('a')");
        $this->assertSame([$schema, $ext, $enum], $sorter->sort([$enum, $ext, $schema], 'up'));
        $this->assertSame([$enum, $ext, $schema], $sorter->sort([$schema, $enum, $ext], 'down'));

        $dropSchema = new DropSchema('old');
        $dropExt    = new DropExtension('btree_gist', self::DEF);
        $dropEnum   = new DropEnum('e', "CREATE TYPE e AS ENUM ('a')");
        $this->assertSame([$dropEnum, $dropExt, $dropSchema], $sorter->sort([$dropSchema, $dropExt, $dropEnum], 'up'));
        $this->assertSame([$dropSchema, $dropExt, $dropEnum], $sorter->sort([$dropEnum, $dropSchema, $dropExt], 'down'));
    }

    public function testDroppingAnExtensionIsReported(): void
    {
        $violations = (new DestructiveLinter())->lint(['schema' => [new DropExtension('btree_gist', self::DEF)]])->getViolations();
        $this->assertSame(['drop-extension'], array_map(fn($v) => $v->type, $violations));
        $this->assertSame('extension `btree_gist`', $violations[0]->object);
    }
}
