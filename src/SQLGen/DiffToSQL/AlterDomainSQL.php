<?php namespace DBDiff\SQLGen\DiffToSQL;


/**
 * A changed domain.
 *
 * ALTER DOMAIN amends a default, NOT NULL and CHECK constraints in place, and
 * that is what it is used for: replacing the domain means DROP DOMAIN, which
 * fails while any column uses it — and with CASCADE would drop those columns.
 * Replacing also lost a constraint's NOT VALID, which CREATE DOMAIN does not
 * accept, so a domain that gained a NOT VALID check could not be migrated at
 * all. Only a change of base type or collation, which ALTER DOMAIN cannot make,
 * still replaces it.
 */
class AlterDomainSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'DOMAIN';
    }

    protected function inPlace(string $definition, string $direction, string $quoted, ?array $privileges): ?array {
        $current = $direction === 'up' ? $this->obj->targetDefinition : $this->obj->sourceDefinition;
        $want    = self::parse($definition);
        $have    = self::parse($current);
        if ($want === null || $have === null || $want['base'] !== $have['base']) {
            return null;
        }

        $lines = [];
        foreach ($have['constraints'] as $name => $def) {
            if (($want['constraints'][$name] ?? null) !== $def) {
                $lines[] = "ALTER DOMAIN $quoted DROP CONSTRAINT IF EXISTS $name;";
            }
        }
        if ($want['default'] !== $have['default']) {
            $lines[] = $want['default'] === null
                ? "ALTER DOMAIN $quoted DROP DEFAULT;"
                : "ALTER DOMAIN $quoted SET DEFAULT {$want['default']};";
        }
        if ($want['notNull'] !== $have['notNull']) {
            $lines[] = "ALTER DOMAIN $quoted " . ($want['notNull'] ? 'SET' : 'DROP') . ' NOT NULL;';
        }
        foreach ($want['constraints'] as $name => $def) {
            if (($have['constraints'][$name] ?? null) !== $def) {
                $lines[] = "ALTER DOMAIN $quoted ADD CONSTRAINT $name $def;";
            }
        }
        return $lines;
    }

    /**
     * A definition (or its tail) split at each `CONSTRAINT name CHECK`, as
     * PostgresObjectKinds writes them: the text before the first, and each
     * constraint's body keyed by its name as written.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function splitConstraints(string $sql): array {
        $parts = preg_split('/ CONSTRAINT ("(?:[^"]|"")+"|[a-z_][a-z0-9_$]*) (?=CHECK )/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $head  = array_shift($parts);
        $constraints = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $constraints[$parts[$i]] = $parts[$i + 1];
        }
        return [$head, $constraints];
    }

    /**
     * The parts of a definition as PostgresObjectKinds renders it:
     * `CREATE DOMAIN n AS base [COLLATE c] [DEFAULT e] [NOT NULL] [CONSTRAINT n CHECK (...) [NOT VALID]]...`
     *
     * @return array{base: string, default: ?string, notNull: bool, constraints: array<string, string>}|null
     */
    private static function parse(string $definition): ?array {
        if (!preg_match('/^CREATE DOMAIN .+? AS (.*)$/s', $definition, $m)) {
            return null;
        }
        [$head, $constraints] = self::splitConstraints($m[1]);
        $notNull = str_ends_with($head, ' NOT NULL');
        if ($notNull) {
            $head = substr($head, 0, -strlen(' NOT NULL'));
        }
        $default = null;
        $at = strpos($head, ' DEFAULT ');
        if ($at !== false) {
            $default = substr($head, $at + strlen(' DEFAULT '));
            $head    = substr($head, 0, $at);
        }
        return ['base' => $head, 'default' => $default, 'notNull' => $notNull, 'constraints' => $constraints];
    }
}
