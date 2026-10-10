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
        $words = [];
        $word = null;
        $length = strlen($options);
        for ($i = 0; $i < $length; $i++) {
            $ch = $options[$i];
            if ($ch === '\\' && $i + 1 < $length) {
                $word = ($word ?? '') . $options[++$i];
            } elseif (ctype_space($ch)) {
                if ($word !== null) {
                    $words[] = $word;
                    $word = null;
                }
            } else {
                $word = ($word ?? '') . $ch;
            }
        }
        if ($word !== null) {
            $words[] = $word;
        }

        $settings = [];
        for ($i = 0; $i < count($words); $i++) {
            $w = $words[$i];
            if ($w === '-c' && isset($words[$i + 1])) {
                $w = $words[++$i];
            } elseif (str_starts_with($w, '-c')) {
                $w = substr($w, 2);
            } elseif (str_starts_with($w, '--')) {
                $w = substr($w, 2);
            } else {
                continue;
            }
            $eq = strpos($w, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            // libpq accepts dashes for underscores in a setting's name.
            $settings[] = [str_replace('-', '_', substr($w, 0, $eq)), substr($w, $eq + 1)];
        }
        return $settings;
    }

    /** Apply the connection's `session_options` to its session. */
    public static function apply(Connection $connection): void
    {
        $options = $connection->getConfig('session_options');
        if (!is_string($options) || $options === '') {
            return;
        }
        foreach (self::parse($options) as [$name, $value]) {
            $connection->select('SELECT set_config(?, ?, false)', [$name, $value]);
        }
    }
}
