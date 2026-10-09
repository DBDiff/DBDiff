<?php

/**
 * A source reached as a role with limited privileges is read in full.
 *
 * Columns and keys came from information_schema, which shows only what the
 * connecting role holds a privilege on. A role that could see a table but not
 * read it — a natural choice for a "read-only" connection — had no columns, so
 * every column of the target looked extra and the migration was
 * `DROP COLUMN … CASCADE` for each. A role that could only SELECT saw no
 * primary key (table_constraints lists a table only for a privilege other than
 * SELECT), so the data diff matched rows without one.
 */
class RestrictedSourceRolePostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_rsr';

    private const SCHEMA = "CREATE TABLE parent (id int PRIMARY KEY, name text NOT NULL);
        CREATE TABLE child (id int PRIMARY KEY, parent_id int REFERENCES parent(id), note varchar(40) DEFAULT 'x',
                            amount numeric(10,2), made timestamptz(3), tally int GENERATED ALWAYS AS IDENTITY);";

    /** [database, user, password] the source is reached as, when set. */
    private ?array $sourceAs = null;

    protected function url(string $db): string
    {
        if ($this->sourceAs !== null && $this->sourceAs[0] === $db) {
            return "postgres://{$this->sourceAs[1]}:{$this->sourceAs[2]}@{$this->host}:{$this->port}/$db";
        }
        return parent::url($db);
    }

    /** A login role on `$db` with USAGE on public, and `$grant` on top. */
    private function role(string $db, string $name, string $grant = ''): void
    {
        $this->connect($db)->exec("DO \$\$ BEGIN CREATE ROLE $name LOGIN PASSWORD 'pw'; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;
            GRANT CONNECT ON DATABASE $db TO $name; GRANT USAGE ON SCHEMA public TO $name; $grant");
    }

    public function testARoleThatCannotReadTheTablesSeesTheirColumns(): void
    {
        $source = $this->db('s_none', self::SCHEMA);
        $target = $this->db('t_none', self::SCHEMA);
        $this->role($source, 'dbdiff_rsr_none');
        $this->sourceAs = [$source, 'dbdiff_rsr_none', 'pw'];

        $this->assertNull($this->diff($source, $target), 'identical schemas, read as a role without SELECT');
    }

    public function testARoleThatCanOnlySelectStillMatchesRowsByPrimaryKey(): void
    {
        $source = $this->db('s_sel', self::SCHEMA . "INSERT INTO parent VALUES (1, 'one'), (2, 'two renamed');");
        $target = $this->db('t_sel', self::SCHEMA . "INSERT INTO parent VALUES (1, 'one'), (2, 'two');");
        $this->role($source, 'dbdiff_rsr_select', 'GRANT SELECT ON ALL TABLES IN SCHEMA public TO dbdiff_rsr_select;');
        $this->sourceAs = [$source, 'dbdiff_rsr_select', 'pw'];

        $this->assertNull($this->diff($source, $target), 'identical schemas, read as a SELECT-only role');

        [$up] = $this->diff($source, $target, 'data');
        $this->assertMatchesRegularExpression('/UPDATE\s+"?parent"?\s+SET .*two renamed.*WHERE\s+"?id"?\s*=\s*\'?2/si', $up, "UP:\n$up");
        $this->connect($target)->exec($up);
        $this->assertSame([[1, 'one'], [2, 'two renamed']],
            $this->connect($target)->query('SELECT id, name FROM parent ORDER BY id')->fetchAll(PDO::FETCH_NUM));
    }
}
