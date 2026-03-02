<?php namespace DBDiff\DB;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use DBDiff\DB\Adapters\AdapterFactory;
use DBDiff\DB\Adapters\DBAdapterInterface;
use DBDiff\Exceptions\DBException;


class DBManager {

    protected Capsule $capsule;
    protected DBAdapterInterface $adapter;
    protected string $driver = 'mysql';

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

        foreach ($params->input as $key => $input) {
            if ($key === 'kind') {
                continue;
            }

            // SQLite uses the db value as a file path; no server block required.
            if ($this->driver === 'sqlite') {
                $config = $this->adapter->buildConnectionConfig([], $input['db']);
            } else {
                $server = $params->{$input['server']};
                // Allow top-level params like --supabase to add sslmode to server cfg.
                if (isset($params->sslmode)) {
                    $server['sslmode'] = $params->sslmode;
                }
                $config = $this->adapter->buildConnectionConfig($server, $input['db']);
            }

            $this->capsule->addConnection($config, $key);
        }
    }

    public function testResources($params): void {
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

    public function getDriver(): string {
        return $this->driver;
    }

    // -------------------------------------------------------------------------
    // Convenience wrappers (delegate to the active adapter)
    // -------------------------------------------------------------------------

    public function getTables(string $connection): array {
        return $this->adapter->getTables($this->getDB($connection));
    }

    public function getColumns(string $connection, string $table): array {
        return $this->adapter->getColumns($this->getDB($connection), $table);
    }

    public function getKey(string $connection, string $table): array {
        return $this->adapter->getPrimaryKey($this->getDB($connection), $table);
    }

    public function getCreateStatement(string $connection, string $table): string {
        return $this->adapter->getCreateStatement($this->getDB($connection), $table);
    }

    public function getTableSchema(string $connection, string $table): array {
        return $this->adapter->getTableSchema($this->getDB($connection), $table);
    }

    public function getDBVariable(string $connection, string $variable): ?string {
        return $this->adapter->getDBVariable($this->getDB($connection), $variable);
    }
}
