<?php namespace DBDiff\Params;

/**
 * The 2.x command line: `dbdiff server1.db1:server2.db2 [options]`, without
 * the `diff` subcommand 3.x introduced, and `--nocomments=true`.
 */
final class LegacyInvocation
{
    /** `server.db:server.db`, the shape of a diff's input; also `server.path:server.path` for SQLite. */
    private const INPUT = '/^[^\s:.]+\.[^\s:]+:[^\s:.]+\.[^\s:]+$/';

    /**
     * The arguments with `diff` inserted when the first one that is not an
     * option is a diff input rather than a command.
     *
     * @param array<int, string> $argv
     * @param callable(string): bool $isCommand
     * @return array<int, string>
     */
    public static function withDiff(array $argv, callable $isCommand): array
    {
        // 2.x took `--nocomments=true`; 3.x's flag takes no value.
        $argv = array_values(array_filter(array_map(function (string $arg) {
            if (!preg_match('/^--nocomments=(.*)$/i', $arg, $m)) {
                return $arg;
            }
            return filter_var($m[1], FILTER_VALIDATE_BOOLEAN) ? '--nocomments' : null;
        }, $argv), fn($arg) => $arg !== null));

        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '-')) {
                continue;
            }
            if (!$isCommand($arg) && preg_match(self::INPUT, $arg)) {
                array_splice($argv, 1, 0, ['diff']);
            }
            break;
        }
        return $argv;
    }
}
