<?php namespace DBDiff\DB\Schema;

use DBDiff\DB\DBManager;
use DBDiff\DB\Support\SchemaSelection;
use DBDiff\Diff\AddTable;
use DBDiff\Diff\CreateSchema;
use DBDiff\Diff\DropSchema;
use DBDiff\Diff\DropTable;
use Illuminate\Database\Connection;

/**
 * A PostgreSQL diff over several schemas.
 *
 * Each schema is compared on its own, exactly as `public` always has been,
 * and each change is tagged with its schema so its SQL names it in full. The
 * changes are then one list, sorted once: every schema's types before any
 * schema's tables, and so on. What crosses schemas — a table referencing a
 * table in another — is ranked over all of them here.
 */
final class MultiSchemaDiff
{
    /** Separates a schema from a name in a key; no identifier contains it. */
    private const SEP = "\x1F";

    public function __construct(private DBManager $manager) {}

    /**
     * @param list<string>                     $schemas
     * @param callable(): array{0: array, 1: array} $diffOne  the schema and data diff of the schema in use
     * @param bool $compareSchemas  false for a data diff: a schema only one side has is not created or dropped
     * @return array{schema: array, data: array}
     */
    public function getDiff(array $schemas, callable $diffOne, bool $compareSchemas = true): array
    {
        $inSource = SchemaSelection::userSchemas($this->manager->getDB('source'));
        $inTarget = SchemaSelection::userSchemas($this->manager->getDB('target'));

        $schemaDiff = [];
        $dataDiff   = [];
        foreach ($schemas as $schema) {
            $source = in_array($schema, $inSource, true);
            $target = in_array($schema, $inTarget, true);
            if (!$source && !$target) {
                continue;
            }
            $change = $compareSchemas ? self::schemaChange($schema, $source, $target) : null;
            if ($change !== null) {
                $schemaDiff[] = $change;
            }

            $this->manager->useSchema($schema);
            [$schemaPart, $dataPart] = $diffOne();
            foreach ($schemaPart as $diff) {
                $schemaDiff[] = self::tagged($diff, $schema);
            }
            foreach ($dataPart as $diff) {
                $dataDiff[] = self::tagged($diff, $schema);
            }
        }

        $this->rankTables($schemaDiff, AddTable::class, 'source');
        $this->rankTables($schemaDiff, DropTable::class, 'target');

        return ['schema' => $schemaDiff, 'data' => $dataDiff];
    }

    /** Creating or dropping a schema only one side has. */
    private static function schemaChange(string $schema, bool $inSource, bool $inTarget): ?object
    {
        if ($inSource === $inTarget) {
            return null;
        }
        return self::tagged($inSource ? new CreateSchema($schema) : new DropSchema($schema), $schema);
    }

    private static function tagged(object $diff, string $schema): object
    {
        $diff->schema = $schema;
        return $diff;
    }

    /**
     * Rank created (or dropped) tables over every schema, parents first, so
     * a table is created after one it references in another schema.
     */
    private function rankTables(array $diffs, string $class, string $side): void
    {
        $tables = array_values(array_filter($diffs, fn($d) => $d instanceof $class));
        if (count($tables) < 2) {
            return;
        }
        $key   = fn($d) => $d->schema . self::SEP . $d->table;
        $order = TableOrder::sort(array_map($key, $tables), $this->references($this->manager->getDB($side)));
        $rank  = array_flip($order);
        foreach ($tables as $diff) {
            $diff->sortOrder = $rank[$key($diff)];
        }
    }

    /**
     * Every table's foreign-key and partition parents, in every schema.
     *
     * @return array<string, list<string>>
     */
    private function references(Connection $connection): array
    {
        $rows = $connection->select(
            "SELECT cn.nspname || chr(31) || c.relname AS child, pn.nspname || chr(31) || p.relname AS parent
               FROM pg_constraint k
               JOIN pg_class c ON c.oid = k.conrelid JOIN pg_namespace cn ON cn.oid = c.relnamespace
               JOIN pg_class p ON p.oid = k.confrelid JOIN pg_namespace pn ON pn.oid = p.relnamespace
              WHERE k.contype = 'f'
             UNION
             SELECT cn.nspname || chr(31) || c.relname, pn.nspname || chr(31) || p.relname
               FROM pg_inherits i
               JOIN pg_class c ON c.oid = i.inhrelid JOIN pg_namespace cn ON cn.oid = c.relnamespace
               JOIN pg_class p ON p.oid = i.inhparent JOIN pg_namespace pn ON pn.oid = p.relnamespace"
        );
        $map = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $map[$row['child']][] = $row['parent'];
        }
        return $map;
    }
}
