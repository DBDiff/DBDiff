<?php

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Objects a migration creates before what uses them, and drops after it, in
 * both directions (issue #238), against a live server. See
 * PostgresRoundTripTestCase for how each case is checked.
 *
 * Each source has something the target lacks, used by a table: the UP must
 * create it before the table needs it, and the DOWN must drop it only once
 * nothing uses it any more.
 */
class CreationOrderRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_order';

    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        $fn = 'CREATE FUNCTION f() RETURNS int LANGUAGE sql IMMUTABLE AS $$ SELECT 7 $$;';
        return [
            'enum column'          => ["CREATE TYPE e AS ENUM ('a'); CREATE TABLE t (id int, c e);", 'CREATE TABLE t (id int);'],
            'enum table'           => ["CREATE TYPE e AS ENUM ('a'); CREATE TABLE t (id int, c e);", ''],
            'domain column'        => ['CREATE DOMAIN d AS int CHECK (VALUE > 0); CREATE TABLE t (id int, c d);', 'CREATE TABLE t (id int);'],
            'domain over enum'     => ["CREATE TYPE e AS ENUM ('a'); CREATE DOMAIN d AS e; CREATE TABLE t (id int, c d);", 'CREATE TABLE t (id int);'],
            'sequence default'     => ["CREATE SEQUENCE s; CREATE TABLE t (id int DEFAULT nextval('s'));", 'CREATE TABLE t (id int);'],
            'function default'     => [$fn . 'CREATE TABLE t (id int DEFAULT f());', 'CREATE TABLE t (id int);'],
            'function check'       => [$fn . 'CREATE TABLE t (id int CHECK (id < f() * 100));', 'CREATE TABLE t (id int);'],
            'function index'       => [$fn . 'CREATE TABLE t (id int); CREATE INDEX t_f ON t ((id + f()));', 'CREATE TABLE t (id int);'],
            'function reads table' => ['CREATE TABLE t (id int); CREATE FUNCTION n() RETURNS bigint LANGUAGE sql AS $$ SELECT count(*) FROM t $$;', ''],
            'trigger function'     => ['CREATE TABLE t (id int); CREATE FUNCTION tf() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$;
                                        CREATE TRIGGER tr BEFORE INSERT ON t FOR EACH ROW EXECUTE FUNCTION tf();', 'CREATE TABLE t (id int);'],
        ];
    }

    #[DataProvider('cases')]
    public function testCreatedBeforeUseAndDroppedAfter(string $source, string $target): void
    {
        $this->assertRoundTrip('c' . substr(md5($source . $target), 0, 8), $source, $target);
    }
}
