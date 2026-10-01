<?php

use DBDiff\Migration\Config\MigrationConfig;
use DBDiff\Migration\Runner\MigrationFile;
use DBDiff\Migration\Runner\MigrationRunner;

/**
 * DBDiff's own runner applies a migration in one transaction, where a label
 * added with `ALTER TYPE ... ADD VALUE` cannot be used before it commits
 * (`unsafe use of new value`). It commits label additions first, so a
 * migration that adds a label and uses it applies.
 */
class MigrationRunnerEnumPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_runner';

    public function testALabelAddedAndUsedInOneMigrationApplies(): void
    {
        $db  = $this->db('enum', "CREATE TYPE st AS ENUM ('new'); CREATE TABLE o (s st);");
        $dir = sys_get_temp_dir() . '/dbdiff_runner_enum_' . uniqid();
        mkdir($dir);
        try {
            $file = MigrationFile::scaffold($dir, 'add paid', '20261001000000');
            file_put_contents($file->upPath, "ALTER TYPE \"st\" ADD VALUE IF NOT EXISTS 'paid' AFTER 'new';\n"
                . "ALTER TABLE \"o\" ALTER COLUMN \"s\" SET DEFAULT 'paid';\n"
                . "INSERT INTO \"o\" DEFAULT VALUES;\n");

            $runner = new MigrationRunner(new MigrationConfig(null, [
                'driver' => 'pgsql', 'host' => $this->host, 'port' => $this->port, 'name' => $db,
                'user' => $this->user, 'password' => $this->pass, 'migrations_dir' => $dir,
            ]));
            $results = $runner->up();

            $this->assertSame('applied', $results[0]['status']);
            $this->assertSame([['s' => 'paid']], $this->rows($db, 'SELECT s::text FROM o'));
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }
}
