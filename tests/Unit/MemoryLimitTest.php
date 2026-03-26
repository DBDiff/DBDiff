<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bug #10 – memory_limit must NOT be hardcoded in source.
 *
 * DBDiff previously called ini_set('memory_limit', '512M') unconditionally,
 * overriding both php.ini and any CLI -d flag.  After the fix, the source
 * file should contain zero calls to ini_set('memory_limit', …).
 */
class MemoryLimitTest extends TestCase
{
    public function testDbDiffPhpDoesNotSetMemoryLimit(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/DBDiff.php');
        $this->assertNotFalse($source);

        // Ensure no ini_set('memory_limit', ...) anywhere in the file
        $this->assertDoesNotMatchRegularExpression(
            '/ini_set\s*\(\s*[\'"]memory_limit[\'"]\s*,/',
            $source,
            'DBDiff.php must not hardcode memory_limit via ini_set()'
        );
    }

    public function testMemoryLimitUnchangedAfterRequiringDbDiff(): void
    {
        // Set a known memory_limit before loading DBDiff
        $original = ini_get('memory_limit');

        // Simply requiring/autoloading the class should not change the limit
        // (class is already autoloaded by PHPUnit, so this is a sanity check)
        $this->assertTrue(class_exists(\DBDiff\DBDiff::class));

        $this->assertSame(
            $original,
            ini_get('memory_limit'),
            'Loading/autoloading DBDiff must not change memory_limit'
        );
    }
}
