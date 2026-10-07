<?php namespace DBDiff\SQLGen\DiffToSQL;

/**
 * GRANT statements putting back grants read with
 * PostgresAcl::grantsJson() — for a recreated view, a re-added
 * column, a swapped enum type.
 */
final class GrantSQL {

    /**
     * A recreated relation's grants, exactly — none added, none lost.
     *
     * DROP discards them and CREATE starts over, and where the database has
     * default privileges the new object picks those up as well. On Supabase
     * that grants `anon` and `authenticated` everything, so a view whose grants
     * had been narrowed came back wide open. The REVOKE strips whatever
     * creation added before the recorded grants go back on.
     *
     * Plain REVOKEs rather than a DO block that reads the catalog at run time:
     * migration runners split on semicolons, and a DO block's body is full of
     * them. The grantees default privileges can add are known now, from the
     * target the migration runs against. Never the owner, whose own privileges
     * come with ownership.
     *
     * @param array<int, array{grantee: string, privilege: string, grantable: bool}> $grants
     * @param string[] $defaultGrantees
     * @return string[]
     */
    public static function restore(string $name, array $grants, string $owner, array $defaultGrantees): array {
        $lines = [];
        $revokeFrom = array_values(array_diff($defaultGrantees, [$owner]));
        if ($revokeFrom !== []) {
            $lines[] = "REVOKE ALL ON $name FROM " . implode(', ', $revokeFrom) . ';';
            // Whoever runs the migration creates the object and so owns it,
            // and may be one of those grantees without having owned the
            // original — the REVOKE would then strip the new owner's own
            // privileges. Re-granting them is a no-op in every other case.
            $lines[] = "GRANT ALL ON $name TO CURRENT_USER;";
        }
        return array_merge($lines, self::statements($grants, "ON $name"));
    }

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
