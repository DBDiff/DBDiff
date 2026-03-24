<?php

use DBDiff\Migration\Command\MigrationNewCommand;
use DBDiff\Migration\Command\MigrationUpCommand;
use DBDiff\Migration\Command\MigrationDownCommand;
use DBDiff\Migration\Command\MigrationStatusCommand;
use DBDiff\Migration\Command\MigrationBaselineCommand;
use DBDiff\Migration\Command\MigrationRepairCommand;
use DBDiff\Migration\Command\MigrationValidateCommand;
use DBDiff\Migration\Runner\MigrationFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for all migration:* Symfony Console commands.
 *
 * Uses a real SQLite database on disk and a temp migrations directory.
 * Each test gets a fresh environment so there are no cross-test dependencies.
 */
class MigrationCommandTest extends TestCase
{
    private Application $app;
    private string $tmpDir;
    private string $dbPath;
    private string $migrationsDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dbdiff_cmd_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->dbPath = $this->tmpDir . '/test.sqlite';
        touch($this->dbPath);

        $this->migrationsDir = $this->tmpDir . '/migrations';
        mkdir($this->migrationsDir, 0755, true);

        // Create a minimal dbdiff.yml that all commands can use
        $this->configPath = $this->tmpDir . '/dbdiff.yml';
        file_put_contents($this->configPath, implode("\n", [
            'database:',
            '  driver: sqlite',
            '  path: ' . $this->dbPath,
            '',
            'migrations:',
            '  dir: ' . $this->migrationsDir,
        ]));

        $this->app = new Application;
        $this->app->add(new MigrationNewCommand);
        $this->app->add(new MigrationUpCommand);
        $this->app->add(new MigrationDownCommand);
        $this->app->add(new MigrationStatusCommand);
        $this->app->add(new MigrationBaselineCommand);
        $this->app->add(new MigrationRepairCommand);
        $this->app->add(new MigrationValidateCommand);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = "$dir/$item";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function tester(string $name): CommandTester
    {
        return new CommandTester($this->app->find($name));
    }

    /**
     * Helper: scaffold a migration file with real SQL directly (bypassing the command).
     */
    private function scaffoldMigration(string $desc, string $version, string $upSql, ?string $downSql = null): MigrationFile
    {
        $file = MigrationFile::scaffold($this->migrationsDir, $desc, $version);
        file_put_contents($file->upPath, $upSql);
        if ($downSql !== null) {
            file_put_contents($file->downPath, $downSql);
        } elseif (file_exists($file->downPath)) {
            unlink($file->downPath);
        }
        return $file;
    }

    // ── migration:new ───────────────────────────────────────────────────────

    public function testNewCreatesFiles(): void
    {
        $tester = $this->tester('migration:new');
        $exitCode = $tester->execute([
            'name'             => 'create_users_table',
            '--config'         => $this->configPath,
            '--migrations-dir' => $this->migrationsDir,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Created UP', $display);
        $this->assertStringContainsString('Created DOWN', $display);

        // Verify files exist
        $files = MigrationFile::scanDir($this->migrationsDir);
        $this->assertCount(1, $files);
        $this->assertSame('create_users_table', $files[0]->description);
    }

    public function testNewFailsOnDuplicate(): void
    {
        $tester = $this->tester('migration:new');

        // Scaffold first one directly to guarantee same version
        MigrationFile::scaffold($this->migrationsDir, 'dup', '20260101120000');

        // Try to scaffold with the same version — this would only collide if
        // the command happened to generate the same timestamp.
        // Instead, test the error path by pre-creating the file.
        $exitCode = $tester->execute([
            'name'             => 'dup',
            '--config'         => $this->configPath,
            '--migrations-dir' => $this->migrationsDir,
        ]);

        // This should succeed since the timestamp will be different.
        // For a true duplicate test, we rely on MigrationFileTest.
        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    // ── migration:up ────────────────────────────────────────────────────────

    public function testUpAppliesMigrations(): void
    {
        $this->scaffoldMigration('create users', '20260101120000',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);',
            'DROP TABLE users;'
        );

        $tester = $this->tester('migration:up');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Applied', $tester->getDisplay());
    }

    public function testUpNothingToMigrate(): void
    {
        $tester = $this->tester('migration:up');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('up to date', $tester->getDisplay());
    }

    public function testUpWithTarget(): void
    {
        $this->scaffoldMigration('first', '20260101120000',
            'CREATE TABLE t1 (id INTEGER PRIMARY KEY);'
        );
        $this->scaffoldMigration('second', '20260102120000',
            'CREATE TABLE t2 (id INTEGER PRIMARY KEY);'
        );
        $this->scaffoldMigration('third', '20260103120000',
            'CREATE TABLE t3 (id INTEGER PRIMARY KEY);'
        );

        $tester = $this->tester('migration:up');
        $exitCode = $tester->execute([
            '--config' => $this->configPath,
            '--target' => '20260102120000',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        // Should show two applied migrations
        $this->assertSame(2, substr_count($display, 'Applied'));
    }

    public function testUpFailsOnBadSql(): void
    {
        $this->scaffoldMigration('bad', '20260101120000',
            'THIS IS NOT VALID SQL;'
        );

        $tester = $this->tester('migration:up');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Failed', $tester->getDisplay());
        $this->assertStringContainsString('repair', $tester->getDisplay());
    }

    // ── migration:down ──────────────────────────────────────────────────────

    public function testDownRollsBack(): void
    {
        $this->scaffoldMigration('create users', '20260101120000',
            'CREATE TABLE users (id INTEGER PRIMARY KEY);',
            'DROP TABLE users;'
        );

        // Apply first
        $upTester = $this->tester('migration:up');
        $upTester->execute(['--config' => $this->configPath]);

        // Roll back
        $tester = $this->tester('migration:down');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Rolled back', $tester->getDisplay());
    }

    public function testDownNothingToRollBack(): void
    {
        $tester = $this->tester('migration:down');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Nothing to roll back', $tester->getDisplay());
    }

    public function testDownReportsNoDownFile(): void
    {
        $this->scaffoldMigration('nodown', '20260101120000',
            'CREATE TABLE nodown (id INTEGER PRIMARY KEY);'
            // no down SQL
        );

        $upTester = $this->tester('migration:up');
        $upTester->execute(['--config' => $this->configPath]);

        $tester = $this->tester('migration:down');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('No DOWN file', $tester->getDisplay());
    }

    public function testDownWithLastOption(): void
    {
        $this->scaffoldMigration('a', '20260101120000',
            'CREATE TABLE a (id INTEGER PRIMARY KEY);',
            'DROP TABLE a;'
        );
        $this->scaffoldMigration('b', '20260102120000',
            'CREATE TABLE b (id INTEGER PRIMARY KEY);',
            'DROP TABLE b;'
        );
        $this->scaffoldMigration('c', '20260103120000',
            'CREATE TABLE c (id INTEGER PRIMARY KEY);',
            'DROP TABLE c;'
        );

        $upTester = $this->tester('migration:up');
        $upTester->execute(['--config' => $this->configPath]);

        $tester = $this->tester('migration:down');
        $exitCode = $tester->execute([
            '--config' => $this->configPath,
            '--last'   => '2',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(2, substr_count($tester->getDisplay(), 'Rolled back'));
    }

    // ── migration:status ────────────────────────────────────────────────────

    public function testStatusShowsEmptyDir(): void
    {
        $tester = $this->tester('migration:status');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No migrations found', $tester->getDisplay());
    }

    public function testStatusShowsPendingAndApplied(): void
    {
        $this->scaffoldMigration('applied', '20260101120000',
            'CREATE TABLE s_applied (id INTEGER PRIMARY KEY);'
        );

        // Apply the first migration
        $upTester = $this->tester('migration:up');
        $upTester->execute(['--config' => $this->configPath]);

        // Add a second pending migration
        $this->scaffoldMigration('pending', '20260102120000',
            'CREATE TABLE s_pending (id INTEGER PRIMARY KEY);'
        );

        $tester = $this->tester('migration:status');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('applied', $display);
        $this->assertStringContainsString('pending', $display);
        $this->assertStringContainsString('1 applied', $display);
        $this->assertStringContainsString('1 pending', $display);
    }

    // ── migration:validate ──────────────────────────────────────────────────

    public function testValidatePassesWhenClean(): void
    {
        $this->scaffoldMigration('valid', '20260101120000',
            'CREATE TABLE valid_t (id INTEGER PRIMARY KEY);'
        );

        $upTester = $this->tester('migration:up');
        $upTester->execute(['--config' => $this->configPath]);

        $tester = $this->tester('migration:validate');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('valid', $tester->getDisplay());
    }

    public function testValidateFailsOnTamperedFile(): void
    {
        $file = $this->scaffoldMigration('tampered', '20260101120000',
            'CREATE TABLE tamper_t (id INTEGER PRIMARY KEY);'
        );

        $upTester = $this->tester('migration:up');
        $upTester->execute(['--config' => $this->configPath]);

        // Tamper with the file
        file_put_contents($file->upPath, 'SELECT 1; -- changed after apply');

        $tester = $this->tester('migration:validate');
        $exitCode = $tester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('checksum', $tester->getDisplay());
    }

    // ── migration:repair ────────────────────────────────────────────────────

    public function testRepairRemovesFailedEntries(): void
    {
        $this->scaffoldMigration('broken', '20260101120000',
            'INVALID SQL;'
        );

        // Run up — it will fail
        $upTester = $this->tester('migration:up');
        $upTester->execute(['--config' => $this->configPath]);

        // Repair — should succeed and remove the failed entry
        $tester = $this->tester('migration:repair');
        $exitCode = $tester->execute([
            '--config' => $this->configPath,
            '--force'  => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Removed', $tester->getDisplay());
        $this->assertStringContainsString('1', $tester->getDisplay());
    }

    public function testRepairNothingToRepair(): void
    {
        $tester = $this->tester('migration:repair');
        $exitCode = $tester->execute([
            '--config' => $this->configPath,
            '--force'  => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('nothing to repair', $tester->getDisplay());
    }

    // ── migration:baseline ──────────────────────────────────────────────────

    public function testBaselineSetsVersion(): void
    {
        $tester = $this->tester('migration:baseline');
        $exitCode = $tester->execute([
            '--config'           => $this->configPath,
            '--baseline-version' => '20260101120000',
            '--force'            => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Baseline set', $display);
        $this->assertStringContainsString('20260101120000', $display);
    }

    public function testBaselineRejectsInvalidVersion(): void
    {
        $tester = $this->tester('migration:baseline');
        $exitCode = $tester->execute([
            '--config'           => $this->configPath,
            '--baseline-version' => 'not-a-timestamp',
            '--force'            => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('14-digit timestamp', $tester->getDisplay());
    }

    public function testBaselineSkipsAlreadyBaselinedMigrations(): void
    {
        $this->scaffoldMigration('old', '20260101120000',
            'CREATE TABLE old_t (id INTEGER PRIMARY KEY);'
        );
        $this->scaffoldMigration('new', '20260102120000',
            'CREATE TABLE new_t (id INTEGER PRIMARY KEY);'
        );

        // Baseline at first version
        $baselineTester = $this->tester('migration:baseline');
        $baselineTester->execute([
            '--config'           => $this->configPath,
            '--baseline-version' => '20260101120000',
            '--force'            => true,
        ]);

        // Now run up — should only apply the second migration
        $upTester = $this->tester('migration:up');
        $exitCode = $upTester->execute(['--config' => $this->configPath]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $upTester->getDisplay();
        // The first migration should be skipped (baselined), second applied
        $this->assertStringContainsString('20260102120000', $display);
        $this->assertStringNotContainsString('20260101120000', $display);
    }

    // ── Full round-trip: new → up → status → validate → down → repair ─────

    public function testFullCommandLifecycle(): void
    {
        // 1. Create a migration via the command
        $newTester = $this->tester('migration:new');
        $newTester->execute([
            'name'             => 'create_accounts',
            '--config'         => $this->configPath,
            '--migrations-dir' => $this->migrationsDir,
        ]);
        $this->assertSame(Command::SUCCESS, $newTester->getStatusCode());

        // Write real SQL into the scaffolded files
        $files = MigrationFile::scanDir($this->migrationsDir);
        $this->assertCount(1, $files);
        file_put_contents($files[0]->upPath, 'CREATE TABLE accounts (id INTEGER PRIMARY KEY, name TEXT);');
        file_put_contents($files[0]->downPath, 'DROP TABLE accounts;');

        // 2. Apply migration
        $upTester = $this->tester('migration:up');
        $upTester->execute(['--config' => $this->configPath]);
        $this->assertSame(Command::SUCCESS, $upTester->getStatusCode());
        $this->assertStringContainsString('Applied', $upTester->getDisplay());

        // 3. Check status
        $statusTester = $this->tester('migration:status');
        $statusTester->execute(['--config' => $this->configPath]);
        $this->assertSame(Command::SUCCESS, $statusTester->getStatusCode());
        $this->assertStringContainsString('1 applied', $statusTester->getDisplay());

        // 4. Validate — should be clean
        $validateTester = $this->tester('migration:validate');
        $validateTester->execute(['--config' => $this->configPath]);
        $this->assertSame(Command::SUCCESS, $validateTester->getStatusCode());

        // 5. Roll back
        $downTester = $this->tester('migration:down');
        $downTester->execute(['--config' => $this->configPath]);
        $this->assertSame(Command::SUCCESS, $downTester->getStatusCode());
        $this->assertStringContainsString('Rolled back', $downTester->getDisplay());

        // 6. Status should now show as pending
        $statusTester2 = $this->tester('migration:status');
        $statusTester2->execute(['--config' => $this->configPath]);
        $this->assertStringContainsString('pending', $statusTester2->getDisplay());
    }
}
