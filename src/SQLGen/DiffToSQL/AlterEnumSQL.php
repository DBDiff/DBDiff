<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\DB\Support\EnumLabels;


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
 * Removing or reordering a label has no equivalent. There is no `DROP VALUE`;
 * the columns move to a new type with the new labels, which then takes the
 * old one's name — see EnumSwapSQL, and EnumSwapPlan for what it carries
 * (issue #237). Where something uses the type that a swap cannot carry — a
 * routine taking it, a domain over it — or there is no plan (a definition
 * that could not be read), the type is replaced as before, with a note saying
 * why it may not apply; PostgreSQL then refuses it naming what is in the way.
 *
 * Each direction is decided on its own: the UP may be additive while the DOWN
 * is not, which is exactly what reverting an added label looks like.
 */
class AlterEnumSQL extends AbstractRecreateSQL {

    protected function dropKeyword(): string {
        return 'TYPE';
    }

    public function getUp(): string {
        return $this->transition($this->obj->targetDefinition, $this->obj->sourceDefinition, 'up');
    }

    public function getDown(): string {
        return $this->transition($this->obj->sourceDefinition, $this->obj->targetDefinition, 'down');
    }

    /**
     * SQL that turns the enum described by `$from` into the one in `$to`.
     *
     * `ALTER TYPE ... ADD VALUE` when every existing label survives in the same
     * relative order; otherwise a swap to a new type, or failing that the type
     * is replaced, as before.
     */
    private function transition(string $from, string $to, string $direction): string {
        $additions = self::plannedAdditions($from, $to);
        if ($additions !== []) {
            return implode("\n", array_map(
                fn(array $addition) => $this->addValueStatement($addition),
                $additions
            ));
        }

        $swap = $this->obj->swaps[$direction] ?? null;
        if ($swap !== null && $swap['blockers'] === []) {
            return implode("\n", (new EnumSwapSQL($this->obj->name, EnumLabels::of($to), $swap, $this->dialect))->statements());
        }
        return $this->replace($to, $swap['blockers'] ?? []);
    }

    /**
     * Whether a statement is a label addition this writes — which a runner
     * applying a migration in one transaction has to commit first, before
     * anything uses the label (see MigrationRunner).
     */
    public static function isValueAddition(string $statement): bool {
        $code = trim(preg_replace('/--[^\n]*/', '', $statement));
        return (bool) preg_match('/^ALTER\s+TYPE\s+.+\s+ADD\s+VALUE\b/is', $code);
    }

    /** Whether `$to` only adds labels to `$from`, which ADD VALUE can do. */
    public static function isAddition(string $from, string $to): bool {
        return self::plannedAdditions($from, $to) !== [];
    }

    /**
     * The labels to add, or none when this is not an addition.
     *
     * Empty covers all three reasons to fall back: a definition that could not
     * be parsed, a label removed or reordered, and labels that already match —
     * whatever differs then is not something ADD VALUE can express.
     *
     * @return array<int, array{0: string, 1: ?string, 2: ?string}>
     */
    private static function plannedAdditions(string $from, string $to): array {
        $fromLabels = EnumLabels::of($from);
        $toLabels   = EnumLabels::of($to);

        if ($fromLabels === null || $toLabels === null) {
            return [];
        }

        return self::additions($fromLabels, $toLabels) ?? [];
    }

    /** One `ALTER TYPE ... ADD VALUE`, positioned if it has a neighbour. */
    private function addValueStatement(array $addition): string {
        [$label, $position, $neighbour] = $addition;

        $at = $position === null ? '' : " $position " . EnumLabels::quote($neighbour);

        return 'ALTER TYPE ' . $this->dialect->qualify($this->obj->name)
            . ' ADD VALUE IF NOT EXISTS ' . EnumLabels::quote($label) . $at . ';';
    }

    /**
     * Drop and recreate, with a note about why it may not apply — naming what
     * is in the way, when that is known.
     */
    private function replace(string $definition, array $blockers): string {
        $quoted = $this->dialect->qualify($this->obj->name);
        $note = $blockers === []
            ? "-- Removing or reordering enum labels needs the type replaced, which\n"
              . "-- PostgreSQL refuses while any column still uses it. Migrate the\n"
              . "-- dependent columns to a new type first, or apply this by hand.\n"
            : "-- Removing or reordering enum labels moves its users to a new type,\n"
              . "-- but these cannot be moved automatically:\n"
              . implode('', array_map(fn($b) => "--   $b\n", $blockers))
              . "-- Migrate them first, or apply this by hand.\n";

        return $note . 'DROP TYPE IF EXISTS ' . $quoted . ";\n" . $definition . ';';
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

            [$position, $neighbour] = self::anchorFor($to, $index, $current);
            $additions[] = [$label, $position, $neighbour];

            // Insert it where it now sits, so the next label anchors correctly.
            array_splice($current, self::insertionPoint($current, $position, $neighbour), 0, [$label]);
        }

        return $additions;
    }

    /**
     * Where a new label goes, relative to one that already exists.
     *
     * The label before it is preferred, so labels land in `$to`'s order; the
     * one after it is the fallback for a label added at the front. Neither
     * exists only when nothing does, and then the label is simply appended.
     *
     * @param string[] $to
     * @param string[] $current
     * @return array{0: ?string, 1: ?string}
     */
    private static function anchorFor(array $to, int $index, array $current): array {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (in_array($to[$i], $current, true)) {
                return ['AFTER', $to[$i]];
            }
        }

        $count = count($to);
        for ($i = $index + 1; $i < $count; $i++) {
            if (in_array($to[$i], $current, true)) {
                return ['BEFORE', $to[$i]];
            }
        }

        return [null, null];
    }

    /**
     * The index to splice a label into, given its anchor.
     *
     * `array_search` returning false is treated as "not found" rather than
     * being used in arithmetic, where it would silently mean position zero.
     *
     * @param string[] $current
     */
    private static function insertionPoint(array $current, ?string $position, ?string $neighbour): int {
        if ($neighbour === null) {
            return count($current);
        }

        $at = array_search($neighbour, $current, true);
        if ($at === false) {
            return count($current);
        }

        return $position === 'AFTER' ? $at + 1 : $at;
    }
}
