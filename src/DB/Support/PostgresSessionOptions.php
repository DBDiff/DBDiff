<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * The `options` of a PostgreSQL connection URL, applied to DBDiff's sessions.
 *
 * `?options=-c statement_timeout=5s -c default_transaction_read_only=on` is
 * how a connection string asks for session settings, and psql, pg_dump and
 * libpq clients honour it. Laravel's connector builds its own DSN and has no
 * place for it, so DBDiff dropped it: a source URL made read-only that way was
 * an ordinary session for DBDiff while every other tool given the same URL
 * respected it.
 *
 * Each `-c name=value` (or `--name=value`) is applied with set_config() as
 * soon as the connection is made, the values bound rather than interpolated.
 */
final class PostgresSessionOptions
{
    /**
     * The settings in a libpq `options` string, in order.
     *
     * Words are separated by whitespace, and a backslash makes the next
     * character literal, so `\ ` is a space inside a value — libpq's rules.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function parse(string $options): array
    {
        $words = self::words($options);
        $settings = [];
        while (($word = array_shift($words)) !== null) {
            // `-c name=value` as two words, or `-cname=value` / `--name=value` as one.
            $assignment = $word === '-c' ? (array_shift($words) ?? '') : self::withoutSwitch($word);
            $setting = $assignment === null ? null : self::setting($assignment);
            if ($setting !== null) {
                $settings[] = $setting;
            }
        }
        return $settings;
    }

    /** @return array<int, string> the words, with backslash escapes resolved */
    private static function words(string $options): array
    {
        preg_match_all('/(?:\\\\.|[^\s\\\\])+/s', $options, $m);
        return array_map(fn(string $w) => preg_replace('/\\\\(.)/s', '$1', $w), $m[0]);
    }

    /** `name=value` from `-cname=value` or `--name=value`; null for any other word. */
    private static function withoutSwitch(string $word): ?string
    {
        if (str_starts_with($word, '--')) {
            return substr($word, 2);
        }
        return str_starts_with($word, '-c') ? substr($word, 2) : null;
    }

    /** [name, value], or null when it is not an assignment. */
    private static function setting(string $assignment): ?array
    {
        $eq = strpos($assignment, '=');
        if ($eq === false || $eq === 0) {
            return null;
        }
        // libpq accepts dashes for underscores in a setting's name.
        return [str_replace('-', '_', substr($assignment, 0, $eq)), substr($assignment, $eq + 1)];
    }

    /** Apply a PostgreSQL connection's `session_options` to its session. */
    public static function apply(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }
        $options = $connection->getConfig('session_options');
        if (!is_string($options) || $options === '') {
            return;
        }
        foreach (self::parse($options) as [$name, $value]) {
            $connection->select('SELECT set_config(?, ?, false)', [$name, $value]);
        }
    }
}
