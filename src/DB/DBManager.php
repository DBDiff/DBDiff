<?php namespace DBDiff\DB;

use Illuminate\Database\Capsule\Manager as Capsule;
use DBDiff\Exceptions\DBException;


class DBManager {

    function __construct() {
        $this->capsule = new Capsule;
    }

    public function connect($params) {
        foreach ($params->input as $key => $input) {
            if ($key === 'kind') continue;
            $server = $params->{$input['server']};
            $db = $input['db'];
            $this->capsule->addConnection([
                'driver'    => 'mysql',
                'host'      => $server['host'],
                'port'      => $server['port'],
                'database'  => $db,
                'username'  => $server['user'],
                'password'  => $server['password'],
                'charset'   => 'utf8',
                'collation' => 'utf8_unicode_ci'
            ], $key);
        }
    }

    public function testResources($params) {
        $this->testResource($params->input['source'], 'source');
        $this->testResource($params->input['target'], 'target');
    }

    public function testResource($input, $res) {
        try {
            $this->capsule->getConnection($res);
        } catch(\Exception $e) {
            throw new DBException("Can't connect to target database");
        }
        if (!empty($input['table'])) {
            try {
                $this->capsule->getConnection($res)->table($input['table'])->first();
            } catch(\Exception $e) {
                throw new DBException("Can't access target table");
            }
        }
    }

    public function getDB($res) {
        return $this->capsule->getConnection($res);
    }

    public function getTables($connection) {
        $result = $this->getDB($connection)->select("show tables");
        return array_flatten($result);
    }

    public function getColumns($connection, $table) {
        $result = $this->getDB($connection)->select("show columns from `$table`");
        return array_pluck($result, 'Field');
    }

    public function getKey($connection, $table) {
        $keys = $this->getDB($connection)->select("show indexes from `$table`");
        $ukey = [];
        foreach ($keys as $key) {
            if ($key['Key_name'] === 'PRIMARY') {
                $ukey[] = $key['Column_name'];
            }
        }
        return $ukey;
    }

}
