<?php namespace DBDiff\DB\Schema;

/**
 * Tables in the order they can be created: each after the tables it
 * references (Kahn's algorithm). Peers are sorted alphabetically for
 * deterministic output, and tables in a cycle are appended the same way.
 *
 * Names are compared as given, so a caller ordering tables from several
 * schemas passes them qualified.
 */
final class TableOrder
{
    /**
     * @param list<string>                $tables
     * @param array<string, list<string>> $fkMap  table => tables it references
     * @return list<string>
     */
    public static function sort(array $tables, array $fkMap): array
    {
        [$deps, $children] = self::adjacency($tables, $fkMap);

        $inDegree = array_map('count', $deps);

        $queue = [];
        foreach ($inDegree as $t => $degree) {
            if ($degree === 0) {
                $queue[] = (string) $t;
            }
        }
        sort($queue);

        $sorted = [];
        while (!empty($queue)) {
            $current  = array_shift($queue);
            $sorted[] = $current;
            foreach ($children[$current] as $child) {
                $inDegree[$child]--;
                if ($inDegree[$child] === 0) {
                    $queue[] = (string) $child;
                    sort($queue);
                }
            }
        }

        // Append any remaining tables (cycles) alphabetically
        $remaining = array_diff($tables, $sorted);
        sort($remaining);
        return array_merge($sorted, $remaining);
    }

    private static function adjacency(array $tables, array $fkMap): array
    {
        $tableSet = array_flip($tables);
        $deps     = array_fill_keys($tables, []);
        $children = array_fill_keys($tables, []);
        foreach ($tables as $table) {
            foreach ($fkMap[$table] ?? [] as $parent) {
                if (isset($tableSet[$parent]) && $parent !== $table) {
                    $deps[$table][]      = $parent;
                    $children[$parent][] = $table;
                }
            }
        }
        return [$deps, $children];
    }
}
