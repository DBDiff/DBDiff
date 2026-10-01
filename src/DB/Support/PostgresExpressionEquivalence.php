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
        return array_values(array_filter($diffs, function ($diff) use ($source, $target) {
            $pair = self::definitions($diff);
            if ($pair === null) {
                return true;
            }
            [$kind, $table, $sourceDef, $targetDef] = $pair;
            $a = self::canonical($source, $kind, $table, $sourceDef);
            $b = self::canonical($target, $kind, $table, $targetDef);
            return $a === null || $b === null || $a !== $b;
        }));
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
    private static function tempTable(Connection $connection, string $table): void {
        $connection->statement(
            'CREATE TEMP TABLE ' . self::TEMP_TABLE . ' (LIKE ' . PostgresSchemaHelper::qualifiedName('public', $table)
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
        if (!preg_match('/^CREATE\s+POLICY\s+("(?:[^"]|"")+")\s+ON\s+(?:"(?:[^"]|"")+"|\S+)(.*)$/s', $definition, $m)) {
            return null;
        }
        self::tempTable($connection, $table);
        $connection->statement('CREATE POLICY ' . $m[1] . ' ON ' . self::TEMP_TABLE . $m[2]);
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
        return $row === null ? null : PostgresSchemaHelper::policyDefinition($row, '"' . $table . '"');
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
        if (!preg_match('/^(CREATE\s+(?:MATERIALIZED\s+)?VIEW\s+"(?:[^"]|"")+"(?:\s+WITH\s+\([^)]*\))?)\s+AS\s+(.*)$/s', $definition, $m)) {
            return null;
        }
        // A materialised view carries its index definitions after the body.
        $parts = explode(";\n", $m[2], 2);
        $connection->statement('CREATE TEMP VIEW dbdiff_canon_v AS ' . $parts[0]);
        $row = $connection->selectOne("SELECT pg_get_viewdef('pg_temp.dbdiff_canon_v'::regclass, true) AS def");
        if ($row === null) {
            return null;
        }
        return $m[1] . ' AS ' . rtrim(trim($row['def']), ';') . (isset($parts[1]) ? ";\n" . $parts[1] : '');
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
        if (!preg_match('/^CREATE\s+DOMAIN\s+"(?:[^"]|"")+"\s+(AS\b.*)$/s', $definition, $m)) {
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
