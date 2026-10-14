<?php

use DBDiff\Exceptions\CLIException;
use DBDiff\Migration\Command\ErrorReport;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whatever stops a command ends as a short message and exit 1.
 */
class ErrorReportTest extends TestCase
{
    private function queryException(string $connection): QueryException
    {
        $pdo = new PDOException('SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to statement timeout');
        return new QueryException($connection, "SELECT c.relname\n  FROM pg_class c\n WHERE true", [], $pdo);
    }

    public function testADatabaseErrorNamesTheServerAndLeavesOutTheQuery(): void
    {
        $message = ErrorReport::message($this->queryException('target'));

        $this->assertSame(
            'SQLSTATE[57014]: Query canceled: 7 ERROR: canceling statement due to statement timeout (on the target (--server2))',
            $message
        );
    }

    public function testDBDiffsOwnErrorIsItsMessage(): void
    {
        $this->assertSame('No such table', ErrorReport::message(new CLIException('No such table')));
    }

    public function testAnythingElseIsCalledUnexpected(): void
    {
        $message = ErrorReport::message(new TypeError('array_keys(): Argument #1 ($array) must be of type array, null given'));

        $this->assertStringStartsWith('Unexpected error (TypeError): array_keys()', $message);
        $this->assertStringContainsString('-v', $message);
    }

    public function testVerboseAddsTheQueryAndTheTrace(): void
    {
        $quiet = new BufferedOutput();
        $this->assertSame(1, ErrorReport::write($quiet, $this->queryException('source')));
        $this->assertStringNotContainsString('pg_class', $quiet->fetch());

        $verbose = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        ErrorReport::write($verbose, $this->queryException('source'));
        $text = $verbose->fetch();
        $this->assertStringContainsString('(on the source (--server1))', $text);
        $this->assertStringContainsString('SQL: SELECT c.relname', $text);
        $this->assertStringContainsString('#0 ', $text);
    }

    public function testConsoleMarkupInAMessageIsPrintedAsIs(): void
    {
        $output = new BufferedOutput();
        ErrorReport::write($output, new CLIException('column <info> missing'));

        $this->assertStringContainsString('column <info> missing', $output->fetch());
    }
}
