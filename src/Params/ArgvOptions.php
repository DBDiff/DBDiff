<?php namespace DBDiff\Params;

/**
 * The long options and positional arguments in an argv array.
 *
 * Replaces the one thing aura/cli was used for. That package was a second
 * argv parser in a project whose CLI is built on symfony/console, its last
 * release was in 2017, it was required as `2.*@dev` — a constraint that
 * permits an unreleased branch — and on PHP 8 it emits a deprecation from
 * inside its own GetoptParser on any bare flag.
 *
 * symfony/console was the obvious candidate, but its ArgvInput is stricter than
 * what this path promises: it rejects unknown options, and gives null rather
 * than true for a value-optional flag. This path is reached by library callers
 * who may pass anything, so those differences are behaviour changes rather than
 * improvements. A parser of this size is cheaper than adapting around them, and
 * every quirk it keeps is pinned by CLIGetterTest.
 *
 * The semantics are aura's, deliberately:
 *
 *   --name=value   the value, verbatim, even if it looks like an option
 *   --name=        true, an empty value being indistinguishable from none
 *   --name         true; the following token is never consumed as a value
 *   --unknown      ignored
 *   repeated       the last occurrence wins
 *   --             skipped, being neither an option nor an argument
 *
 * `get()` takes an option name — with or without leading dashes — or an integer
 * position, where position 0 is the script name, matching the interface this
 * replaced so its call sites did not have to change.
 */
class ArgvOptions {

    /** @var array<string,string|true> */
    private $options = [];

    /** @var list<string> */
    private $positionals = [];

    /**
     * @param list<string> $argv     Raw argv, script name first.
     * @param list<string> $declared Option names to recognise, without dashes.
     */
    public static function fromArgv(array $argv, array $declared): self {
        $instance = new self;
        $known    = array_fill_keys($declared, true);

        foreach ($argv as $index => $token) {
            // argv[0] is the script name, and stays positional 0 so that an
            // integer lookup means what it always did.
            if ($index === 0 || !self::isOption($token)) {
                $instance->positionals[] = $token;
                continue;
            }

            if ($token === '--') {
                continue;
            }

            [$name, $value] = self::split($token);
            if (isset($known[$name])) {
                $instance->options[$name] = $value;
            }
        }

        return $instance;
    }

    /** A long option only: this path never declared short ones. */
    private static function isOption(string $token): bool {
        return str_starts_with($token, '--');
    }

    /**
     * `--name=value` into a name and a value, where an absent or empty value is
     * true.
     *
     * @return array{0:string,1:string|true}
     */
    private static function split(string $token): array {
        $body = substr($token, 2);
        $eq   = strpos($body, '=');

        if ($eq === false) {
            return [$body, true];
        }

        $value = substr($body, $eq + 1);
        return [substr($body, 0, $eq), $value === '' ? true : $value];
    }

    /**
     * An option's value, or a positional argument.
     *
     * Returns null for anything not given, so callers can keep testing the
     * result for truthiness as they always have.
     *
     * @param  string|int $key
     * @return string|bool|null
     */
    public function get($key) {
        if (is_int($key)) {
            return $this->positionals[$key] ?? null;
        }

        return $this->options[ltrim($key, '-')] ?? null;
    }
}
