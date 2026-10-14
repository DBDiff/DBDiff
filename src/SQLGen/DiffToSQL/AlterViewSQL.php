<?php namespace DBDiff\SQLGen\DiffToSQL;


/**
 * A view whose new columns begin with its old ones is replaced in place, so
 * whatever reads it keeps working, and keeps its grants, comments and
 * triggers. CREATE OR REPLACE VIEW cannot change a column's name, type or
 * order, so any other change drops and recreates the view — see
 * ViewReplacementPlan.
 */
class AlterViewSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'VIEW';
    }

    protected function inPlace(string $definition, string $direction, string $quoted, ?array $privileges): ?array {
        if (!($this->obj->inPlace[$direction] ?? false) || !preg_match('/^\s*CREATE\s+VIEW\b/i', $definition)) {
            return null;
        }
        $lines = [preg_replace('/^\s*CREATE\s+VIEW\b/i', 'CREATE OR REPLACE VIEW', $definition, 1) . ';'];
        if ($privileges !== null) {
            // The grants it has now, and any a fresh view would pick up, give
            // way to exactly the ones this side has.
            $other = $direction === 'up' ? 'down' : 'up';
            $revokeFrom = array_values(array_diff(
                array_unique(array_merge(array_column($privileges[$other], 'grantee'), $privileges['defaultGrantees'])),
                [$privileges['owner']]
            ));
            if ($revokeFrom !== []) {
                $lines[] = "REVOKE ALL ON $quoted FROM " . implode(', ', $revokeFrom) . ';';
            }
            array_push($lines, ...GrantSQL::statements($privileges[$direction], "ON $quoted"));
        }
        return $lines;
    }
}
