<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Connection;
use DBDiff\DB\Support\SchemaScope;
use DBDiff\DB\Support\SchemaSelection;
use DBDiff\DB\Schema\TableOrder;
use DBDiff\Diff\AddTable;
use DBDiff\Diff\AlterTableAddColumn;
use DBDiff\Diff\AlterTableDropColumn;
use DBDiff\Diff\CreateEnum;
use DBDiff\Diff\CreateRoutine;
use DBDiff\Diff\CreateSchema;
use DBDiff\Diff\DropSchema;
use DBDiff\Diff\DropTable;
use DBDiff\Linter\DestructiveLinter;
use DBDiff\SQLGen\DiffSorter;
use DBDiff\SQLGen\MigrationGenerator;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\DiffToSQL\RoutineDrop;
use DBDiff\SQLGen\DiffToSQL\CreateSchemaSQL;
use DBDiff\SQLGen\DiffToSQL\DropSchemaSQL;

/**
 * Naming objects outside `public` (`--schemas`): what DBDiff writes itself.
 * What PostgreSQL renders is qualified by the server and is round-tripped in
 * MultiSchemaRoundTripPostgresTest.
 */
class MultiSchemaSQLTest extends TestCase
{
    private static function connectionIn(string $schema): Connection
    {
        $connection = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $config = new \ReflectionProperty(Connection::class, 'config');
        $config->setValue($connection, ['schema' => $schema]);
        return $connection;
    }

    public function testPublicIsNamedAsItAlwaysHasBeen(): void
    {
        $pg = new PostgresDialect();
        $this->assertSame('"t"', $pg->qualify('t'));
        $this->assertSame('"t"', $pg->inSchema('public')->qualify('t'));
        $this->assertSame('"t"', $pg->inSchema(null)->qualify('t'));
    }

    public function testAnotherSchemaIsNamedInFull(): void
    {
        $pg = (new PostgresDialect())->inSchema('App "Data"');
        $this->assertSame('"App ""Data"""."Order Items"', $pg->qualify('Order Items'));
        // Columns belong to their table and are never qualified.
        $this->assertSame('"Qty"', $pg->quote('Qty'));
    }

    public function testInSchemaLeavesTheDialectItCameFromAlone(): void
    {
        $pg = new PostgresDialect();
        $pg->inSchema('app');
        $this->assertSame('"t"', $pg->qualify('t'));
    }

    public function testTableStatementsNameTheSchema(): void
    {
        $pg = (new PostgresDialect())->inSchema('app');
        $this->assertSame('ALTER TABLE "app"."t" DROP COLUMN "c" CASCADE;', $pg->dropColumn('t', 'c'));
        $this->assertSame('DROP INDEX "app"."i";', $pg->dropIndex('t', 'i'));
        $this->assertSame('DROP TRIGGER IF EXISTS "tg" ON "app"."t";', $pg->dropTrigger('tg', 't'));
    }

    public function testASerialColumnsSequenceIsCreatedAsPostgreSQLNamesIt(): void
    {
        $pg = (new PostgresDialect())->inSchema('app');
        $sql = $pg->addColumn('t', "\"id\" integer DEFAULT nextval('app.\"T_id_seq\"'::regclass)");
        $this->assertStringStartsWith("CREATE SEQUENCE IF NOT EXISTS app.\"T_id_seq\";\n", $sql);
        $this->assertStringContainsString('ALTER TABLE "app"."t" ADD COLUMN', $sql);
    }

    public function testMySQLIsUnchanged(): void
    {
        $this->assertSame('`t`', (new MySQLDialect())->inSchema(null)->qualify('t'));
    }

    public function testARoutineIsDroppedByTheNamePostgreSQLPrints(): void
    {
        $def = 'CREATE OR REPLACE FUNCTION app.f(integer) RETURNS int AS $$ SELECT 1 $$';
        $pg = new PostgresDialect();
        $this->assertSame('DROP FUNCTION IF EXISTS app.f(integer);', RoutineDrop::build($def, 'app.f(integer)', $pg));
        $this->assertSame('DROP FUNCTION IF EXISTS "Fn"(integer);', RoutineDrop::build($def, '"Fn"(integer)', $pg));
        $this->assertSame('DROP FUNCTION IF EXISTS "f"(integer);', RoutineDrop::build($def, 'f(integer)', $pg));
    }

    public function testASchemaIsCreatedAndDroppedEmpty(): void
    {
        $pg = new PostgresDialect();
        $create = new CreateSchemaSQL(new CreateSchema('App Data'), $pg);
        $drop   = new DropSchemaSQL(new DropSchema('old'), $pg);
        $this->assertSame('CREATE SCHEMA IF NOT EXISTS "App Data";', $create->getUp());
        $this->assertSame('DROP SCHEMA IF EXISTS "App Data";', $create->getDown());
        $this->assertSame('DROP SCHEMA IF EXISTS "old";', $drop->getUp());
        $this->assertSame('CREATE SCHEMA IF NOT EXISTS "old";', $drop->getDown());
    }

    public function testSchemasAreMadeFirstAndDroppedLast(): void
    {
        $create = new CreateSchema('app');
        $drop   = new DropSchema('old');
        $enum   = new CreateEnum('e', "CREATE TYPE e AS ENUM ('a')");
        $sorter = new DiffSorter();
        $this->assertSame([$create, $enum, $drop], $sorter->sort([$drop, $enum, $create], 'up'));
        $this->assertSame([$drop, $enum, $create], $sorter->sort([$create, $enum, $drop], 'down'));
    }

    public function testChangesToTablesOfOneNameInTwoSchemasStayTogether(): void
    {
        $at = function (string $schema, string $column) {
            $diff = new AlterTableAddColumn('t', $column, null);
            $diff->schema = $schema;
            return $diff;
        };
        $sorted = (new DiffSorter())->sort([$at('b', 'x'), $at('a', 'y'), $at('b', 'w'), $at('a', 'z')], 'up');
        $this->assertSame(['a.y', 'a.z', 'b.w', 'b.x'], array_map(fn($d) => "{$d->schema}.{$d->column}", $sorted));
    }

    public function testUnitMarkersNameTheSchema(): void
    {
        $column = new AlterTableAddColumn('t', 'c', null);
        $column->schema = 'app';
        $routine = new CreateRoutine('app.f(integer)', 'CREATE FUNCTION app.f(integer) RETURNS int AS $$ SELECT 1 $$');
        $routine->schema = 'app';
        $schema = new CreateSchema('app');
        $schema->schema = 'app';

        $sql = MigrationGenerator::generate([$schema, $routine], 'getUp', true);
        $this->assertStringContainsString('-- dbdiff:unit CreateSchema app' . "\n", $sql);
        $this->assertStringContainsString('-- dbdiff:unit CreateRoutine app.f(integer)' . "\n", $sql);

        $method = new \ReflectionMethod(MigrationGenerator::class, 'unitObject');
        $this->assertSame('app.t.c', $method->invoke(null, $column));
        $column->schema = 'public';
        $this->assertSame('t.c', $method->invoke(null, $column));
    }

    public function testTheLinterNamesTablesOutsidePublicInFull(): void
    {
        $drop = new DropTable('t', null);
        $drop->schema = 'app';
        $result = (new DestructiveLinter())->lint(['schema' => [$drop, new DropSchema('old')]]);
        $subjects = array_map(fn($v) => $v->object, $result->getViolations());
        $this->assertContains('table `app.t`', $subjects);
        $this->assertContains('schema `old`', $subjects);
    }

    // Two tables called t in different schemas are not one rename candidate.
    public function testARenameIsLookedForWithinOneSchema(): void
    {
        $drop = new AlterTableDropColumn('t', 'old', (object) ['type' => 'int']);
        $drop->schema = 'a';
        $add = new AlterTableAddColumn('t', 'new', (object) ['type' => 'int']);
        $add->schema = 'b';
        $result = (new DestructiveLinter())->lint(['schema' => [$drop, $add]]);
        $this->assertSame(['drop-column'], array_map(fn($v) => $v->type, $result->getViolations()));
    }

    public function testSchemaScopeNamesAndFindsTheSchema(): void
    {
        $this->assertSame('"t"', SchemaScope::name(self::connectionIn('public'), 't'));
        $this->assertSame('"My ""App"""."t"', SchemaScope::name(self::connectionIn('My "App"'), 't'));
        $this->assertSame("(SELECT oid FROM pg_namespace WHERE nspname = 'it''s')", SchemaScope::oid(self::connectionIn("it's")));
    }

    public function testTablesAreOrderedAfterWhatTheyReferenceAcrossSchemas(): void
    {
        $order = TableOrder::sort(['billing.invoices', 'public.customers'], ['billing.invoices' => ['public.customers']]);
        $this->assertSame(['public.customers', 'billing.invoices'], $order);
    }

    public function testTheDefaultSelectionIsPublicAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('select');
        $params = (object) ['schemas' => null, 'schemasToIgnore' => null];
        $this->assertSame(['public'], SchemaSelection::resolve($params, $connection, $connection));
        $this->assertTrue(SchemaSelection::isDefault(['public']));
        $this->assertFalse(SchemaSelection::isDefault(['public', 'app']));
    }

    public function testSchemasAreChosenByGlobAndPublicComesFirst(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('select')->willReturn([['name' => 'app'], ['name' => 'auth'], ['name' => 'public'], ['name' => 'api']]);

        $all = (object) ['schemas' => ['*'], 'schemasToIgnore' => null];
        $this->assertSame(['public', 'api', 'app', 'auth'], SchemaSelection::resolve($all, $connection, $connection));

        $ignoring = (object) ['schemas' => null, 'schemasToIgnore' => 'auth, a?i'];
        $this->assertSame(['public', 'app'], SchemaSelection::resolve($ignoring, $connection, $connection));

        // Named outright, a schema neither side has yet is still compared.
        $named = (object) ['schemas' => ['app', 'reports'], 'schemasToIgnore' => null];
        $this->assertSame(['app', 'reports'], SchemaSelection::resolve($named, $connection, $connection));
    }
}
