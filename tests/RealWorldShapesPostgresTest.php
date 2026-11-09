<?php

/**
 * Shapes taken from real, widely used schemas — AdventureWorks, Pagila,
 * GitLab's structure.sql, any pgvector project — that the synthetic corpus
 * did not have, against a live server. See PostgresRoundTripTestCase.
 *
 * Each was wrong in a way the round trip alone could not see: an array that
 * lost its element's modifier, or a domain that resolved to a different
 * type, re-diffed as identical. So every case also compares the catalog's own
 * reading of the columns, domains and partitions.
 *
 * Each runs with pg_dump rendering new tables and with DBDiff's own renderer,
 * which is what runs wherever no pg_dump as new as the server is installed.
 */
class RealWorldShapesPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_rw';

    /** Partitions and types outside `public` are part of what is checked. */
    protected string $schemas = '*';

    /** @return array<string, array{string}> */
    public static function renderers(): array
    {
        return ['pg_dump' => ['on'], 'built-in' => ['off']];
    }

    protected function tearDown(): void
    {
        putenv('DBDIFF_PG_DUMP_RENDERER');
        parent::tearDown();
    }

    private function renderer(string $mode): void
    {
        putenv('DBDIFF_PG_DUMP_RENDERER=' . $mode);
    }

    /** What the catalog says, which the diff must not be the only judge of. */
    private function catalog(string $db): array
    {
        $pdo = $this->connect($db);
        $q = fn(string $sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
        return [
            'columns' => $q("SELECT n.nspname, c.relname, a.attname, format_type(a.atttypid, a.atttypmod)
                               FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
                               JOIN pg_namespace n ON n.oid = c.relnamespace
                              WHERE a.attnum > 0 AND NOT a.attisdropped AND c.relkind IN ('r', 'p')
                                AND n.nspname NOT IN ('pg_catalog', 'information_schema')
                              ORDER BY 1, 2, 3"),
            'domains' => $q("SELECT t.typname, con.conname, pg_get_constraintdef(con.oid), con.convalidated
                               FROM pg_constraint con JOIN pg_type t ON t.oid = con.contypid
                              ORDER BY 1, 2"),
            'generated' => $q("SELECT attrelid::regclass::text, attname, attgenerated::text FROM pg_attribute
                                 WHERE attgenerated <> '' ORDER BY 1, 2"),
            // Options and owner too: a sequence that kept its name but lost its
            // START, or its owner, reads the same by name alone.
            'sequences' => $q("SELECT c.relname, s.seqstart, s.seqmin,
                                      (SELECT d.refobjid::regclass::text || '.' || a.attname
                                         FROM pg_depend d
                                         JOIN pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
                                        WHERE d.objid = c.oid AND d.classid = 'pg_class'::regclass
                                          AND d.deptype IN ('a', 'i'))
                                 FROM pg_class c JOIN pg_sequence s ON s.seqrelid = c.oid ORDER BY 1"),
            'defaults' => $q("SELECT adrelid::regclass::text, pg_get_expr(adbin, adrelid)
                                FROM pg_attrdef ORDER BY 1, 2"),
            'partitions' => $q("SELECT i.inhrelid::regclass::text, i.inhparent::regclass::text
                                  FROM pg_inherits i ORDER BY 1"),
        ];
    }

    private function roundTrip(string $case, string $mode, string $source, string $target): string
    {
        $this->renderer($mode);
        return $this->assertRoundTrip("{$case}_$mode", $source, $target, fn(string $db) => $this->catalog($db));
    }

    /** AdventureWorks: `"Name"` written bare is PostgreSQL's own `name` type. */
    /** @dataProvider renderers */
    public function testMixedCaseDomainsOnANewTable(string $mode): void
    {
        $domains = 'CREATE DOMAIN "Name" AS varchar(50); CREATE DOMAIN "Flag" AS boolean NOT NULL;';
        $this->roundTrip('domains', $mode,
            $domains . 'CREATE TABLE store (id int PRIMARY KEY, n "Name", f "Flag" DEFAULT false, aliases "Name"[]);',
            $domains);
    }

    /** Arrays keep their element's modifier, and name mixed-case and non-public element types. */
    /** @dataProvider renderers */
    public function testArraysWithModifiers(string $mode): void
    {
        $types = 'CREATE SCHEMA app; CREATE TYPE app.status AS ENUM (\'x\'); CREATE TYPE "Mood" AS ENUM (\'a\');';
        $this->roundTrip('arrays', $mode,
            $types . 'CREATE TABLE t (id int PRIMARY KEY, a varchar(20)[], b numeric(10,2)[], c timestamptz(3)[],
                                      i bit(4)[], d "Mood"[], e app.status[]);',
            $types);
    }

    /** A column whose array lost its modifier is a difference, not identical. */
    public function testALostArrayModifierIsADifference(): void
    {
        $up = $this->assertRoundTrip('array_typmod',
            'CREATE TABLE t (id int PRIMARY KEY, a varchar(20)[], b numeric(10,2)[]);',
            'CREATE TABLE t (id int PRIMARY KEY, a varchar[], b numeric[]);',
            fn(string $db) => $this->catalog($db));
        $this->assertStringContainsString('character varying(20)[]', $up);
    }

    /** Pagila and GitLab: a partitioned table gaining a column gains it once, on the parent. */
    /** @dataProvider renderers */
    public function testAColumnAddedToAPartitionedTable(string $mode): void
    {
        $parts = "CREATE TABLE p (id int, at date NOT NULL) PARTITION BY RANGE (at);
                  CREATE TABLE p_1 PARTITION OF p FOR VALUES FROM ('2020-01-01') TO ('2021-01-01');
                  CREATE TABLE p_2 (id int, at date NOT NULL);
                  ALTER TABLE p ATTACH PARTITION p_2 FOR VALUES FROM ('2021-01-01') TO ('2022-01-01');";
        $this->roundTrip('part_add', $mode, $parts . "ALTER TABLE p ADD COLUMN note text DEFAULT 'x';", $parts);
    }

    /** GitLab keeps hash partitions of public tables in a schema of their own. */
    /** @dataProvider renderers */
    public function testAPartitionInAnotherSchemaThanItsParent(string $mode): void
    {
        $this->roundTrip('part_schema', $mode,
            'CREATE SCHEMA parts; CREATE TABLE ev (id bigint NOT NULL, k int) PARTITION BY HASH (id);
             CREATE TABLE parts.ev_0 PARTITION OF ev FOR VALUES WITH (modulus 2, remainder 0);
             CREATE TABLE parts.ev_1 PARTITION OF ev FOR VALUES WITH (modulus 2, remainder 1);',
            'CREATE SCHEMA parts;');
    }

    /**
     * GitLab's `uploads`: a partitioned table's id from a sequence it owns,
     * each attached partition carrying a copy of the default. One sequence
     * on the target, not the original plus a `uploads_id_seq1`.
     */
    /** @dataProvider renderers */
    public function testAPartitionedTablesOwnSequence(string $mode): void
    {
        $this->roundTrip('part_seq', $mode,
            "CREATE TABLE u (id bigint NOT NULL, k text NOT NULL) PARTITION BY LIST (k);
             CREATE SEQUENCE u_id_seq; ALTER SEQUENCE u_id_seq OWNED BY u.id;
             ALTER TABLE u ALTER id SET DEFAULT nextval('u_id_seq');
             CREATE TABLE u_a (id bigint DEFAULT nextval('u_id_seq') NOT NULL, k text NOT NULL);
             ALTER TABLE u ATTACH PARTITION u_a FOR VALUES IN ('a');
             CREATE TABLE u_b PARTITION OF u FOR VALUES IN ('b');",
            '');
    }

    /**
     * GitLab's `organizations`, verbatim: ids from 1000, the sequence owned by
     * the column. Written bigserial beside the sequence the sequence kind had
     * already created, the target counted from 1 on an `organizations_id_seq1`.
     */
    /** @dataProvider renderers */
    public function testAnOwnedSequenceWithOptionsOfItsOwn(string $mode): void
    {
        $this->roundTrip('seq_opts', $mode,
            "CREATE TABLE organizations (id bigint NOT NULL, name text DEFAULT ''::text NOT NULL);
             CREATE SEQUENCE organizations_id_seq
                 START WITH 1000
                 INCREMENT BY 1
                 MINVALUE 1000
                 NO MAXVALUE
                 CACHE 1;
             ALTER SEQUENCE organizations_id_seq OWNED BY organizations.id;
             ALTER TABLE ONLY organizations ALTER COLUMN id SET DEFAULT nextval('organizations_id_seq'::regclass);
             ALTER TABLE ONLY organizations ADD CONSTRAINT organizations_pkey PRIMARY KEY (id);",
            '');
    }

    /**
     * GitLab's `packages_packages_id_seq`, verbatim: owned by one table and
     * feeding another's default too.
     */
    /** @dataProvider renderers */
    public function testAnOwnedSequenceSharedByTwoTables(string $mode): void
    {
        $this->roundTrip('seq_shared', $mode,
            "CREATE TABLE packages_packages (id bigint NOT NULL, name character varying NOT NULL);
             CREATE SEQUENCE packages_packages_id_seq
                 START WITH 1
                 INCREMENT BY 1
                 NO MINVALUE
                 NO MAXVALUE
                 CACHE 1;
             ALTER SEQUENCE packages_packages_id_seq OWNED BY packages_packages.id;
             CREATE TABLE packages_composer_packages (
                 id bigint DEFAULT nextval('packages_packages_id_seq'::regclass) NOT NULL,
                 project_id bigint NOT NULL);
             ALTER TABLE ONLY packages_packages ALTER COLUMN id SET DEFAULT nextval('packages_packages_id_seq'::regclass);",
            '');
    }

    /**
     * GitLab's `p_ci_build_needs`, verbatim: a partitioned table renamed from
     * `ci_build_needs`, still owning `ci_build_needs_id_seq`. Written
     * bigserial, the target's sequence was named after the new table.
     */
    /** @dataProvider renderers */
    public function testAPartitionedTableOwningASequenceNamedForItsOldName(string $mode): void
    {
        $this->roundTrip('seq_renamed', $mode,
            "CREATE TABLE p_ci_build_needs (
                 name text NOT NULL,
                 artifacts boolean DEFAULT true NOT NULL,
                 optional boolean DEFAULT false NOT NULL,
                 build_id bigint NOT NULL,
                 partition_id bigint NOT NULL,
                 id bigint NOT NULL,
                 project_id bigint
             )
             PARTITION BY LIST (partition_id);
             CREATE SEQUENCE ci_build_needs_id_seq
                 START WITH 1
                 INCREMENT BY 1
                 NO MINVALUE
                 NO MAXVALUE
                 CACHE 1;
             ALTER SEQUENCE ci_build_needs_id_seq OWNED BY p_ci_build_needs.id;
             ALTER TABLE ONLY p_ci_build_needs ALTER COLUMN id SET DEFAULT nextval('ci_build_needs_id_seq'::regclass);
             CREATE TABLE ci_build_needs (LIKE p_ci_build_needs INCLUDING DEFAULTS);
             ALTER TABLE p_ci_build_needs ATTACH PARTITION ci_build_needs FOR VALUES IN (100);",
            '');
    }

    /**
     * GitLab's `zoekt_tasks`, verbatim: the id comes from a trigger, so the
     * column has no default, but it still owns its sequence. Read as the
     * column's own serial, nothing created the sequence, and the trigger
     * failed on the first insert.
     */
    /** @dataProvider renderers */
    public function testAnOwnedSequenceATriggerDrawsOn(string $mode): void
    {
        $this->roundTrip('seq_trigger', $mode,
            "CREATE FUNCTION assign_zoekt_tasks_id_value() RETURNS trigger
                 LANGUAGE plpgsql
                 AS \$\$
             BEGIN
             IF NEW.\"id\" IS NOT NULL THEN
               RAISE WARNING 'Manually assigning ids is not allowed, the value will be ignored';
             END IF;
             NEW.\"id\" := nextval('zoekt_tasks_id_seq'::regclass);
             RETURN NEW;

             END
             \$\$;
             CREATE TABLE zoekt_tasks (
                 id bigint NOT NULL,
                 partition_id bigint DEFAULT 1 NOT NULL,
                 zoekt_node_id bigint NOT NULL,
                 zoekt_repository_id bigint NOT NULL,
                 project_identifier bigint NOT NULL,
                 perform_at timestamp with time zone DEFAULT now() NOT NULL,
                 created_at timestamp with time zone NOT NULL,
                 updated_at timestamp with time zone NOT NULL,
                 state smallint DEFAULT 0 NOT NULL,
                 task_type smallint NOT NULL,
                 retries_left smallint DEFAULT 5 NOT NULL,
                 claimed_until timestamp with time zone,
                 CONSTRAINT c_zoekt_tasks_on_retries_left CHECK (((retries_left > 0) OR ((retries_left = 0) AND (state = 255))))
             )
             PARTITION BY LIST (partition_id);
             CREATE SEQUENCE zoekt_tasks_id_seq
                 START WITH 1
                 INCREMENT BY 1
                 NO MINVALUE
                 NO MAXVALUE
                 CACHE 1;
             ALTER SEQUENCE zoekt_tasks_id_seq OWNED BY zoekt_tasks.id;
             ALTER TABLE ONLY zoekt_tasks
                 ADD CONSTRAINT zoekt_tasks_pkey PRIMARY KEY (id, partition_id);
             CREATE TRIGGER assign_zoekt_tasks_id_trigger BEFORE INSERT ON zoekt_tasks FOR EACH ROW EXECUTE FUNCTION assign_zoekt_tasks_id_value();",
            '');
    }

    /**
     * GitLab's `p_sent_notifications`, verbatim: the id's default draws on
     * `sent_notifications_id_seq`, while the column owns a sequence of its
     * own that nothing uses. Written bigserial, the target's default drew on
     * the owned one instead.
     */
    /** @dataProvider renderers */
    public function testADefaultDrawingOnASequenceItsColumnDoesNotOwn(string $mode): void
    {
        $this->roundTrip('seq_not_owned', $mode,
            "CREATE SEQUENCE sent_notifications_id_seq
                 START WITH 1
                 INCREMENT BY 1
                 NO MINVALUE
                 NO MAXVALUE
                 CACHE 1;
             CREATE TABLE p_sent_notifications (
                 id bigint DEFAULT nextval('sent_notifications_id_seq'::regclass) NOT NULL,
                 project_id bigint,
                 noteable_id bigint,
                 recipient_id bigint,
                 issue_email_participant_id bigint,
                 namespace_id bigint NOT NULL,
                 created_at timestamp with time zone NOT NULL,
                 partition integer DEFAULT 1 NOT NULL,
                 noteable_type text,
                 commit_id text,
                 reply_key text NOT NULL,
                 in_reply_to_discussion_id text,
                 CONSTRAINT check_18a72130c5 CHECK ((char_length(commit_id) <= 255)),
                 CONSTRAINT check_21ab645552 CHECK ((char_length(reply_key) <= 255)),
                 CONSTRAINT check_4c25a085c6 CHECK ((char_length(noteable_type) <= 255)),
                 CONSTRAINT check_7e7dc3845d CHECK ((char_length(in_reply_to_discussion_id) <= 255))
             )
             PARTITION BY LIST (partition);
             CREATE SEQUENCE p_sent_notifications_id_seq
                 START WITH 1
                 INCREMENT BY 1
                 NO MINVALUE
                 NO MAXVALUE
                 CACHE 1;
             ALTER SEQUENCE p_sent_notifications_id_seq OWNED BY p_sent_notifications.id;
             ALTER TABLE ONLY p_sent_notifications
                 ADD CONSTRAINT p_sent_notifications_pkey PRIMARY KEY (id, partition);",
            '');
    }

    /** Pagila on PostgreSQL 18: a virtual generated column stays virtual. */
    /** @dataProvider renderers */
    public function testAVirtualGeneratedColumn(string $mode): void
    {
        $this->requireVersion(180000);
        $this->roundTrip('virtual', $mode,
            'CREATE TABLE film (id int PRIMARY KEY, length smallint, hours numeric GENERATED ALWAYS AS (round(length / 60.0, 2)) VIRTUAL);',
            '');
    }

    /** Stored and virtual are different columns, so a change between them is a difference. */
    public function testStoredBecomingVirtualIsADifference(): void
    {
        $this->requireVersion(180000);
        $up = $this->assertRoundTrip('stored_virtual',
            'CREATE TABLE film (id int PRIMARY KEY, length smallint, hours numeric GENERATED ALWAYS AS (round(length / 60.0, 2)) VIRTUAL);',
            'CREATE TABLE film (id int PRIMARY KEY, length smallint, hours numeric GENERATED ALWAYS AS (round(length / 60.0, 2)) STORED);',
            fn(string $db) => $this->catalog($db));
        $this->assertStringContainsString('VIRTUAL', $up);
    }

    private function requireVersion(int $min): void
    {
        if ($this->serverVersion < $min) {
            $this->markTestSkipped('Needs PostgreSQL ' . intdiv($min, 10000) . ' or newer.');
        }
    }

    /** AdventureWorks: a domain in use gains a NOT VALID check, which only ALTER DOMAIN can add. */
    public function testADomainInUseGainsANotValidCheck(): void
    {
        $base = 'CREATE DOMAIN "Phone" AS varchar(25); CREATE TABLE person (id int PRIMARY KEY, phone "Phone");';
        $up = $this->assertRoundTrip('domain_check',
            $base . 'ALTER DOMAIN "Phone" ADD CONSTRAINT phone_len CHECK (length(VALUE) <= 40) NOT VALID;',
            $base, fn(string $db) => $this->catalog($db));
        $this->assertStringNotContainsString('DROP DOMAIN', $up);
    }

    /** A new domain with a NOT VALID check stays unvalidated. */
    public function testANewDomainWithANotValidCheck(): void
    {
        $this->assertRoundTrip('domain_new',
            'CREATE DOMAIN dd AS text CONSTRAINT dd_ok CHECK (VALUE <> \'\');
             ALTER DOMAIN dd ADD CONSTRAINT dd_len CHECK (length(VALUE) < 5) NOT VALID;',
            '', fn(string $db) => $this->catalog($db));
    }
}
