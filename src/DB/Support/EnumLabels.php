<?php namespace DBDiff\DB\Support;

/**
 * Enum labels as SQL writes them: read from a `CREATE TYPE ... AS ENUM (...)`
 * definition, quoted for one, and found in rendered expressions.
 */
final class EnumLabels {

    /**
     * The labels of a `CREATE TYPE ... AS ENUM (...)` definition, in order.
     *
     * Returns null when the definition is not that shape, so an unexpected
     * rendering falls back to replacement rather than producing nonsense.
     *
     * @return string[]|null
     */
    public static function of(string $definition): ?array {
        if (!preg_match('/\bAS\s+ENUM\s*\((.*)\)\s*;?\s*$/is', $definition, $m)) {
            return null;
        }

        $body   = $m[1];
        $labels = [];
        $i      = 0;
        $length = strlen($body);

        while ($i < $length) {
            if ($body[$i] === "'") {
                [$label, $i] = self::readQuoted($body, $i + 1);
                $labels[] = $label;
                continue;
            }
            // Only separators and whitespace belong between labels; anything
            // else means this is not a plain label list.
            if ($body[$i] !== ',' && trim($body[$i]) !== '') {
                return null;
            }
            $i++;
        }

        return $labels === [] ? null : $labels;
    }

    public static function quote(string $label): string {
        return "'" . str_replace("'", "''", $label) . "'";
    }

    /** `CREATE TYPE <name> AS ENUM (...)` for these labels; `$name` already quoted. */
    public static function createStatement(string $quotedName, array $labels): string {
        return "CREATE TYPE $quotedName AS ENUM ("
            . implode(', ', array_map([self::class, 'quote'], $labels)) . ');';
    }

    /**
     * Whether rendered SQL — a default, a constraint or index definition —
     * has a literal of enum `$type` holding one of `$labels`: `'c'::st`, or an
     * element of `'{a,c}'::st[]`. PostgreSQL renders every enum constant with
     * its cast, so these are all of them.
     */
    public static function mentioned(string $sql, string $type, array $labels): bool {
        if ($labels === []) {
            return false;
        }
        $name = '(?:"?public"?\.)?(?:"' . preg_quote(str_replace('"', '""', $type), '/') . '"|'
            . preg_quote($type, '/') . '(?![\w$]))';
        preg_match_all("/'((?:[^']|'')*)'::$name(\\[\\])?/", $sql, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $literal = str_replace("''", "'", $m[1]);
            $values  = !empty($m[2]) ? self::arrayElements($literal) : [$literal];
            if (array_intersect($values, $labels) !== []) {
                return true;
            }
        }
        return false;
    }

    /** The elements of an array literal: `{a,"b c"}` → ['a', 'b c']. */
    private static function arrayElements(string $literal): array {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^{},\s]+)/', $literal, $m, PREG_SET_ORDER);
        return array_map(
            fn($e) => isset($e[2]) && $e[2] !== '' ? $e[2] : stripcslashes($e[1]),
            $m
        );
    }

    /**
     * One quoted label, starting just past its opening quote.
     *
     * @return array{0: string, 1: int} the label, and where the scan resumes
     */
    private static function readQuoted(string $body, int $i): array {
        $length = strlen($body);
        $label  = '';

        while ($i < $length) {
            if ($body[$i] !== "'") {
                $label .= $body[$i];
                $i++;
                continue;
            }
            // A doubled quote is an escaped one, not the end.
            if (($body[$i + 1] ?? '') === "'") {
                $label .= "'";
                $i += 2;
                continue;
            }
            $i++;
            break;
        }

        return [$label, $i];
    }
}
