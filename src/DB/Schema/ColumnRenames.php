<?php namespace DBDiff\DB\Schema;

use DBDiff\DB\Support\SchemaScope;
use DBDiff\Logger;
use Illuminate\Database\Connection;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;

/**
 * A column renamed on the source reads as one column dropped and another
 * added — and applied that way, the column's data is gone.
 *
 * A column the target has and the source lacks, and one the source has and
 * the target lacks, defined the same way apart from the name, are a rename
 * when they are the only such pair in the table. Never guessed: two or more
 * columns sharing a definition on either side are left as drops and adds,
 * and said so. Generated columns (whose expression names other columns) and
 * serial ones (whose sequence is named after the column) are not matched.
 */
final class ColumnRenames {

    /**
     * @param array<string, object> $diffs column diffs, target → source
     * @return array{0: array<string, object>, 1: array<string, string>} the diffs without the
     *         renamed pairs, and the renames, new name => old name
     */
    public static function detect(string $table, array $diffs, array $namedNotNull = ['source' => [], 'target' => []]): array {
        $removed = [];
        $added   = [];
        foreach ($diffs as $column => $diff) {
            if ($diff instanceof DiffOpRemove
                && ($shape = self::shape($diff->getOldValue(), in_array($column, $namedNotNull['target'] ?? [], true))) !== null) {
                $removed[$shape][] = $column;
            } elseif ($diff instanceof DiffOpAdd
                && ($shape = self::shape($diff->getNewValue(), in_array($column, $namedNotNull['source'] ?? [], true))) !== null) {
                $added[$shape][] = $column;
            }
        }

        $renames = [];
        foreach ($removed as $shape => $from) {
            $to = $added[$shape] ?? [];
            if ($to === []) {
                continue;
            }
            if (count($from) === 1 && count($to) === 1) {
                $renames[$to[0]] = $from[0];
                unset($diffs[$from[0]], $diffs[$to[0]]);
                Logger::info("Table `$table`: column `{$from[0]}` is renamed `{$to[0]}`, keeping its data (--renames=off to drop and add)");
            } else {
                Logger::warning(sprintf(
                    'Table `%s`: columns %s go and %s come with the same definition — not taken for renames, so their data is not carried over',
                    $table, implode(', ', $from), implode(', ', $to)
                ));
            }
        }
        return [$diffs, $renames];
    }

    /**
     * The definition without the column's name, or null when it cannot be
     * matched. Its NOT NULL is the column's or, from PostgreSQL 18, a
     * constraint under a name of its own (`$namedNotNull`) — a column renamed
     * on the source keeps its old one — and reads the same either way.
     */
    private static function shape(string $definition, bool $namedNotNull = false): ?string {
        if (preg_match('/GENERATED\s+ALWAYS\s+AS\s+\(|nextval\(|\bserial\b|\bbigserial\b|\bsmallserial\b/i', $definition)) {
            return null;
        }
        $rest = preg_replace('/^\s*(?:"(?:[^"]|"")*"|\S+)\s+/', '', $definition, 1);
        if ($rest === null || $rest === $definition) {
            return null;
        }
        $rest = preg_replace('/(?<!\bIS)\s+NOT NULL\b/', '', trim($rest), 1, $inline);
        return $rest . ($inline || $namedNotNull ? ' NOT NULL' : '');
    }

    /** The columns whose NOT NULL is a constraint of a name of its own, among a table's constraints. */
    public static function namedNotNullColumns(array $constraints): array {
        $out = [];
        foreach ($constraints as $definition) {
            if (preg_match('/^CONSTRAINT\s+(?:"(?:[^"]|"")*"|\S+)\s+NOT NULL\s+"((?:[^"]|"")+)"/', (string) $definition, $m)) {
                $out[] = str_replace('""', '"', $m[1]);
            }
        }
        return $out;
    }

    /**
     * Indexes or constraints a column rename carries along.
     *
     * One the target names as the source does whose definition differs only
     * by the renamed column needs nothing: RENAME COLUMN updates it. One named
     * after the old column (`t_name_check`, `t_name_not_null`) whose source
     * counterpart is named after the new one, defined the same once the
     * column is renamed, is renamed rather than dropped and created again.
     * Matched on the whole definition, so a lookalike is never taken.
     *
     * @param array<string, string> $renames new name => old name
     * @param array<string, string> $target  name => definition
     * @param array<string, string> $source  name => definition
     * @return array{0: array<string, true>, 1: array<string, string>} names left as they are, and renames target => source
     */
    public static function carried(array $renames, array $target, array $source): array {
        $unchanged = [];
        $renamed   = [];
        foreach ($target as $name => $definition) {
            foreach ($renames as $to => $from) {
                $moved = self::substitute($definition, $from, $to);
                if (isset($source[$name])) {
                    if ($moved === $source[$name]) {
                        $unchanged[$name] = true;
                    }
                    continue;
                }
                $candidate = str_replace($from, $to, $name);
                if ($candidate !== $name && isset($source[$candidate]) && !isset($target[$candidate])
                    && self::substitute($moved, $name, $candidate) === $source[$candidate]) {
                    $renamed[$name] = $candidate;
                }
            }
        }
        return [$unchanged, $renamed];
    }

    /** `$definition` with the identifier `$from`, quoted or not, as `$to`. */
    private static function substitute(string $definition, string $from, string $to): string {
        $quotedFrom = '"' . str_replace('"', '""', $from) . '"';
        $quotedTo   = preg_match('/^[a-z_][a-z0-9_$]*$/', $to) ? $to : '"' . str_replace('"', '""', $to) . '"';
        $out = str_replace($quotedFrom, '"' . str_replace('"', '""', $to) . '"', $definition);
        return preg_replace('/(?<![\w$"])' . preg_quote($from, '/') . '(?![\w$"])/', $quotedTo, $out) ?? $out;
    }

    /**
     * The renamed column's NOT NULL constraint under its default name, as
     * [old name, new name], or null: before PostgreSQL 18 a NOT NULL is no
     * constraint, and one named otherwise is compared with the others.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function defaultNotNull(Connection $target, string $table, string $from, string $to): ?array {
        $old = "{$table}_{$from}_not_null";
        $row = $target->selectOne(
            "SELECT 1 AS found FROM pg_constraint
              WHERE conrelid = to_regclass(?) AND contype = 'n' AND conname = ?",
            [SchemaScope::name($target, $table), $old]
        );
        return $row === null ? null : [$old, "{$table}_{$to}_not_null"];
    }
}
