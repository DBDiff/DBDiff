<?php

/**
 * Whether two databases hold the same schema, as pg-conformance's state
 * query sees it.
 *
 * A round trip checked only by DBDiff diffing again is blind to whatever
 * DBDiff itself does not read: a comment the migration dropped reads as
 * converged when neither diff looks at comments. The state query is the
 * corpus's own account of what is there, independent of the tool under test.
 *
 * For a PostgresRoundTripTestCase: uses its connect().
 */
trait CorpusState
{
    /** The corpus checkout or package the cases came from. */
    protected static function conformanceDir(): string
    {
        return getenv('PG_CONFORMANCE_DIR') ?: dirname(__DIR__, 2) . '/node_modules/@akalforge/pg-conformance';
    }

    protected function assertSameState(string $expected, string $actual, string $message): void
    {
        $schemas = array_values(array_unique(array_merge($this->userSchemas($expected), $this->userSchemas($actual))));
        sort($schemas);
        $differences = self::differences($this->state($expected, $schemas), $this->state($actual, $schemas));
        $this->assertSame([], $differences, $message);
    }

    /**
     * The state of every schema the database has, for assertRoundTrip()'s
     * `$state`: equal for two databases holding the same schemas.
     *
     * @return array<string, mixed>
     */
    protected function schemaState(string $db): array
    {
        $schemas = $this->userSchemas($db);
        sort($schemas);
        return $this->state($db, $schemas);
    }

    /** @return array<string, mixed> */
    private function state(string $db, array $schemas): array
    {
        $list = implode(', ', array_map(fn(string $s) => "'" . str_replace("'", "''", $s) . "'", $schemas));
        $sql  = str_replace('__SCHEMAS__', $list, file_get_contents(self::conformanceDir() . '/state.sql'));
        $json  = $this->connect($db)->query($sql)->fetchColumn();
        $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        // A column's position is its attnum, and PostgreSQL cannot move a
        // column: one dropped and added again — a generated column changing
        // its expression, a type swapped through a new column — ends up last
        // whichever tool migrates it. SupaForge's diffState leaves it out too.
        foreach ($state['tables'] ?? [] as $t => $table) {
            foreach ($table['columns'] ?? [] as $c => $column) {
                unset($state['tables'][$t]['columns'][$c]['position']);
            }
        }
        return $state;
    }

    /** @return list<string> */
    private function userSchemas(string $db): array
    {
        return $this->connect($db)->query(
            "SELECT n.nspname FROM pg_namespace n
              WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
                AND n.nspname NOT LIKE 'pg\\_toast%' AND n.nspname NOT LIKE 'pg\\_temp\\_%'
                AND NOT EXISTS (SELECT 1 FROM pg_depend d
                                 WHERE d.classid = 'pg_namespace'::regclass AND d.objid = n.oid AND d.deptype = 'e')"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Where two state documents differ, as `path: expected → actual` lines.
     * An entry of a list is named by its `name` where it has one.
     *
     * @return list<string>
     */
    private static function differences(mixed $expected, mixed $actual, string $path = ''): array
    {
        if (!is_array($expected) || !is_array($actual)) {
            return $expected === $actual ? [] : ["$path: " . json_encode($expected) . ' → ' . json_encode($actual)];
        }
        $out = [];
        $named = fn(array $list) => array_is_list($list) ? self::byName($list) : $list;
        $a = $named($expected);
        $b = $named($actual);
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            $at = $path === '' ? (string) $key : "$path.$key";
            if (!array_key_exists($key, $b)) {
                $out[] = "$at: missing";
            } elseif (!array_key_exists($key, $a)) {
                $out[] = "$at: unexpected " . json_encode($b[$key]);
            } else {
                array_push($out, ...self::differences($a[$key], $b[$key], $at));
            }
        }
        return $out;
    }

    /** A list keyed by each entry's name, or by position where entries have none. */
    private static function byName(array $list): array
    {
        $keyed = [];
        foreach ($list as $i => $entry) {
            $name = is_array($entry) && isset($entry['name']) && is_string($entry['name'])
                ? (isset($entry['schema']) && is_string($entry['schema']) ? "{$entry['schema']}.{$entry['name']}" : $entry['name'])
                : (string) $i;
            $keyed[isset($keyed[$name]) ? "$name#$i" : $name] = $entry;
        }
        return $keyed;
    }
}
