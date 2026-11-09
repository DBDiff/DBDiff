<?php

use DBDiff\Migration\Config\MigrationConfig;
use DBDiff\Migration\Runner\MigrationFile;
use DBDiff\Migration\Runner\MigrationRunner;

/**
 * `migration:up` sends a file one statement at a time. A function body, a DO
 * block or a string literal holding a semicolon is still one statement, and
 * splitting it on every `;` sent PostgreSQL fragments it could not parse.
 */
class MigrationRunnerBodiesPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_runner_bodies';

    public function testBodiesAndLiteralsWithSemicolonsApplyWhole(): void
    {
        $db  = $this->db('bodies', 'CREATE TABLE notes (id int, body text);');
        $dir = sys_get_temp_dir() . '/dbdiff_runner_bodies_' . uniqid();
        mkdir($dir);
        try {
            $file = MigrationFile::scaffold($dir, 'bodies', '20261101000000');
            file_put_contents($file->upPath, <<<'SQL'
                CREATE FUNCTION stamp() RETURNS trigger LANGUAGE plpgsql AS $fn$
                BEGIN
                  NEW.body := NEW.body || ' (stamped; once)';
                  RETURN NEW;
                END;
                $fn$;
                CREATE TRIGGER notes_stamp BEFORE INSERT ON notes FOR EACH ROW EXECUTE FUNCTION stamp();
                CREATE FUNCTION add(a int, b int) RETURNS int LANGUAGE sql
                BEGIN ATOMIC
                  SELECT CASE WHEN a > 0 THEN a + b ELSE b END;
                END;
                DO $$ BEGIN INSERT INTO notes VALUES (add(1, 1), 'from do; block'); END $$;
                INSERT INTO notes VALUES (3, E'escaped \'; quote'), (4, 'plain; text');
                SQL);

            $results = (new MigrationRunner(new MigrationConfig(null, [
                'driver' => 'pgsql', 'host' => $this->host, 'port' => $this->port, 'name' => $db,
                'user' => $this->user, 'password' => $this->pass, 'migrations_dir' => $dir,
            ])))->up();

            $this->assertSame('applied', $results[0]['status'], (string) ($results[0]['error'] ?? ''));
            $this->assertSame([
                ['id' => 2, 'body' => 'from do; block (stamped; once)'],
                ['id' => 3, 'body' => "escaped '; quote (stamped; once)"],
                ['id' => 4, 'body' => 'plain; text (stamped; once)'],
            ], $this->rows($db, 'SELECT id, body FROM notes ORDER BY id'));
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }
}
