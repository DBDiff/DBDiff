<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\DB\Support\PostgresSchemaHelper;

/**
 * The statements that take a column's dependants out of the way of a type
 * change and put them back afterwards (issue #226).
 *
 * Putting a view back means putting all of it back. `CREATE VIEW v AS ...`
 * alone lost the view's options — `security_invoker` among them, without which
 * the view bypasses row level security — along with its grants, comments,
 * INSTEAD OF triggers and, for a materialised view, its indexes. Of those,
 * only the options and triggers would have shown up on the next diff; grants
 * and comments were lost silently.
 *
 * `$skip` names the dependants the UP leaves to their own diffs — see
 * ColumnDependantPlan. The DOWN passes none.
 */
final class ColumnDependantsSQL {

    /**
     * @param array<string, mixed> $dependants  see PostgresColumnDependants::find()
     * @param array<string, true> $skip
     * @param 'up'|'down'          $direction  which definition a regenerated
     *                                         generated column comes back with
     */
    public function __construct(
        private array $dependants,
        private array $skip = [],
        private string $direction = 'up'
    ) {}

    /**
     * Policies and triggers first — a policy can read a view, and DROP VIEW is
     * refused while it does — then views, deepest first, so a view built on
     * another goes before the one it reads.
     *
     * @return string[]
     */
    public function drops(): array {
        $lines = [];

        foreach ($this->dependants['policies'] ?? [] as $policy) {
            $lines[] = 'DROP POLICY IF EXISTS ' . self::ident($policy['name'])
                . ' ON ' . PostgresSchemaHelper::qualifiedName($policy['schema'], $policy['table']) . ';';
        }
        foreach ($this->dependants['triggers'] ?? [] as $trigger) {
            $lines[] = 'DROP TRIGGER IF EXISTS ' . self::ident($trigger['name'])
                . ' ON ' . PostgresSchemaHelper::qualifiedName($trigger['schema'], $trigger['table']) . ';';
        }
        foreach ($this->viewsDeepestFirst() as $view) {
            $lines[] = 'DROP ' . self::keyword($view['kind']) . ' IF EXISTS '
                . PostgresSchemaHelper::qualifiedName($view['schema'], $view['name']) . ';';
        }
        // Last, once nothing reads them. Without CASCADE: whatever depends on
        // a generated column and is not handled here should stop the
        // migration, not disappear with it (issue #233).
        foreach ($this->dependants['generated'] ?? [] as $generated) {
            $lines[] = 'ALTER TABLE ' . PostgresSchemaHelper::qualifiedName($generated['schema'], $generated['table'])
                . ' DROP COLUMN ' . self::ident($generated['name']) . ';';
        }

        return $lines;
    }

    /**
     * Views shallowest first, each complete; then the policies and triggers.
     *
     * @return string[]
     */
    public function recreates(): array {
        $lines = [];

        // Generated columns first: views and policies may read them.
        foreach ($this->dependants['generated'] ?? [] as $generated) {
            array_push($lines, ...$this->recreateGenerated($generated));
        }

        foreach (array_reverse($this->viewsDeepestFirst()) as $view) {
            $key = $view['schema'] . '.' . $view['name'];
            if (isset($this->skip[$key])) {
                continue;
            }
            array_push($lines, ...$this->recreateView($view));
        }

        foreach (['policies', 'triggers'] as $kind) {
            foreach ($this->dependants[$kind] ?? [] as $object) {
                $key = $object['schema'] . '.' . $object['table'] . '.' . $object['name'];
                if (isset($this->skip[$key])) {
                    continue;
                }
                $lines[] = self::statement($object['definition']);
            }
        }

        return $lines;
    }

    /**
     * A generated column re-added, recomputed from its expression, with the
     * indexes, constraints, comment and column grants dropping it took away.
     *
     * @return string[]
     */
    private function recreateGenerated(array $generated): array {
        $table = PostgresSchemaHelper::qualifiedName($generated['schema'], $generated['table']);
        $name  = self::ident($generated['name']);
        $lines = [$this->addGeneratedColumn($generated, $table, $name)];
        foreach (array_merge($generated['constraints'] ?? [], $generated['indexes'] ?? []) as $statement) {
            $lines[] = self::statement($statement);
        }
        if (($generated['comment'] ?? null) !== null) {
            $lines[] = "COMMENT ON COLUMN $table.$name IS {$generated['comment']};";
        }
        return array_merge($lines, GrantSQL::statements($generated['grants'] ?? [], "($name) ON $table"));
    }

    /**
     * On the way up a generated column whose own definition changes comes back
     * as the source defines it; otherwise, and always on the way down, as the
     * target has it.
     */
    private function addGeneratedColumn(array $generated, string $table, string $name): string {
        if ($this->direction === 'up' && isset($generated['upDefinition'])) {
            return "ALTER TABLE $table ADD COLUMN " . rtrim(trim($generated['upDefinition']), ';') . ';';
        }
        $collate = $generated['collation'] ? " COLLATE {$generated['collation']}" : '';
        $notNull = $generated['notNull'] ? ' NOT NULL' : '';
        return "ALTER TABLE $table ADD COLUMN $name {$generated['type']}$collate"
            . " GENERATED ALWAYS AS ({$generated['expression']}) STORED$notNull;";
    }

    /** @return string[] */
    private function recreateView(array $view): array {
        $name  = PostgresSchemaHelper::qualifiedName($view['schema'], $view['name']);
        $lines = [
            'CREATE ' . self::keyword($view['kind']) . ' ' . $name
                . PostgresSchemaHelper::withOptions($view['options'] ?? null)
                . ' AS ' . $view['definition'] . ';',
        ];
        foreach ($view['indexes'] ?? [] as $indexDef) {
            $lines[] = self::statement($indexDef);
        }

        array_push($lines, ...$this->privileges($view, $name));

        if (($view['comment'] ?? null) !== null) {
            $lines[] = 'COMMENT ON ' . self::keyword($view['kind']) . " $name IS {$view['comment']};";
        }
        foreach ($view['columnComments'] ?? [] as $c) {
            $lines[] = "COMMENT ON COLUMN $name." . self::ident($c['column']) . " IS {$c['comment']};";
        }

        foreach ($view['triggers'] ?? [] as $trigger) {
            if (isset($this->skip[$view['schema'] . '.' . $view['name'] . '.' . $trigger['name']])) {
                continue;
            }
            $lines[] = self::statement($trigger['definition']);
        }

        return $lines;
    }

    /**
     * The view's grants, exactly — none added, none lost.
     *
     * DROP VIEW discards them and CREATE VIEW starts over, and where the
     * database has default privileges the new view picks those up as well. On
     * Supabase that grants `anon` and `authenticated` everything, so a view
     * whose grants had been narrowed came back wide open. The REVOKE strips
     * whatever creation added before the recorded grants go back on.
     *
     * @return string[]
     */
    private function privileges(array $view, string $name): array {
        $lines = [];

        // Plain REVOKEs rather than a DO block that reads the catalog at run
        // time: migration runners split on semicolons, and a DO block's body
        // is full of them. The grantees default privileges can add are known
        // now, from the target the migration runs against. Never the owner,
        // whose own privileges come with ownership.
        $revokeFrom = array_values(array_diff($this->dependants['defaultGrantees'] ?? [], [$view['owner'] ?? '']));
        if ($revokeFrom !== []) {
            $lines[] = "REVOKE ALL ON $name FROM " . implode(', ', $revokeFrom) . ';';
            // Whoever runs the migration creates the view and so owns it, and
            // may be one of those grantees without having owned the original
            // — the REVOKE would then strip the new owner's own privileges.
            // Re-granting them is a no-op in every other case.
            $lines[] = "GRANT ALL ON $name TO CURRENT_USER;";
        }

        return array_merge($lines, GrantSQL::statements($view['grants'] ?? [], "ON $name"));
    }

    /** @return array<int, array<string, mixed>> */
    private function viewsDeepestFirst(): array {
        $views = $this->dependants['views'] ?? [];
        usort($views, static fn($a, $b) => $b['depth'] <=> $a['depth']);
        return $views;
    }

    private static function statement(string $sql): string {
        return rtrim(trim($sql), ';') . ';';
    }

    private static function ident(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** MATERIALIZED VIEW for relkind 'm', VIEW otherwise. */
    private static function keyword(string $relkind): string {
        return $relkind === 'm' ? 'MATERIALIZED VIEW' : 'VIEW';
    }
}
