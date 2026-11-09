<?php namespace DBDiff\Migration\Runner;

/**
 * Splits a migration file into the statements a server will accept one at a
 * time — the job a database's own client does before it sends anything.
 *
 * A semicolon ends a statement only where the server would read it as the end
 * of one. So it is skipped inside:
 *
 *   - string literals ('…', with '' and, on MySQL or in a PostgreSQL E'…'
 *     string, backslash escapes) and quoted identifiers ("…", `…`);
 *   - comments: -- to the end of the line, # on MySQL, and /* … *\/, which
 *     PostgreSQL lets nest;
 *   - PostgreSQL dollar-quoted bodies ($$ … $$, $fn$ … $fn$);
 *   - the BEGIN … END body of a routine, trigger or event — MySQL and MariaDB
 *     routines, SQLite triggers, and PostgreSQL's BEGIN ATOMIC.
 *
 * A body's END is told from the END IF / END LOOP / END WHILE / END REPEAT of
 * a statement inside it by the word that follows; a CASE, which ends in a bare
 * END or END CASE, opens a level of its own so its END closes it.
 *
 * A line `DELIMITER <token>`, as the mysql client reads it, changes what ends
 * a statement until the next DELIMITER line; the line itself is never sent.
 * That lets a file written for the mysql client run here unchanged.
 */
final class SqlStatementSplitter
{
    private const ROUTINE = '/^\s*CREATE\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?'
        . '(?:(?:TEMP|TEMPORARY)\s+)?(?:CONSTRAINT\s+)?(?:AGGREGATE\s+)?(?:PROCEDURE|FUNCTION|TRIGGER|EVENT)\b/i';

    /** A compound statement's own END word: END IF, END LOOP, … — not a body's END. */
    private const INNER_END = ['IF', 'LOOP', 'WHILE', 'REPEAT'];

    /** @return string[] statements, trimmed, without their delimiter */
    public static function split(string $sql, string $driver = 'mysql'): array
    {
        $mysql = $driver === 'mysql';
        $pgsql = $driver === 'pgsql';
        $len = strlen($sql);
        $statements = [];
        $buffer = '';
        $delimiter = ';';
        $depth = 0;
        $routine = null; // whether the statement being read creates a routine; decided on first need
        $i = 0;

        $finish = function () use (&$statements, &$buffer, &$depth, &$routine): void {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
            $depth = 0;
            $routine = null;
        };

        while ($i < $len) {
            $c = $sql[$i];

            // A DELIMITER line, between statements (comments aside, which are
            // dropped with it), at the start of a line.
            if (($i === 0 || $sql[$i - 1] === "\n")
                && preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(?:\r?\n|$)/Ai', $sql, $m, 0, $i)
                && trim(self::withoutComments($buffer)) === '') {
                $delimiter = $m[1];
                $buffer = '';
                $i += strlen($m[0]);
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $escapes = $c !== '`' && ($mysql || ($pgsql && $c === "'" && self::isEString($sql, $i)));
                $end = self::quoteEnd($sql, $i, $c, $escapes);
                $buffer .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-' || $c === '#' && $mysql) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $len : $end;
                $buffer .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = self::blockCommentEnd($sql, $i, $pgsql);
                $buffer .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($c === '$' && $pgsql && !self::isWordChar($sql[$i - 1] ?? ' ')
                && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/A', $sql, $m, 0, $i)) {
                $close = strpos($sql, $m[0], $i + strlen($m[0]));
                $end = $close === false ? $len : $close + strlen($m[0]);
                $buffer .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($delimiter !== ';') {
                if (substr_compare($sql, $delimiter, $i, strlen($delimiter)) === 0) {
                    $finish();
                    $i += strlen($delimiter);
                    continue;
                }
                $buffer .= $c;
                $i++;
                continue;
            }

            if ((ctype_alpha($c) || $c === '_') && !self::isWordChar($sql[$i - 1] ?? ' ')) {
                $word = self::wordAt($sql, $i);
                $buffer .= $word;
                $i += strlen($word);
                $upper = strtoupper($word);
                if ($upper !== 'BEGIN' && $upper !== 'CASE' && $upper !== 'END') {
                    continue;
                }
                $routine ??= self::isRoutine($buffer);
                if (!$routine) {
                    continue;
                }
                if ($upper === 'END') {
                    [$next, $at] = self::nextWord($sql, $i);
                    if (in_array(strtoupper($next), self::INNER_END, true)) {
                        continue;
                    }
                    if (strtoupper($next) === 'CASE') { // END CASE closes the CASE; its word opens nothing
                        $buffer .= substr($sql, $i, $at + strlen($next) - $i);
                        $i = $at + strlen($next);
                    }
                    $depth = max(0, $depth - 1);
                } else {
                    $depth++;
                }
                continue;
            }

            if ($c === ';' && $depth === 0) {
                $finish();
                $i++;
                continue;
            }
            $buffer .= $c;
            $i++;
        }
        $finish();
        return $statements;
    }

    /** Whether a statement creates a procedure, function, trigger or event. */
    public static function isRoutine(string $statement): bool
    {
        return (bool) preg_match(self::ROUTINE, self::withoutComments($statement));
    }

    private static function quoteEnd(string $sql, int $start, string $quote, bool $escapes): int
    {
        $len = strlen($sql);
        for ($i = $start + 1; $i < $len; $i++) {
            if ($escapes && $sql[$i] === '\\') {
                $i++;
            } elseif ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') !== $quote) {
                    return $i + 1;
                }
                $i++;
            }
        }
        return $len;
    }

    private static function blockCommentEnd(string $sql, int $start, bool $nests): int
    {
        $len = strlen($sql);
        $level = 0;
        for ($i = $start; $i < $len - 1; $i++) {
            if ($sql[$i] === '/' && $sql[$i + 1] === '*' && ($nests || $level === 0)) {
                $level++;
                $i++;
            } elseif ($sql[$i] === '*' && $sql[$i + 1] === '/') {
                if (--$level === 0) {
                    return $i + 2;
                }
                $i++;
            }
        }
        return $len;
    }

    /** E'…' or e'…': PostgreSQL's string with backslash escapes. */
    private static function isEString(string $sql, int $quote): bool
    {
        return $quote > 0 && ($sql[$quote - 1] === 'E' || $sql[$quote - 1] === 'e')
            && !self::isWordChar($sql[$quote - 2] ?? ' ');
    }

    private static function isWordChar(string $c): bool
    {
        return ctype_alnum($c) || $c === '_' || $c === '$';
    }

    private static function wordAt(string $sql, int $i): string
    {
        preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/A', $sql, $m, 0, $i);
        return $m[0];
    }

    /** @return array{0: string, 1: int} the next word after $i, and where it starts */
    private static function nextWord(string $sql, int $i): array
    {
        if (preg_match('/\G\s*([A-Za-z_][A-Za-z0-9_$]*)/A', $sql, $m, PREG_OFFSET_CAPTURE, $i)) {
            return [$m[1][0], $m[1][1]];
        }
        return ['', $i];
    }

    private static function withoutComments(string $sql): string
    {
        return preg_replace(['~/\*.*?\*/~s', '/--[^\n]*/', '/^\s*#[^\n]*/m'], ' ', $sql);
    }
}
