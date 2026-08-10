<?php

declare(strict_types=1);

namespace Tests\Unit;

use DBDiff\DB\Adapters\BulkSchemaAdapterInterface;
use DBDiff\DB\Adapters\PostgresAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the batch schema assembly introduced for issue #165.
 *
 * getBulkTableSchema() fetches every changed table in a fixed number of
 * queries and then splits the flat result sets back out per table. The
 * assembly step is where batching can go wrong, so these tests focus on it —
 * especially cross-table isolation, which single-table fetching got for free.
 *
 * The assemble* helpers are private and take pre-fetched rows, so they are
 * exercised through reflection with no database connection, matching the
 * approach used by MySQLAdapterNormalizationTest.
 */
class PostgresBulkSchemaTest extends TestCase
{
    private PostgresAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new PostgresAdapter();
    }

    private function invoke(string $method, array $args)
    {
        $ref = new \ReflectionMethod(PostgresAdapter::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->adapter, ...$args);
    }

    /** Build an information_schema.columns row with sensible defaults. */
    private static function colRow(string $table, string $name, array $overrides = []): array
    {
        return array_merge([
            'table_name'               => $table,
            'column_name'              => $name,
            'data_type'                => 'integer',
            'character_maximum_length' => null,
            'is_nullable'              => 'YES',
            'column_default'           => null,
            'numeric_precision'        => null,
            'numeric_scale'            => null,
            'udt_name'                 => 'int4',
            'datetime_precision'       => null,
            'is_identity'              => 'NO',
            'identity_generation'      => null,
            'is_generated'             => 'NEVER',
            'generation_expression'    => null,
            'domain_name'              => null,
        ], $overrides);
    }

    /** Build a table_constraints row with sensible defaults. */
    private static function conRow(string $table, string $name, array $overrides = []): array
    {
        return array_merge([
            'table_name'         => $table,
            'constraint_name'    => $name,
            'constraint_type'    => 'UNIQUE',
            'is_deferrable'      => 'NO',
            'initially_deferred' => 'NO',
            'column_name'        => null,
            'ordinal_position'   => 1,
            'foreign_table'      => null,
            'foreign_column'     => null,
            'update_rule'        => null,
            'delete_rule'        => null,
            'match_option'       => 'NONE',
            'convalidated'       => true,
        ], $overrides);
    }

    // ── Interface wiring ──────────────────────────────────────────────────

    public function testPostgresAdapterDeclaresBulkCapability(): void
    {
        // DBSchema uses this instanceof check to decide whether to batch.
        $this->assertInstanceOf(BulkSchemaAdapterInterface::class, $this->adapter);
    }

    // ── assembleColumns ───────────────────────────────────────────────────

    public function testAssembleColumnsSplitsRowsAcrossTables(): void
    {
        $rows = [
            self::colRow('users', 'id'),
            self::colRow('users', 'email', ['data_type' => 'text']),
            self::colRow('orders', 'id'),
        ];

        $result = $this->invoke('assembleColumns', [$rows, [], []]);

        $this->assertSame(['users', 'orders'], array_keys($result));
        $this->assertSame(['id', 'email'], array_keys($result['users']));
        $this->assertSame(['id'], array_keys($result['orders']));
    }

    public function testAssembleColumnsKeepsSameColumnNameSeparatePerTable(): void
    {
        // Two tables sharing a column name must not overwrite each other —
        // the failure mode a flat, non-table-keyed result would produce.
        $rows = [
            self::colRow('users', 'name', ['data_type' => 'text']),
            self::colRow('orders', 'name', ['data_type' => 'character varying',
                                            'character_maximum_length' => 50]),
        ];

        $result = $this->invoke('assembleColumns', [$rows, [], []]);

        $this->assertSame('"name" text', $result['users']['name']);
        $this->assertSame('"name" varchar(50)', $result['orders']['name']);
    }

    public function testAssembleColumnsEmitsNotNullAndDefault(): void
    {
        $rows = [self::colRow('t', 'status', [
            'data_type'      => 'text',
            'is_nullable'    => 'NO',
            'column_default' => "'active'::text",
        ])];

        $result = $this->invoke('assembleColumns', [$rows, [], []]);
        $this->assertSame('"status" text NOT NULL DEFAULT \'active\'::text', $result['t']['status']);
    }

    public function testAssembleColumnsEmitsIdentity(): void
    {
        $rows = [self::colRow('t', 'id', [
            'is_nullable'         => 'NO',
            'is_identity'         => 'YES',
            'identity_generation' => 'ALWAYS',
            'column_default'      => 'nextval(...)',
        ])];

        $result = $this->invoke('assembleColumns', [$rows, [], []]);
        // Identity columns carry no DEFAULT clause even when one is reported.
        $this->assertSame('"id" integer NOT NULL GENERATED ALWAYS AS IDENTITY', $result['t']['id']);
    }

    public function testAssembleColumnsDefaultsIdentityGenerationWhenNull(): void
    {
        $rows = [self::colRow('t', 'id', [
            'is_identity'         => 'YES',
            'identity_generation' => null,
        ])];

        $result = $this->invoke('assembleColumns', [$rows, [], []]);
        $this->assertSame('"id" integer GENERATED BY DEFAULT AS IDENTITY', $result['t']['id']);
    }

    public function testAssembleColumnsEmitsStoredGeneratedColumn(): void
    {
        $rows = [self::colRow('t', 'total', [
            'data_type'             => 'numeric',
            'numeric_precision'     => 10,
            'numeric_scale'         => 2,
            'is_generated'          => 'ALWAYS',
            'generation_expression' => '(qty * price)',
        ])];

        $result = $this->invoke('assembleColumns', [$rows, [], []]);
        $this->assertSame(
            '"total" numeric(10,2) GENERATED ALWAYS AS ((qty * price)) STORED',
            $result['t']['total']
        );
    }

    public function testAssembleColumnsSuppressesNotNullEnforcedByDomain(): void
    {
        // The domain already carries NOT NULL, so repeating it inline would
        // make the source and target DDL disagree spuriously.
        $rows = [self::colRow('t', 'code', [
            'data_type'   => 'text',
            'domain_name' => 'nonempty_text',
            'is_nullable' => 'NO',
        ])];

        $result = $this->invoke('assembleColumns', [$rows, ['nonempty_text' => true], []]);
        $this->assertSame('"code" nonempty_text', $result['t']['code']);
    }

    public function testAssembleColumnsKeepsNotNullWhenDomainIsNullable(): void
    {
        $rows = [self::colRow('t', 'code', [
            'data_type'   => 'text',
            'domain_name' => 'loose_text',
            'is_nullable' => 'NO',
        ])];

        $result = $this->invoke('assembleColumns', [$rows, ['loose_text' => false], []]);
        $this->assertSame('"code" loose_text NOT NULL', $result['t']['code']);
    }

    public function testAssembleColumnsSuppressesNotNullCarriedByNamedConstraint(): void
    {
        // PG18+ named NOT NULL constraints are emitted as constraints instead,
        // so the inline NOT NULL must be dropped to avoid a duplicate.
        $rows    = [self::colRow('t', 'email', ['data_type' => 'text', 'is_nullable' => 'NO'])];
        $nnCols  = ['t' => ['email' => true]];

        $result = $this->invoke('assembleColumns', [$rows, [], $nnCols]);
        $this->assertSame('"email" text', $result['t']['email']);
    }

    public function testAssembleColumnsNamedNotNullSuppressionIsPerTable(): void
    {
        // A named NOT NULL on users.email must not suppress orders.email.
        $rows = [
            self::colRow('users',  'email', ['data_type' => 'text', 'is_nullable' => 'NO']),
            self::colRow('orders', 'email', ['data_type' => 'text', 'is_nullable' => 'NO']),
        ];

        $result = $this->invoke('assembleColumns', [$rows, [], ['users' => ['email' => true]]]);

        $this->assertSame('"email" text', $result['users']['email']);
        $this->assertSame('"email" text NOT NULL', $result['orders']['email']);
    }

    public function testAssembleColumnsWithNoRows(): void
    {
        $this->assertSame([], $this->invoke('assembleColumns', [[], [], []]));
    }

    // ── assembleIndexes ───────────────────────────────────────────────────

    public function testAssembleIndexesSplitsRowsAcrossTables(): void
    {
        $rows = [
            ['table_name' => 'users',  'indexname' => 'users_email_idx',  'indexdef' => 'CREATE INDEX users_email_idx ON users (email)'],
            ['table_name' => 'orders', 'indexname' => 'orders_user_idx',  'indexdef' => 'CREATE INDEX orders_user_idx ON orders (user_id)'],
        ];

        $result = $this->invoke('assembleIndexes', [$rows, []]);

        $this->assertSame(['users_email_idx'], array_keys($result['users']));
        $this->assertSame(['orders_user_idx'], array_keys($result['orders']));
    }

    public function testAssembleIndexesSkipsConstraintBackedIndexes(): void
    {
        // PK/UNIQUE/EXCLUDE indexes are rendered as constraints instead.
        $rows = [
            ['table_name' => 'users', 'indexname' => 'users_pkey',      'indexdef' => 'CREATE UNIQUE INDEX users_pkey ON users (id)'],
            ['table_name' => 'users', 'indexname' => 'users_email_idx', 'indexdef' => 'CREATE INDEX users_email_idx ON users (email)'],
        ];

        $result = $this->invoke('assembleIndexes', [$rows, ['users' => ['users_pkey' => true]]]);
        $this->assertSame(['users_email_idx'], array_keys($result['users']));
    }

    public function testAssembleIndexesSkipListIsScopedPerTable(): void
    {
        // Index names are only unique per schema in practice, but the skip list
        // is keyed by table: a constraint index on one table must never hide a
        // same-named plain index on another.
        $rows = [
            ['table_name' => 'users',  'indexname' => 'shared_name', 'indexdef' => 'CREATE UNIQUE INDEX shared_name ON users (id)'],
            ['table_name' => 'orders', 'indexname' => 'shared_name', 'indexdef' => 'CREATE INDEX shared_name ON orders (ref)'],
        ];

        $result = $this->invoke('assembleIndexes', [$rows, ['users' => ['shared_name' => true]]]);

        $this->assertSame([], $result['users']);
        $this->assertSame(
            ['shared_name' => 'CREATE INDEX shared_name ON orders (ref)'],
            $result['orders']
        );
    }

    public function testAssembleIndexesKeepsTableWithOnlySkippedIndexes(): void
    {
        $rows = [
            ['table_name' => 'users', 'indexname' => 'users_pkey', 'indexdef' => 'CREATE UNIQUE INDEX users_pkey ON users (id)'],
        ];

        $result = $this->invoke('assembleIndexes', [$rows, ['users' => ['users_pkey' => true]]]);
        $this->assertSame(['users' => []], $result);
    }

    // ── assembleConstraints ───────────────────────────────────────────────

    public function testAssembleConstraintsSplitsRowsAcrossTables(): void
    {
        $rows = [
            self::conRow('users',  'users_email_key',  ['column_name' => 'email']),
            self::conRow('orders', 'orders_ref_key',   ['column_name' => 'ref']),
        ];

        $result = $this->invoke('assembleConstraints', [$rows, [], []]);

        $this->assertSame(['users_email_key'], array_keys($result['users']));
        $this->assertSame(['orders_ref_key'], array_keys($result['orders']));
    }

    public function testAssembleConstraintsKeepsSameConstraintNameSeparatePerTable(): void
    {
        // Postgres scopes constraint names per table, not per schema, so two
        // tables may legitimately share one. Batching must not merge them.
        $rows = [
            self::conRow('users',  'uniq_code', ['column_name' => 'code']),
            self::conRow('orders', 'uniq_code', ['column_name' => 'ref']),
        ];

        $result = $this->invoke('assembleConstraints', [$rows, [], []]);

        $this->assertSame('CONSTRAINT "uniq_code" UNIQUE ("code")', $result['users']['uniq_code']);
        $this->assertSame('CONSTRAINT "uniq_code" UNIQUE ("ref")',  $result['orders']['uniq_code']);
    }

    public function testAssembleConstraintsGroupsMultiColumnConstraint(): void
    {
        $rows = [
            self::conRow('t', 'uniq_ab', ['column_name' => 'a', 'ordinal_position' => 1]),
            self::conRow('t', 'uniq_ab', ['column_name' => 'b', 'ordinal_position' => 2]),
        ];

        $result = $this->invoke('assembleConstraints', [$rows, [], []]);
        $this->assertSame('CONSTRAINT "uniq_ab" UNIQUE ("a", "b")', $result['t']['uniq_ab']);
    }

    public function testAssembleConstraintsCollapsesJoinFanOut(): void
    {
        // A 2-column FK referencing a 2-column key produces 2x2 rows because
        // key_column_usage and constraint_column_usage are joined together.
        // Each column must still appear exactly once in the emitted DDL.
        $base = [
            'constraint_type' => 'FOREIGN KEY',
            'foreign_table'   => 'parent',
            'update_rule'     => 'NO ACTION',
            'delete_rule'     => 'CASCADE',
        ];
        $rows = [
            self::conRow('child', 'fk_ab', $base + ['column_name' => 'a', 'ordinal_position' => 1, 'foreign_column' => 'x']),
            self::conRow('child', 'fk_ab', $base + ['column_name' => 'a', 'ordinal_position' => 1, 'foreign_column' => 'y']),
            self::conRow('child', 'fk_ab', $base + ['column_name' => 'b', 'ordinal_position' => 2, 'foreign_column' => 'x']),
            self::conRow('child', 'fk_ab', $base + ['column_name' => 'b', 'ordinal_position' => 2, 'foreign_column' => 'y']),
        ];

        $result = $this->invoke('assembleConstraints', [$rows, [], []]);
        $this->assertStringContainsString('FOREIGN KEY ("a", "b")', $result['child']['fk_ab']);
    }

    public function testAssembleConstraintsAppendsCheckConstraints(): void
    {
        $checks = [
            ['table_name' => 't', 'constraint_name' => 'chk_pos', 'definition' => 'CHECK ((qty > 0))'],
        ];

        $result = $this->invoke('assembleConstraints', [[], $checks, []]);
        $this->assertSame('CONSTRAINT "chk_pos" CHECK ((qty > 0))', $result['t']['chk_pos']);
    }

    public function testAssembleConstraintsAppendsNamedNotNull(): void
    {
        $nn = ['t' => ['email_required' => ['column_name' => 'email', 'convalidated' => true]]];

        $result = $this->invoke('assembleConstraints', [[], [], $nn]);
        $this->assertSame('CONSTRAINT "email_required" NOT NULL "email"', $result['t']['email_required']);
    }

    public function testAssembleConstraintsMarksUnvalidatedNamedNotNull(): void
    {
        $nn = ['t' => ['email_required' => ['column_name' => 'email', 'convalidated' => false]]];

        $result = $this->invoke('assembleConstraints', [[], [], $nn]);
        $this->assertSame('CONSTRAINT "email_required" NOT NULL "email" NOT VALID', $result['t']['email_required']);
    }

    public function testAssembleConstraintsCombinesAllThreeSources(): void
    {
        $rows   = [self::conRow('t', 'uniq_a', ['column_name' => 'a'])];
        $checks = [['table_name' => 't', 'constraint_name' => 'chk_a', 'definition' => 'CHECK ((a > 0))']];
        $nn     = ['t' => ['nn_a' => ['column_name' => 'a', 'convalidated' => true]]];

        $result = $this->invoke('assembleConstraints', [$rows, $checks, $nn]);
        $this->assertSame(['uniq_a', 'chk_a', 'nn_a'], array_keys($result['t']));
    }

    public function testAssembleConstraintsDropsUnrenderableTypes(): void
    {
        // buildConstraintDef returns null for types it does not render; a null
        // must never reach the diff map, where it would be compared as a value.
        $rows = [self::conRow('t', 'weird', ['constraint_type' => 'SOMETHING ELSE', 'column_name' => 'a'])];

        $result = $this->invoke('assembleConstraints', [$rows, [], []]);
        $this->assertArrayNotHasKey('weird', $result['t'] ?? []);
    }

    // ── buildConstraintDef ────────────────────────────────────────────────

    public function testBuildConstraintDefForeignKey(): void
    {
        $c = self::conRow('t', 'fk', [
            'constraint_type' => 'FOREIGN KEY',
            'foreign_table'   => 'parent',
            'foreign_column'  => 'id',
            'update_rule'     => 'NO ACTION',
            'delete_rule'     => 'CASCADE',
        ]);
        $c['columns'] = ['parent_id'];

        $this->assertSame(
            'CONSTRAINT "fk" FOREIGN KEY ("parent_id") REFERENCES "parent" ("id")'
            . ' ON UPDATE NO ACTION ON DELETE CASCADE',
            $this->invoke('buildConstraintDef', ['fk', $c])
        );
    }

    /** @dataProvider matchOptionProvider */
    public function testBuildConstraintDefMatchOptions(?string $option, string $expected): void
    {
        $c = self::conRow('t', 'fk', [
            'constraint_type' => 'FOREIGN KEY',
            'foreign_table'   => 'parent',
            'foreign_column'  => 'id',
            'update_rule'     => 'NO ACTION',
            'delete_rule'     => 'NO ACTION',
            'match_option'    => $option,
        ]);
        $c['columns'] = ['parent_id'];

        $def = $this->invoke('buildConstraintDef', ['fk', $c]);
        $this->assertStringContainsString($expected, $def);
    }

    public static function matchOptionProvider(): array
    {
        return [
            'MATCH FULL'          => ['FULL',    '("id") MATCH FULL ON UPDATE'],
            'MATCH PARTIAL'       => ['PARTIAL', '("id") MATCH PARTIAL ON UPDATE'],
            'NONE emits nothing'  => ['NONE',    '("id") ON UPDATE'],
            'SIMPLE emits nothing'=> ['SIMPLE',  '("id") ON UPDATE'],
        ];
    }

    public function testBuildConstraintDefDeferrable(): void
    {
        $c = self::conRow('t', 'uq', ['is_deferrable' => 'YES', 'initially_deferred' => 'YES']);
        $c['columns'] = ['a'];

        $this->assertSame(
            'CONSTRAINT "uq" UNIQUE ("a") DEFERRABLE INITIALLY DEFERRED',
            $this->invoke('buildConstraintDef', ['uq', $c])
        );
    }

    public function testBuildConstraintDefDeferrableInitiallyImmediate(): void
    {
        $c = self::conRow('t', 'uq', ['is_deferrable' => 'YES', 'initially_deferred' => 'NO']);
        $c['columns'] = ['a'];

        $this->assertSame(
            'CONSTRAINT "uq" UNIQUE ("a") DEFERRABLE INITIALLY IMMEDIATE',
            $this->invoke('buildConstraintDef', ['uq', $c])
        );
    }

    public function testBuildConstraintDefNotValidForeignKey(): void
    {
        $c = self::conRow('t', 'fk', [
            'constraint_type' => 'FOREIGN KEY',
            'foreign_table'   => 'parent',
            'foreign_column'  => 'id',
            'update_rule'     => 'NO ACTION',
            'delete_rule'     => 'NO ACTION',
            'convalidated'    => false,
        ]);
        $c['columns'] = ['parent_id'];

        $this->assertStringEndsWith(' NOT VALID', $this->invoke('buildConstraintDef', ['fk', $c]));
    }

    public function testBuildConstraintDefPrimaryKey(): void
    {
        $c = self::conRow('t', 'pk', ['constraint_type' => 'PRIMARY KEY']);
        $c['columns'] = ['a', 'b'];

        $this->assertSame(
            'CONSTRAINT "pk" PRIMARY KEY ("a", "b")',
            $this->invoke('buildConstraintDef', ['pk', $c])
        );
    }

    public function testBuildConstraintDefReturnsNullForUnknownType(): void
    {
        $c = self::conRow('t', 'x', ['constraint_type' => 'CHECK']);
        $c['columns'] = ['a'];

        $this->assertNull($this->invoke('buildConstraintDef', ['x', $c]));
    }

    // ── buildColumnType ───────────────────────────────────────────────────

    /** @dataProvider columnTypeProvider */
    public function testBuildColumnType(array $col, string $expected): void
    {
        $row = self::colRow('t', 'c', $col);
        $this->assertSame($expected, $this->invoke('buildColumnType', [$row]));
    }

    public static function columnTypeProvider(): array
    {
        return [
            'domain wins over data_type' => [
                ['data_type' => 'text', 'domain_name' => 'email_address'], 'email_address',
            ],
            'time without time zone' => [['data_type' => 'time without time zone'], 'time'],
            'time with time zone'    => [['data_type' => 'time with time zone'],    'timetz'],
            'double precision'       => [['data_type' => 'double precision'],       'double precision'],
            'array uses udt_name'    => [['data_type' => 'ARRAY', 'udt_name' => '_int4'], '_int4'],
            'varchar with length'    => [
                ['data_type' => 'character varying', 'character_maximum_length' => 255], 'varchar(255)',
            ],
            'varchar without length' => [
                ['data_type' => 'character varying', 'character_maximum_length' => null], 'varchar',
            ],
            'char with length' => [
                ['data_type' => 'character', 'character_maximum_length' => 10], 'char(10)',
            ],
            'numeric with precision' => [
                ['data_type' => 'numeric', 'numeric_precision' => 10, 'numeric_scale' => 2], 'numeric(10,2)',
            ],
            'numeric without precision' => [
                ['data_type' => 'numeric', 'numeric_precision' => null], 'numeric',
            ],
            'timestamptz no precision' => [
                ['data_type' => 'timestamp with time zone', 'datetime_precision' => 0], 'timestamptz',
            ],
            'timestamptz with precision' => [
                ['data_type' => 'timestamp with time zone', 'datetime_precision' => 3], 'timestamptz(3)',
            ],
            'timestamp no precision' => [
                ['data_type' => 'timestamp without time zone', 'datetime_precision' => 0], 'timestamp',
            ],
            'timestamp with precision' => [
                ['data_type' => 'timestamp without time zone', 'datetime_precision' => 6], 'timestamp(6)',
            ],
            'passthrough integer' => [['data_type' => 'integer'], 'integer'],
            'passthrough jsonb'   => [['data_type' => 'jsonb'],   'jsonb'],
        ];
    }

    // ── getBulkTableSchema guard ──────────────────────────────────────────

    public function testGetBulkTableSchemaShortCircuitsOnEmptyList(): void
    {
        // Must return before touching the connection — an IN () clause built
        // from an empty list would be a syntax error on every driver.
        $connection = $this->createMock(\Illuminate\Database\Connection::class);
        $connection->expects($this->never())->method('select');

        $this->assertSame([], $this->adapter->getBulkTableSchema($connection, []));
    }
}
