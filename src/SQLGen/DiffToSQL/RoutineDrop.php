<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/**
 * Builds the DROP statement that precedes a routine replacement.
 *
 * Shared by DropRoutineSQL and AlterRoutineSQL, which previously carried
 * identical private copies — so the argument-list handling below would
 * otherwise have had to be written twice.
 */
class RoutineDrop {

    /**
     * @param string $definition Routine DDL, used only to tell FUNCTION from PROCEDURE.
     * @param string $name       Bare name, or a Postgres signature like `fn(integer,text)`.
     */
    public static function build(string $definition, string $name, SQLDialectInterface $dialect): string {
        $type = preg_match('/\bPROCEDURE\b/i', $definition) ? 'PROCEDURE' : 'FUNCTION';
        [$bare, $args] = self::splitSignature($name);
        return "DROP $type IF EXISTS " . $dialect->quote($bare) . $args . ';';
    }

    /**
     * Split `fn(integer,text)` into the name and its argument list.
     *
     * Postgres keys routines by signature so overloads stay distinct (issue
     * #187), and an unqualified DROP is ambiguous once overloads exist:
     *
     *     ERROR: function name "cosine_distance" is not unique
     *
     * The argument list is appended outside the quoted identifier — quoting
     * the whole signature would produce `"fn(integer,text)"`, a single odd
     * identifier rather than a call signature.
     *
     * MySQL has no overloads and passes a bare name, which returns unchanged.
     *
     * @return array{string, string} [bare name, argument list including parens]
     */
    private static function splitSignature(string $name): array {
        $pos = strpos($name, '(');
        if ($pos === false) {
            return [$name, ''];
        }
        return [substr($name, 0, $pos), substr($name, $pos)];
    }
}
