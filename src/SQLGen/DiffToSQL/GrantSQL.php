<?php namespace DBDiff\SQLGen\DiffToSQL;

/**
 * GRANT statements putting back grants read with
 * PostgresAcl::grantsJson() — for a recreated view, a re-added
 * column, a swapped enum type.
 */
final class GrantSQL {

    /**
     * For `[grantee, privilege, grantable]` rows on `$target` (`ON "v"`,
     * `("col") ON "t"`, `ON TYPE "e"`): one statement per grantee, and a
     * second where some privileges carry the grant option and others do not.
     *
     * @return string[]
     */
    public static function statements(array $grants, string $target): array {
        $grouped = [];
        foreach ($grants as $g) {
            $grouped[$g['grantee']][$g['grantable'] ? 1 : 0][] = $g['privilege'];
        }
        $lines = [];
        foreach ($grouped as $grantee => $byOption) {
            foreach ([0, 1] as $withOption) {
                if (!empty($byOption[$withOption])) {
                    $lines[] = 'GRANT ' . implode(', ', $byOption[$withOption]) . " $target TO $grantee"
                        . ($withOption ? ' WITH GRANT OPTION' : '') . ';';
                }
            }
        }
        return $lines;
    }
}
