<?php namespace DBDiff\Migration\Command;

use DBDiff\Exceptions\BaseException;
use DBDiff\Migration\Exceptions\ConfigException;
use DBDiff\Migration\Exceptions\MigrationException;
use Illuminate\Database\QueryException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * How a command reports what stopped it.
 *
 * Whatever is thrown — by DBDiff, by the database driver, by a library, or a
 * PHP error — ends as a short message and a non-zero exit, never a PHP stack
 * dump. A database error names the server it came from and leaves out the
 * catalog query it interrupted, which ran to dozens of lines; `-v` shows the
 * query and the trace. Anything DBDiff did not raise itself is called
 * unexpected, so it can be told apart from a mistake in the input.
 */
final class ErrorReport {

    /** The message, one paragraph, for a person to read. */
    public static function message(\Throwable $e): string {
        if ($e instanceof QueryException) {
            $cause = $e->getPrevious()?->getMessage() ?? $e->getMessage();
            $cause = self::withoutQuery($cause);
            $where = self::connectionLabel($e->connectionName ?? null);
            return $where !== null ? "$cause (on the $where)" : $cause;
        }
        if ($e instanceof \PDOException) {
            return self::withoutQuery($e->getMessage());
        }
        $message = trim($e->getMessage());
        if (self::isExpected($e)) {
            return $message !== '' ? $message : get_class($e);
        }
        return 'Unexpected error (' . self::shortClass($e) . ')'
            . ($message !== '' ? ": $message" : '')
            . ' — re-run with -v for details, and please report it if it persists';
    }

    /**
     * Write the report: the message, then with -v the query and the trace.
     *
     * @return int the exit code to return
     */
    public static function write(OutputInterface $output, \Throwable $e): int {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }
        $output->writeln('<error>' . self::escape(self::message($e)) . '</error>');
        if ($output->isVerbose()) {
            if ($e instanceof QueryException) {
                $output->writeln(self::escape('SQL: ' . $e->getSql()));
            }
            for ($at = $e; $at !== null; $at = $at->getPrevious()) {
                $output->writeln(self::escape(get_class($at) . ': ' . $at->getMessage()));
                $output->writeln(self::escape($at->getTraceAsString()));
            }
        }
        return 1;
    }

    /** DBDiff's own refusals, and input it cannot use: the message is the whole story. */
    private static function isExpected(\Throwable $e): bool {
        return $e instanceof BaseException
            || $e instanceof ConfigException
            || $e instanceof MigrationException
            || $e instanceof \InvalidArgumentException
            || $e instanceof \Symfony\Component\Console\Exception\ExceptionInterface;
    }

    /** Illuminate appends "(Connection: x, SQL: ...)" to the driver's message. */
    private static function withoutQuery(string $message): string {
        $message = preg_replace('/\s*\(Connection: [^,)]*, SQL: .*\)\s*$/s', '', $message) ?? $message;
        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }

    private static function connectionLabel(?string $name): ?string {
        return match ($name) {
            'source'    => 'source (--server1)',
            'target'    => 'target (--server2)',
            'migration' => 'migration database',
            null, ''    => null,
            default     => "$name connection",
        };
    }

    private static function shortClass(\Throwable $e): string {
        return (new \ReflectionClass($e))->getShortName();
    }

    /** Console tags in a message would otherwise be read as formatting. */
    private static function escape(string $text): string {
        return \Symfony\Component\Console\Formatter\OutputFormatter::escape($text);
    }
}
