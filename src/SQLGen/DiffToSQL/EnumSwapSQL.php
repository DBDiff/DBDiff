<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\DB\Support\EnumLabels;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;

/**
 * An enum's labels removed or reordered by moving everything to a new type
 * (issue #237):
 *
 *     CREATE TYPE "st__new" AS ENUM ('new', 'shipped');
 *     -- what reads the columns, and what PostgreSQL would parse again, stands aside
 *     ALTER TABLE "o" ALTER COLUMN "s" DROP DEFAULT;
 *     ALTER TABLE "o" ALTER COLUMN "s" TYPE "st__new" USING "s"::text::"st__new";
 *     DROP TYPE "st";
 *     ALTER TYPE "st__new" RENAME TO "st";
 *     ALTER TABLE "o" ALTER COLUMN "s" SET DEFAULT 'new'::st;
 *     -- and everything that stood aside comes back
 *
 * Every column's data is kept. A row holding a label that no longer exists
 * fails the `USING` cast, which is the right outcome: those rows need dealing
 * with first, and nothing has been changed when the statement fails.
 *
 * The columns of one table are retyped in one statement: a foreign key
 * between two of them is checked once both have the new type.
 */
final class EnumSwapSQL {

    /** @param array<string, mixed> $swap  one direction of EnumSwapPlan's plan */
    public function __construct(
        private string $type,
        private array $labels,
        private array $swap,
        private SQLDialectInterface $dialect
    ) {}

    /** @return string[] */
    public function statements(): array {
        $old = $this->dialect->qualify($this->type);
        $new = $this->dialect->qualify(substr($this->type, 0, 58) . '__new');
        // As they are now, never with a definition another diff gives them.
        $dependants = new ColumnDependantsSQL($this->swap['dependants'], $this->swap['skip'], 'down');

        return array_merge(
            [EnumLabels::createStatement($new, $this->labels)],
            $dependants->drops(),
            $this->standAside(),
            $this->retypes($new),
            // RENAME TO takes a bare name: the type stays in its schema.
            ["DROP TYPE $old;", "ALTER TYPE $new RENAME TO " . $this->dialect->quote($this->type) . ';'],
            $this->putBack(),
            $this->typeMetadata($old),
            $dependants->recreates()
        );
    }

    /** What the retype would parse again, and the defaults it cannot cast. */
    private function standAside(): array {
        $usage = $this->swap['usage'];
        $lines = [];
        foreach ($usage['constraints'] as $c) {
            $lines[] = $this->alterTable($c['table'], 'DROP CONSTRAINT IF EXISTS ' . $this->dialect->quote($c['name']));
        }
        foreach ($usage['indexes'] as $i) {
            $lines[] = 'DROP INDEX IF EXISTS ' . $this->dialect->qualify($i['name']) . ';';
        }
        foreach ($usage['columns'] as $c) {
            if ($c['default'] !== null) {
                $lines[] = $this->alterColumn($c, 'DROP DEFAULT');
            }
        }
        return $lines;
    }

    /**
     * The defaults, constraints and indexes back, as they were — those that
     * name a removed label excepted, which another diff replaces.
     */
    private function putBack(): array {
        $usage = $this->swap['usage'];
        $lines = [];
        foreach ($usage['columns'] as $c) {
            if (!empty($c['restoreDefault'])) {
                $lines[] = $this->alterColumn($c, 'SET DEFAULT ' . $c['default']);
            }
        }
        foreach (array_filter($usage['constraints'], fn($c) => $c['recreate']) as $c) {
            $name    = $this->dialect->quote($c['name']);
            $lines[] = $this->alterTable($c['table'], "ADD CONSTRAINT $name {$c['definition']}");
            array_push($lines, ...self::comment("CONSTRAINT $name ON " . $this->dialect->qualify($c['table']), $c['comment']));
        }
        foreach (array_filter($usage['indexes'], fn($i) => $i['recreate']) as $i) {
            $lines[] = rtrim($i['definition'], ';') . ';';
            array_push($lines, ...self::comment('INDEX ' . $this->dialect->qualify($i['name']), $i['comment']));
        }
        return $lines;
    }

    /** One `ALTER TABLE` per table, retyping all of its columns of the type. */
    private function retypes(string $new): array {
        $byTable = [];
        foreach ($this->swap['usage']['columns'] as $c) {
            if ($c['inherited']) {
                continue;
            }
            $col  = $this->dialect->quote($c['column']);
            $cast = $c['array'] ? "text[]::{$new}[]" : "text::$new";
            $type = $c['array'] ? "{$new}[]" : $new;
            $byTable[$c['table']][] = "ALTER COLUMN $col TYPE $type USING $col::$cast";
        }
        $lines = [];
        foreach ($byTable as $table => $clauses) {
            $lines[] = $this->alterTable($table, implode(', ', $clauses));
        }
        return $lines;
    }

    private function alterColumn(array $column, string $clause): string {
        return $this->alterTable($column['table'], 'ALTER COLUMN ' . $this->dialect->quote($column['column']) . ' ' . $clause);
    }

    /** `ALTER TABLE "t" <clause>;` */
    private function alterTable(string $table, string $clause): string {
        return 'ALTER TABLE ' . $this->dialect->qualify($table) . " $clause;";
    }

    /** The comment and grants DROP TYPE took with the old type. */
    private function typeMetadata(string $type): array {
        $usage = $this->swap['usage'];
        $lines = self::comment("TYPE $type", $usage['comment'] ?? null);
        if (!empty($usage['publicRevoked'])) {
            $lines[] = "REVOKE USAGE ON TYPE $type FROM PUBLIC;";
        }
        return array_merge($lines, GrantSQL::statements($usage['grants'] ?? [], "ON TYPE $type"));
    }

    /** @return string[] */
    private static function comment(string $on, ?string $quotedComment): array {
        return $quotedComment === null ? [] : ["COMMENT ON $on IS $quotedComment;"];
    }
}
