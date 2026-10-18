<?php namespace DBDiff\DB;

use DBDiff\DB\Support\PostgresSessionOptions;
use DBDiff\DB\Support\PostgresSnapshot;
use DBDiff\DB\Support\ServerSettings;

use DBDiff\DB\Support\ComputedColumns;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use DBDiff\DB\Adapters\AdapterFactory;
use DBDiff\DB\Adapters\DBAdapterInterface;
use DBDiff\DB\Adapters\ColumnDependencyAdapterInterface;
use DBDiff\Exceptions\DBException;

class DBManager {

    protected Capsule $capsule;
    protected DBAdapterInterface $adapter;
    protected string $driver = 'mysql';

    /** The schema useSchema() last pointed the connections at. */
    protected ?string $schema = null;

    /** @var array<string, string> each side's PostgreSQL snapshot, which its connections read as of */
    protected array $snapshots = [];

    function __construct() {
        $this->capsule = new Capsule;
        $dispatcher    = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, function ($event) {
            $event->statement->setFetchMode(\PDO::FETCH_ASSOC);
        });
        $this->capsule->setEventDispatcher($dispatcher);

        // Default adapter; overridden by connect() once params are available.
        $this->adapter = AdapterFactory::create('mysql');
    }

    public function connect($params): void {
        $this->driver  = $params->driver ?? 'mysql';
        $this->adapter = AdapterFactory::create($this->driver);

        if (!isset($params->input) || !is_iterable($params->input)) {
            throw new DBException('Database connection parameters not configured. Provide server1/server2 or --server1-url/--server2-url.');
        }

        foreach ($params->input as $key => $input) {
            if ($key === 'kind') {
                continue;
            }

            // SQLite uses the db value as a file path; no server block required.
            if ($this->driver === 'sqlite') {
                $config = $this->adapter->buildConnectionConfig([], $input['db']);
            } else {
                $server = $params->{$input['server']};
                ServerSettings::requireFor($server, $input['server']);
                // --supabase / --sslmode supply an sslmode where the server's own
                // URL does not name one.
                if (isset($params->sslmode) && empty($server['sslmode'])) {
                    $server['sslmode'] = $params->sslmode;
                }
                $config = $this->adapter->buildConnectionConfig($server, $input['db']);
            }

            $this->capsule->addConnection($config, $key);
            // The URL's session settings, on a connection just created.
            PostgresSessionOptions::apply($this->capsule->getConnection($key));

            if ($this->driver === 'pgsql' && ($params->snapshot ?? true)) {
                $this->holdSnapshot($key, $config);
            }
        }
    }

    /**
     * Read `$side` as of one instant: a second connection exports its
     * snapshot and keeps it open, and the one DBDiff reads with joins it —
     * see PostgresSnapshot.
     */
    private function holdSnapshot(string $side, array $config): void {
        $anchor = $side . '__snapshot';
        $this->capsule->addConnection($config, $anchor);
        $connection = $this->capsule->getConnection($anchor);
        PostgresSessionOptions::apply($connection);
        $id = PostgresSnapshot::export($connection, $side);
        if ($id !== null && PostgresSnapshot::join($this->getDB($side), $id, $side)) {
            $this->snapshots[$side] = $id;
        }
    }

    /**
     * Point both connections' catalog reads at one PostgreSQL schema.
     *
     * The search path stays `public`, which is where a migration is read
     * against: a definition PostgreSQL renders — a view, a constraint, a
     * column's type — then names everything outside `public` in full, so it
     * means the same thing when it is applied.
     */
    public function useSchema(string $schema): void {
        if ($this->schema === $schema) {
            return;
        }
        $this->schema = $schema;
        foreach (['source', 'target'] as $name) {
            $config = $this->getDB($name)->getConfig();
            $config['schema']      = $schema;
            $config['search_path'] = 'public';
            $this->capsule->getDatabaseManager()->purge($name);
            $this->capsule->addConnection($config, $name);
            PostgresSessionOptions::apply($this->capsule->getConnection($name));
            // The new connection reads as of the same instant as the last.
            if (isset($this->snapshots[$name])
                && !PostgresSnapshot::join($this->getDB($name), $this->snapshots[$name], $name)) {
                unset($this->snapshots[$name]);
            }
        }
    }

    public function testResources($params): void {
        if (!isset($params->input['source'])) {
            throw new DBException('Database connection [source] not configured.');
        }
        if (!isset($params->input['target'])) {
            throw new DBException('Database connection [target] not configured.');
        }
        $this->testResource($params->input['source'], 'source');
        $this->testResource($params->input['target'], 'target');
    }

    public function testResource($input, string $res): void {
        try {
            $this->capsule->getConnection($res);
        } catch (\Exception $e) {
            throw new DBException("Can't connect to $res database: " . $e->getMessage());
        }
        if (!empty($input['table'])) {
            try {
                $this->capsule->getConnection($res)->table($input['table'])->first();
            } catch (\Exception $e) {
                throw new DBException("Can't access table `{$input['table']}` on $res: " . $e->getMessage());
            }
        }
    }

    public function getDB(string $res) {
        return $this->capsule->getConnection($res);
    }

    public function getAdapter(): DBAdapterInterface {
        return $this->adapter;
    }

    /** Whether `$side` is read as of one snapshot. */
    public function readsSnapshot(string $side): bool {
        return isset($this->snapshots[$side]);
    }

    public function getDriver(): string {
        return $this->driver;
    }

    // -------------------------------------------------------------------------
    // Convenience wrappers (delegate to the active adapter)
    // -------------------------------------------------------------------------

    public function getTables(string $connection): array {
        return $this->adapter->getTables($this->getDB($connection));
    }

    /**
     * The columns a data diff reads and writes: every column but the generated
     * ones, whose values the server computes and refuses to be given.
     */
    public function getDataColumns(string $connection, string $table): array {
        $db = $this->getDB($connection);
        return array_values(array_diff(
            $this->adapter->getColumns($db, $table),
            ComputedColumns::generated($db, $this->getDriver(), $table)
        ));
    }

    public function getKey(string $connection, string $table): array {
        return $this->adapter->getPrimaryKey($this->getDB($connection), $table);
    }

    /** @param list<string> $withoutConstraints see DBAdapterInterface::getCreateStatement() */
    public function getCreateStatement(string $connection, string $table, array $withoutConstraints = []): string {
        return $this->adapter->getCreateStatement($this->getDB($connection), $table, $withoutConstraints);
    }

    public function getTableSchema(string $connection, string $table): array {
        return $this->adapter->getTableSchema($this->getDB($connection), $table);
    }

    public function getDBVariable(string $connection, string $variable): ?string {
        return $this->adapter->getDBVariable($this->getDB($connection), $variable);
    }

    public function getBinaryColumns(string $connection, string $table): array {
        return $this->adapter->getBinaryColumns($this->getDB($connection), $table);
    }

    public function getForeignKeyMap(string $connection): array {
        return $this->adapter->getForeignKeyMap($this->getDB($connection));
    }

    public function getViews(string $connection): array {
        return $this->adapter->getViews($this->getDB($connection));
    }

    public function getTriggers(string $connection): array {
        return $this->adapter->getTriggers($this->getDB($connection));
    }

    public function getRoutines(string $connection): array {
        return $this->adapter->getRoutines($this->getDB($connection));
    }

    public function getEnums(string $connection): array {
        return $this->adapter->getEnums($this->getDB($connection));
    }


    /**
     * What reads one column on the named connection.
     *
     * Null for an adapter that does not implement the capability: only
     * PostgreSQL refuses a column type change while a view, policy or trigger
     * reads the column, so only PostgreSQL needs them dropped and recreated
     * around it (issue #226).
     */
    public function getColumnDependants(string $connection, string $table, string $column, bool $regenerate = false): ?array {
        if (!$this->adapter instanceof ColumnDependencyAdapterInterface) {
            return null;
        }
        return $this->adapter->getColumnDependants($this->getDB($connection), $table, $column, $regenerate);
    }

    public function getSchemaHashMap(string $connection, array $tables = []): array {
        return $this->adapter->getSchemaHashMap($this->getDB($connection), $tables);
    }
}
