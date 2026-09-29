<?php namespace DBDiff\SQLGen\DiffToSQL;


/**
 * Changing the labels of an enum.
 *
 * Adding one needs no drop: `ALTER TYPE ... ADD VALUE` appends a label, and
 * since PostgreSQL 12 `BEFORE` / `AFTER` can place it anywhere in the ordering.
 * That matters because an enum in use cannot be dropped at all —
 *
 *     ERROR:  cannot drop type order_state because other objects depend on it
 *     DETAIL: column state of table public.orders depends on type ...
 *
 * — and a column of that type is the normal case, so the drop-and-recreate
 * this used to emit unconditionally produced a migration that could not run in
 * either direction (issue #228). Adding a value in development and promoting
 * it is ordinary work.
 *
 * Removing a label has no equivalent. There is no `DROP VALUE`, and doing it
 * properly means migrating every dependent column to a new type — a
 * multi-statement rewrite that touches data and cannot be generated blind. For
 * that case the type is still replaced, which is what the reverse of an
 * addition needs too, and the statement carries a note saying why it may not
 * apply.
 *
 * Each direction is decided on its own: the UP may be additive while the DOWN
 * is not, which is exactly what reverting an added label looks like.
 */
class AlterEnumSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'TYPE';
    }

    public function getUp(): string {
        return $this->transition($this->obj->targetDefinition, $this->obj->sourceDefinition);
    }

    public function getDown(): string {
        return $this->transition($this->obj->sourceDefinition, $this->obj->targetDefinition);
    }

    /**
     * SQL that turns the enum described by `$from` into the one in `$to`.
     *
     * `ALTER TYPE ... ADD VALUE` when every existing label survives in the same
     * relative order; otherwise the type is replaced, as before.
     */
    private function transition(string $from, string $to): string {
        $fromLabels = self::labelsOf($from);
        $toLabels   = self::labelsOf($to);

        if ($fromLabels === null || $toLabels === null) {
            return $this->replace($to);
        }

        $additions = self::additions($fromLabels, $toLabels);
        if ($additions === null) {
            return $this->replace($to);
        }
        if ($additions === []) {
            // The labels match; whatever differs is not something ADD VALUE can
            // express, so fall back rather than emit nothing.
            return $this->replace($to);
        }

        $quoted = $this->dialect->quote($this->obj->name);
        $lines  = [];
        foreach ($additions as $addition) {
            [$label, $position, $neighbour] = $addition;
            $lines[] = "ALTER TYPE $quoted ADD VALUE IF NOT EXISTS " . self::quoteLabel($label)
                . ($position === null ? '' : " $position " . self::quoteLabel($neighbour))
                . ';';
        }

        return implode("\n", $lines);
    }

    /** Drop and recreate, with a note about why it may not apply. */
    private function replace(string $definition): string {
        $quoted = $this->dialect->quote($this->obj->name);

        return "-- Removing or reordering enum labels needs the type replaced, which\n"
            . "-- PostgreSQL refuses while any column still uses it. Migrate the\n"
            . "-- dependent columns to a new type first, or apply this by hand.\n"
            . 'DROP TYPE IF EXISTS ' . $quoted . ";\n" . $definition . ';';
    }

    /**
     * The labels of a `CREATE TYPE ... AS ENUM (...)` definition, in order.
     *
     * Returns null when the definition is not that shape, so an unexpected
     * rendering falls back to replacement rather than producing nonsense.
     *
     * @return string[]|null
     */
    public static function labelsOf(string $definition): ?array {
        if (!preg_match('/\bAS\s+ENUM\s*\((.*)\)\s*;?\s*$/is', $definition, $m)) {
            return null;
        }

        $body   = $m[1];
        $labels = [];
        $i      = 0;
        $length = strlen($body);

        while ($i < $length) {
            $char = $body[$i];
            if ($char === "'") {
                $i++;
                $label = '';
                while ($i < $length) {
                    if ($body[$i] === "'") {
                        // A doubled quote is an escaped one, not the end.
                        if (($body[$i + 1] ?? '') === "'") {
                            $label .= "'";
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $label .= $body[$i];
                    $i++;
                }
                $labels[] = $label;
                continue;
            }
            // Only separators and whitespace belong between labels; anything
            // else means this is not a plain label list.
            if ($char !== ',' && trim($char) !== '') {
                return null;
            }
            $i++;
        }

        return $labels === [] ? null : $labels;
    }

    /**
     * The labels `$to` adds to `$from`, each with where it goes.
     *
     * Returns null when `$from` is not a subsequence of `$to` — a label was
     * removed or the order changed, neither of which ADD VALUE can do.
     *
     * Each entry is [label, 'BEFORE'|'AFTER'|null, neighbour].
     *
     * @param string[] $from
     * @param string[] $to
     * @return array<int, array{0: string, 1: ?string, 2: ?string}>|null
     */
    public static function additions(array $from, array $to): ?array {
        // Every surviving label must appear in `to` in the same relative
        // order, or this is a reorder rather than an addition.
        $kept = array_values(array_filter($to, static fn($l) => in_array($l, $from, true)));
        if ($kept !== array_values($from)) {
            return null;
        }

        $current   = array_values($from);
        $additions = [];

        foreach ($to as $index => $label) {
            if (in_array($label, $current, true)) {
                continue;
            }

            // Anchor to a neighbour that already exists, preferring the one
            // before it so the labels land in `to`'s order.
            $position = null;
            $neighbour = null;
            for ($i = $index - 1; $i >= 0; $i--) {
                if (in_array($to[$i], $current, true)) {
                    $position = 'AFTER';
                    $neighbour = $to[$i];
                    break;
                }
            }
            if ($position === null) {
                for ($i = $index + 1; $i < count($to); $i++) {
                    if (in_array($to[$i], $current, true)) {
                        $position = 'BEFORE';
                        $neighbour = $to[$i];
                        break;
                    }
                }
            }

            $additions[] = [$label, $position, $neighbour];

            // Insert it where it now sits, so the next label anchors correctly.
            $at = $neighbour === null
                ? count($current)
                : array_search($neighbour, $current, true) + ($position === 'AFTER' ? 1 : 0);
            array_splice($current, $at, 0, [$label]);
        }

        return $additions;
    }

    private static function quoteLabel(string $label): string {
        return "'" . str_replace("'", "''", $label) . "'";
    }
}
