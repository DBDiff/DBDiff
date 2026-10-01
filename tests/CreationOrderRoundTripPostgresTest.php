<?php

/**
 * Objects a migration creates before what uses them, and drops after it, in
 * both directions (issue #238), against a live server. See
 * PostgresRoundTripTestCase for how each case is checked.
 *
 * Each source has something the target lacks, used by a table: the UP must
 * create it before the table needs it, and the DOWN must drop it only once
 * nothing uses it any more — and the same the other way round.
 */
class CreationOrderRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_order';

    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        $fn = 'CREATE FUNCTION f() RETURNS int LANGUAGE sql IMMUTABLE AS $$ SELECT 7 $$;';
        $fnArgs = 'CREATE FUNCTION addn(x integer, y text DEFAULT $$a$$) RETURNS integer LANGUAGE sql IMMUTABLE AS $$ SELECT x + 1 $$;';
        return [
            'enum column'          => ["CREATE TYPE e AS ENUM ('a'); CREATE TABLE t (id int, c e);", 'CREATE TABLE t (id int);'],
            'enum table'           => ["CREATE TYPE e AS ENUM ('a'); CREATE TABLE t (id int, c e);", ''],
            'domain column'        => ['CREATE DOMAIN d AS int CHECK (VALUE > 0); CREATE TABLE t (id int, c d);', 'CREATE TABLE t (id int);'],
            'domain over enum'     => ["CREATE TYPE e AS ENUM ('a'); CREATE DOMAIN d AS e; CREATE TABLE t (id int, c d);", 'CREATE TABLE t (id int);'],
            'sequence default'     => ["CREATE SEQUENCE s; CREATE TABLE t (id int DEFAULT nextval('s'));", 'CREATE TABLE t (id int);'],
            'function default'     => [$fn . 'CREATE TABLE t (id int DEFAULT f());', 'CREATE TABLE t (id int);'],
            'function check'       => [$fn . 'CREATE TABLE t (id int CHECK (id < f() * 100));', 'CREATE TABLE t (id int);'],
            'function index'       => [$fn . 'CREATE TABLE t (id int); CREATE INDEX t_f ON t ((id + f()));', 'CREATE TABLE t (id int);'],
            'function with args'   => [$fnArgs . 'CREATE TABLE t (id int DEFAULT addn(1), c int CHECK (c < addn(100)));', 'CREATE TABLE t (id int);'],
            'changed default'      => [$fnArgs . 'CREATE TABLE t (id int DEFAULT addn(5));', 'CREATE TABLE t (id int DEFAULT 0);'],
            'function reads table' => ['CREATE TABLE t (id int); CREATE FUNCTION n() RETURNS bigint LANGUAGE sql AS $$ SELECT count(*) FROM t $$;', ''],
            'trigger function'     => ['CREATE TABLE t (id int); CREATE FUNCTION tf() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$;
                                        CREATE TRIGGER tr BEFORE INSERT ON t FOR EACH ROW EXECUTE FUNCTION tf();', 'CREATE TABLE t (id int);'],
        ];
    }

    /** @dataProvider cases */
    public function testCreatedBeforeUseAndDroppedAfter(string $source, string $target): void
    {
        $this->assertRoundTrip('c' . substr(md5($source . $target), 0, 8), $source, $target);
    }

    /**
     * The other way round: the *target* has something the source lacks. The
     * UP drops it only once what uses it is gone, and the DOWN recreates it
     * before what uses it — types first, a routine a table calls before the
     * table, a routine reading a table after it.
     *
     * @return array<string, array{string}>
     */
    public static function removals(): array
    {
        return [
            'enum used everywhere'  => ["CREATE TYPE e AS ENUM ('a', 'b'); CREATE DOMAIN d AS e; CREATE TABLE t (id int, c e);
                                        CREATE FUNCTION f(x e) RETURNS int LANGUAGE sql AS \$\$ SELECT 1 \$\$;
                                        CREATE VIEW v AS SELECT id, c, 'a'::e AS k FROM t;"],
            'sequence and function' => ["CREATE SEQUENCE s; CREATE FUNCTION g() RETURNS int LANGUAGE sql AS \$\$ SELECT 1 \$\$;
                                        CREATE TABLE t (id int DEFAULT nextval('s'), x int DEFAULT g());"],
            'table typed by enum'   => ["CREATE TYPE e AS ENUM ('a'); CREATE TABLE t (id int); CREATE TABLE t2 (id int, c e);
                                        CREATE VIEW v2 AS SELECT * FROM t2;"],
            'function reads table'  => ['CREATE TABLE t (id int); CREATE TABLE t2 (id int);
                                        CREATE FUNCTION n() RETURNS bigint LANGUAGE sql AS $$ SELECT count(*) FROM t2 $$;'],
            'trigger on table'      => ['CREATE TABLE t (id int); CREATE TABLE t2 (id int);
                                        CREATE FUNCTION tf() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$;
                                        CREATE TRIGGER tr BEFORE INSERT ON t2 FOR EACH ROW EXECUTE FUNCTION tf();'],
            'composite over enum'   => ["CREATE TYPE e AS ENUM ('a'); CREATE TYPE c AS (x e, y int); CREATE TABLE t (id int, v c);"],
            'check and index call'  => ['CREATE FUNCTION lim() RETURNS int LANGUAGE sql IMMUTABLE AS $$ SELECT 10 $$;
                                        CREATE TABLE t (id int CHECK (id < lim())); CREATE INDEX t_l ON t ((id + lim()));'],
        ];
    }

    /** @dataProvider removals */
    public function testDroppedAfterUseAndRecreatedBefore(string $target): void
    {
        $this->assertRoundTrip('r' . substr(md5($target), 0, 8), 'CREATE TABLE t (id int);', $target);
    }
}
