<?php

namespace Tests\Unit;

use DBDiff\Exceptions\FSException;
use DBDiff\Migration\Command\DiffCommand;
use PHPUnit\Framework\TestCase;

/**
 * `--output` for the formats that name their own files (issue #224).
 *
 * A path naming a migration file is refused before anything is written; a
 * directory, including one whose name happens to contain a dot, is accepted
 * and created if missing.
 */
class OutputDirectoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dbdiff-output-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function resolve(?string $output, ?string $example = 'V20260101000000__migration.sql'): string
    {
        $method = new \ReflectionMethod(DiffCommand::class, 'resolveOutputDirectory');
        $method->setAccessible(true);
        return $method->invoke(null, $output, $example);
    }

    public function testAFilePathIsRefused(): void
    {
        $this->expectException(FSException::class);
        $this->expectExceptionMessage('must be a directory');
        $this->resolve($this->root . '/custom.sql');
    }

    public function testAPhpFilePathIsRefusedForEitherFormat(): void
    {
        $this->expectException(FSException::class);
        $this->resolve($this->root . '/custom.php', '2026_01_01_000000_migration.php');
    }

    public function testAnExistingFileIsRefused(): void
    {
        touch($this->root . '/taken');
        $this->expectException(FSException::class);
        $this->resolve($this->root . '/taken');
    }

    public function testADirectoryWithADotInItsNameIsAccepted(): void
    {
        $dir = $this->resolve($this->root . '/releases/2.0');
        $this->assertDirectoryExists($dir);
        $this->assertSame($this->root . '/releases/2.0', $dir);
    }

    public function testATrailingSlashIsDropped(): void
    {
        $this->assertSame($this->root . '/out', $this->resolve($this->root . '/out/'));
    }

    public function testNoOptionMeansTheWorkingDirectory(): void
    {
        $this->assertSame(getcwd(), $this->resolve(null));
    }
}
