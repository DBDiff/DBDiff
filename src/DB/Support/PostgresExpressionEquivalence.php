<?php namespace DBDiff\DB\Support;

use DBDiff\Diff\AlterMatView;
use DBDiff\Diff\AlterPolicy;
use DBDiff\Diff\AlterTableChangeConstraint;
use DBDiff\Diff\AlterTableChangeKey;
use DBDiff\Diff\AlterTrigger;
use DBDiff\Diff\AlterView;
use DBDiff\Diff\AlterDomain;
use DBDiff\Diff\AlterTableChangeColumn;
use Illuminate\Database\Connection;

/**
 * Drops "changes" between two definitions PostgreSQL considers the same
 * (issue #234).
 *
 * The server's rendering of an expression is not stable across a round trip.
 * `status IN ('draft', 'active')` on a varchar column renders as
 *
 *     ((status)::text = ANY ((ARRAY['draft'::character varying, …])::text[]))
 *
 * and creating the same thing again *from that text* — which is what a dump,
 * a restore or a generated migration does — stores a differently shaped but
 * equivalent expression that renders as
 *
 *     ((status)::text = ANY (ARRAY[('draft'::character varying)::text, …]))
 *
 * DBDiff compares renderings, so a CHECK constraint, partial index, policy,
 * view or trigger condition written that way differed between a database and
 * any copy of it — a DROP + CREATE on every diff that applied cleanly and
 * changed nothing.
 *
 * The second rendering is stable: it survives a further round trip unchanged.
 * So when two renderings differ, each is created once more on its own side —
 * as a temporary object, in a transaction that is always rolled back — and the
 * stable renderings are compared. Anything that cannot be recreated there
 * (a trigger on a view, a definition naming something the temporary object
 * lacks) keeps the textual comparison: the difference is still reported, as
 * it was before.
 */
final class PostgresExpressionEquivalence {

    private const TEMP_TABLE = 'dbdiff_canon';

    /**
     * The diffs without those that are the same definition rendered twice.
     *
     * @param array<int, object> $diffs
     * @return array<int, object>
     */
    public static function dropEquivalent(array $diffs, Connection $source, Connection $target): array {
        self::$rendered = [];
        self::renderViewsAhead($diffs, $source, $target);
        try {
            return array_values(array_filter($diffs, fn($diff) => self::isRealChange($diff, $source, $target)));
        } finally {
            self::$rendered = [];
        }
    }

    /**
     * View bodies already rendered, per connection: body => rendering, or null
     * when the body could not be created there.
     *
     * @var array<int, array<string, ?string>>
     */
    private static array $rendered = [];

    /**
     * Render, up front and in one round per connection, every view body the
     * comparison will ask about.
     *
     * Each view cost four round trips per rendering (begin, create, read,
     * roll back), and across major versions the target renders two per view:
     * about 1,600 for 200 views, which over a 50 ms link nearly doubled a diff.
     * One DO block now creates every temporary view, each under its own
     * exception handler so a body that cannot be built only loses its own
     * rendering, and writes the renderings to a temporary table read back
     * once — all inside a transaction that is rolled back.
     *
     * @param array<int, object> $diffs
     */
    private static function renderViewsAhead(array $diffs, Connection $source, Connection $target): void {
        $want = [spl_object_id($source) => [], spl_object_id($target) => []];
        $crossVersion = null;
        foreach ($diffs as $diff) {
            $pair = self::definitions($diff);
            if ($pair === null || $pair[0] !== 'view') {
                continue;
            }
            [, , $sourceDef, $targetDef] = $pair;
            $sourceBody = self::viewParts($sourceDef)[1] ?? null;
            $targetBody = self::viewParts($targetDef)[1] ?? null;
            if ($sourceBody !== null) {
                $want[spl_object_id($source)][$sourceBody] = true;
            }
            if ($targetBody !== null) {
                $want[spl_object_id($target)][$targetBody] = true;
            }
            $crossVersion ??= self::major($source) !== self::major($target);
            if ($crossVersion && $sourceBody !== null) {
                $want[spl_object_id($target)][$sourceBody] = true;
            }
        }
        foreach ([$source, $target] as $connection) {
            $bodies = array_keys($want[spl_object_id($connection)]);
            if ($bodies !== []) {
                self::$rendered[spl_object_id($connection)] = self::renderViewBodies($connection, $bodies);
            }
        }
    }

    /**
     * @param array<int, string> $bodies
     * @return array<string, ?string>
     */
    private static function renderViewBodies(Connection $connection, array $bodies): array {
        $pdo = $connection->getPdo();
        $quote = fn(string $s) => "'" . str_replace("'", "''", $s) . "'";
        $blocks = [];
        foreach (array_values($bodies) as $i => $body) {
            $view = 'dbdiff_canon_b' . $i;
            $blocks[] = "BEGIN EXECUTE " . $quote("CREATE TEMP VIEW $view AS " . $body) . "; "
                . "INSERT INTO pg_temp.dbdiff_canon_out VALUES ($i, pg_get_viewdef('pg_temp.$view'::regclass, true)); "
                . "EXCEPTION WHEN others THEN NULL; END;";
        }
        $out = array_fill_keys($bodies, null);
        $pdo->beginTransaction();
        try {
            $pdo->exec("CREATE TEMP TABLE dbdiff_canon_out (i int, def text);\n"
                . "DO \$dbdiff_canon\$ BEGIN\n" . implode("\n", $blocks) . "\nEND \$dbdiff_canon\$;");
            $list = array_values($bodies);
            foreach ($connection->select('SELECT i, def FROM pg_temp.dbdiff_canon_out') as $row) {
                $out[$list[(int) $row['i']]] = $row['def'];
            }
        } catch (\Throwable $e) {
            // Rendered one at a time instead, as before.
            return [];
        } finally {
            $pdo->rollBack();
        }
        return $out;
    }

    /**
     * A view definition as [head, body, tail]: `CREATE [MATERIALIZED] VIEW
     * "v" [WITH (...)]`, the query, and a materialised view's index
     * definitions after it; null when it is not one.
     *
     * @return array{0: string, 1: string, 2: ?string}|null
     */
    private static function viewParts(string $definition): ?array {
        if (!preg_match('/^CREATE\s+(?:MATERIALIZED\s+)?VIEW\s+/', $definition, $create)
            || ($name = self::leadingName(substr($definition, strlen($create[0])))) === null
            || !preg_match('/^((?:\s+WITH\s+\([^)]*\))?)\s+AS\s+(.*)$/s', $name[1], $rest)) {
            return null;
        }
        $parts = explode(";\n", $rest[2], 2);
        return [$create[0] . $name[0] . $rest[1], $parts[0], $parts[1] ?? null];
    }

    /** False when the two definitions are the same one rendered differently. */
    private static function isRealChange(object $diff, Connection $source, Connection $target): bool {
        $pair = self::definitions($diff);
        if ($pair === null) {
            return true;
        }
        [$kind, $table, $sourceDef, $targetDef] = $pair;
        $a = self::canonical($source, $kind, $table, $sourceDef);
        $b = self::canonical($target, $kind, $table, $targetDef);
        // Across major versions the same definition prints differently —
        // PostgreSQL 16 dropped the table qualifiers 15 writes in a view
        // (`SELECT id` against `SELECT vt.id`) — so each side's own rendering
        // never matches. Rendered on one server, they do.
        $same = ($a !== null && $a === $b)
            || ($b !== null && self::major($source) !== self::major($target)
                && self::canonical($target, $kind, $table, $sourceDef) === $b);
        return !$same;
    }

    /** @var array<int, int> server major version, per connection */
    private static array $majors = [];

    private static function major(Connection $connection): int {
        $key = spl_object_id($connection);
        return self::$majors[$key] ??= intdiv((int) $connection->selectOne('SHOW server_version_num')['server_version_num'], 10000);
    }

    /**
     * [kind, table, source definition, target definition] for a diff this
     * applies to, or null.
     */
    private static function definitions(object $diff): ?array {
        $readers = [
            AlterTableChangeConstraint::class => fn($d) => ['check', $d->table, $d->diff->getNewValue(), $d->diff->getOldValue()],
            AlterTableChangeKey::class        => fn($d) => ['index', $d->table, $d->diff->getNewValue(), $d->diff->getOldValue()],
            AlterPolicy::class                => fn($d) => ['policy', $d->table, $d->sourceDefinition, $d->targetDefinition],
            AlterTrigger::class               => fn($d) => ['trigger', $d->table, $d->sourceDefinition, $d->targetDefinition],
            AlterView::class                  => fn($d) => ['view', null, $d->sourceDefinition, $d->targetDefinition],
            AlterMatView::class               => fn($d) => ['view', null, $d->sourceDefinition, $d->targetDefinition],
            AlterDomain::class                => fn($d) => ['domain', $d->name, $d->sourceDefinition, $d->targetDefinition],
            AlterTableChangeColumn::class     => fn($d) => self::generatedExpressionOnly($d)
                ? ['generated', $d->table, $d->diff->getNewValue(), $d->diff->getOldValue()]
                : null,
        ];
        $read = $readers[get_class($diff)] ?? null;
        return $read === null ? null : $read($diff);
    }

    /**
     * The definition as the server renders it after one more round trip, or
     * null when it cannot be recreated as a temporary object.
     */
    public static function canonical(Connection $connection, string $kind, ?string $table, string $definition): ?string {
        // Already rendered with the rest (renderViewsAhead): no transaction needed.
        if ($kind === 'view' && ($parts = self::viewParts($definition)) !== null
            && array_key_exists($parts[1], self::$rendered[spl_object_id($connection)] ?? [])) {
            return self::view($connection, $definition);
        }
        $pdo = $connection->getPdo();
        $pdo->beginTransaction();
        try {
            return match ($kind) {
                'check'   => self::check($connection, $table, $definition),
                'index'   => self::index($connection, $table, $definition),
                'policy'  => self::policy($connection, $table, $definition),
                'trigger' => self::trigger($connection, $table, $definition),
                'view'    => self::view($connection, $definition),
                'domain'  => self::domain($connection, $table, $definition),
                'generated' => self::generated($connection, $table, $definition),
                default   => null,
            };
        } catch (\Throwable $e) {
            return null;
        } finally {
            $pdo->rollBack();
        }
    }

    /** A temporary table with the same columns, to attach things to. */
    /**
     * The name a definition opens with — quoted or not, qualified or not —
     * and the rest of it; null when it does not open with one.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function leadingName(string $sql): ?array {
        $name = '';
        while (preg_match('/^(?:"(?:[^"]|"")*"|[^\s".(]+)/', $sql, $part)) {
            $name .= $part[0];
            $sql = substr($sql, strlen($part[0]));
            if (!str_starts_with($sql, '.')) {
                return [$name, $sql];
            }
            $name .= '.';
            $sql = substr($sql, 1);
        }
        return null;
    }

    private static function tempTable(Connection $connection, string $table): void {
        $connection->statement(
            'CREATE TEMP TABLE ' . self::TEMP_TABLE . ' (LIKE ' . PostgresSchemaHelper::qualifiedName(SchemaScope::of($connection), $table)
            . ')'
        );
    }

    /** `CONSTRAINT "name" CHECK (...)`, re-rendered. Other kinds are left alone. */
    private static function check(Connection $connection, string $table, string $definition): ?string {
        if (!preg_match('/^(CONSTRAINT\s+"(?:[^"]|"")+")\s+(CHECK\b.*)$/s', $definition, $m)) {
            return null;
        }
        self::tempTable($connection, $table);
        $connection->statement('ALTER TABLE ' . self::TEMP_TABLE . ' ADD CONSTRAINT dbdiff_canon_c ' . $m[2]);
        $row = $connection->selectOne(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint
              WHERE conrelid = 'pg_temp." . self::TEMP_TABLE . "'::regclass AND conname = 'dbdiff_canon_c'"
        );
        return $row === null ? null : $m[1] . ' ' . $row['def'];
    }

    /** `CREATE [UNIQUE] INDEX name ON table USING ...`, re-rendered. */
    private static function index(Connection $connection, string $table, string $definition): ?string {
        if (!preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+(\S+)\s+ON\s+(?:ONLY\s+)?\S+\s+(USING\b.*)$/s', $definition, $m)) {
            return null;
        }
        self::tempTable($connection, $table);
        $connection->statement('CREATE ' . $m[1] . 'INDEX dbdiff_canon_i ON ' . self::TEMP_TABLE . ' ' . $m[3]);
        $row = $connection->selectOne("SELECT pg_get_indexdef('pg_temp.dbdiff_canon_i'::regclass) AS def");
        return $row === null ? null : $m[2] . ' ' . self::withoutTemp($row['def']);
    }

    /** DBDiff's `CREATE POLICY "name" ON "table" ...`, re-rendered the same way. */
    private static function policy(Connection $connection, string $table, string $definition): ?string {
        if (!preg_match('/^CREATE\s+POLICY\s+("(?:[^"]|"")+")\s+ON\s+(.*)$/s', $definition, $m)
            || ($relation = self::leadingName($m[2])) === null) {
            return null;
        }
        self::tempTable($connection, $table);
        $connection->statement('CREATE POLICY ' . $m[1] . ' ON ' . self::TEMP_TABLE . $relation[1]);
        $row = $connection->selectOne(
            "SELECT p.polname AS name, p.polpermissive AS permissive,
                    CASE p.polcmd WHEN 'r' THEN 'SELECT' WHEN 'a' THEN 'INSERT'
                                  WHEN 'w' THEN 'UPDATE' WHEN 'd' THEN 'DELETE'
                                  ELSE 'ALL' END AS command,
                    (SELECT string_agg(quote_ident(r.rolname), ', ' ORDER BY r.rolname)
                       FROM unnest(p.polroles) AS ro
                       JOIN pg_roles r ON r.oid = ro) AS roles,
                    pg_get_expr(p.polqual, p.polrelid) AS using_expr,
                    pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
               FROM pg_policy p
              WHERE p.polrelid = 'pg_temp." . self::TEMP_TABLE . "'::regclass"
        );
        return $row === null ? null : PostgresSchemaHelper::policyDefinition($row, PostgresIdent::quote($table));
    }

    /** `pg_get_triggerdef` output, re-rendered. A trigger on a view is not attempted. */
    private static function trigger(Connection $connection, string $table, string $definition): ?string {
        if (!preg_match('/^(CREATE\s+(?:CONSTRAINT\s+)?TRIGGER\s+.*?\s+ON\s+)\S+(\s.*)$/s', $definition, $m)) {
            return null;
        }
        self::tempTable($connection, $table);
        $connection->statement($m[1] . self::TEMP_TABLE . $m[2]);
        $row = $connection->selectOne(
            "SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger
              WHERE tgrelid = 'pg_temp." . self::TEMP_TABLE . "'::regclass AND NOT tgisinternal"
        );
        return $row === null ? null : self::withoutTemp($row['def']);
    }

    /**
     * `CREATE [MATERIALIZED] VIEW "v" [WITH (...)] AS body[;\n<indexes>]` with
     * the body re-rendered through a temporary view — the same deparser a
     * materialised view's body goes through.
     */
    private static function view(Connection $connection, string $definition): ?string {
        $parts = self::viewParts($definition);
        if ($parts === null) {
            return null;
        }
        [$head, $body, $tail] = $parts;
        $cache = self::$rendered[spl_object_id($connection)] ?? [];
        if (array_key_exists($body, $cache)) {
            $def = $cache[$body];
        } else {
            $connection->statement('CREATE TEMP VIEW dbdiff_canon_v AS ' . $body);
            $def = $connection->selectOne("SELECT pg_get_viewdef('pg_temp.dbdiff_canon_v'::regclass, true) AS def")['def'] ?? null;
        }
        if ($def === null) {
            return null;
        }
        return $head . ' AS ' . rtrim(trim($def), ';') . ($tail !== null ? ";\n" . $tail : '');
    }

    /**
     * A regenerated column whose only difference is its expression's text —
     * the one case where equivalence decides whether to drop and re-add it.
     */
    private static function generatedExpressionOnly(AlterTableChangeColumn $diff): bool {
        if (!$diff->regenerated) {
            return false;
        }
        $old = PostgresColumnDefinition::parse($diff->diff->getOldValue());
        $new = PostgresColumnDefinition::parse($diff->diff->getNewValue());
        return $old->isGenerated() && $new->isGenerated()
            && $old->generated !== $new->generated
            && $old->typeWithCollation() === $new->typeWithCollation()
            && $old->notNull === $new->notNull
            && $old->compression === $new->compression;
    }

    /** A stored generated column's definition with its expression re-rendered. */
    private static function generated(Connection $connection, string $table, string $definition): ?string {
        $column = PostgresColumnDefinition::parse($definition);
        self::tempTable($connection, $table);
        $connection->statement('ALTER TABLE ' . self::TEMP_TABLE . ' ADD COLUMN dbdiff_canon_g '
            . $column->typeWithCollation() . " GENERATED ALWAYS AS ({$column->generated}) STORED");
        $row = $connection->selectOne(
            "SELECT pg_get_expr(ad.adbin, ad.adrelid) AS expr
               FROM pg_attrdef ad
               JOIN pg_attribute a ON a.attrelid = ad.adrelid AND a.attnum = ad.adnum
              WHERE ad.adrelid = 'pg_temp." . self::TEMP_TABLE . "'::regclass AND a.attname = 'dbdiff_canon_g'"
        );
        return $row === null ? null : $column->typeWithCollation() . ' AS (' . $row['expr'] . ')';
    }

    /** `CREATE DOMAIN "d" AS ...` re-rendered through a temporary domain. */
    private static function domain(Connection $connection, string $name, string $definition): ?string {
        if (!preg_match('/^CREATE\s+DOMAIN\s+(.*)$/s', $definition, $create)
            || ($domain = self::leadingName($create[1])) === null
            || !preg_match('/^\s+(AS\b.*)$/s', $domain[1], $m)) {
            return null;
        }
        $connection->statement('CREATE DOMAIN pg_temp.dbdiff_canon_d ' . $m[1]);
        return PostgresObjectKinds::domainDefinition($connection, 'pg_temp.dbdiff_canon_d', $name);
    }

    /** The temporary table's session-specific schema, taken out so both sides compare. */
    private static function withoutTemp(string $sql): string {
        return preg_replace('/\bpg_temp(?:_\d+)?\.' . self::TEMP_TABLE . '\b/', '<table>', $sql);
    }
}
