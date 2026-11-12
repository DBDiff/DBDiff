<?php

declare(strict_types=1);

namespace Tests\Unit;

use DBDiff\DB\Adapters\MySQLAdapter;
use DBDiff\DB\Adapters\PostgresAdapter;
use DBDiff\Migration\Config\DsnParser;
use DBDiff\Migration\Config\MigrationConfig;
use PHPUnit\Framework\TestCase;

/**
 * Connecting through a Unix-domain socket (issue #44): a `socket` setting in
 * the server config, ?socket= in a URL, or libpq's ?host=/directory.
 */
class SocketConnectionTest extends TestCase
{
    private const SERVER = ['user' => 'u', 'password' => 'p', 'host' => 'localhost', 'port' => '3306'];

    public function testMySQLConnectsThroughTheSocketFile(): void
    {
        $config = (new MySQLAdapter())->buildConnectionConfig(self::SERVER + ['socket' => '/Applications/MAMP/tmp/mysql/mysql.sock'], 'db');

        $this->assertSame('/Applications/MAMP/tmp/mysql/mysql.sock', $config['unix_socket']);
    }

    /** A config naming only the socket, as #44 asked, is enough. */
    public function testAMySQLServerGivenOnlyASocketNeedsNoHostOrPort(): void
    {
        $config = (new MySQLAdapter())->buildConnectionConfig(['user' => 'root', 'password' => 'root', 'socket' => '/tmp/mysql.sock'], 'db');

        $this->assertSame('/tmp/mysql.sock', $config['unix_socket']);
        $this->assertNotEmpty($config['host']);
    }

    public function testMySQLWithoutASocketKeepsTCP(): void
    {
        $config = (new MySQLAdapter())->buildConnectionConfig(self::SERVER, 'db');

        $this->assertArrayNotHasKey('unix_socket', $config);
        $this->assertSame('localhost', $config['host']);
    }

    public function testPostgresTakesTheSocketDirectoryAsItsHost(): void
    {
        $config = (new PostgresAdapter())->buildConnectionConfig(self::SERVER + ['socket' => '/var/run/postgresql/'], 'db');

        $this->assertSame('/var/run/postgresql', $config['host']);
    }

    /** The file a server reports (.s.PGSQL.<port>) is its directory and port. */
    public function testPostgresReadsASocketFileAsItsDirectoryAndPort(): void
    {
        $config = (new PostgresAdapter())->buildConnectionConfig(self::SERVER + ['socket' => '/tmp/.s.PGSQL.5433'], 'db');

        $this->assertSame('/tmp', $config['host']);
        $this->assertSame('5433', $config['port']);
    }

    public function testAUrlNamesTheSocket(): void
    {
        $server = DsnParser::toServerAndDb(DsnParser::parse('mysql://root:pw@localhost/shop?socket=/tmp/mysql.sock'))['server'];

        $this->assertSame('/tmp/mysql.sock', $server['socket']);
    }

    public function testMySQLsOwnUnixSocketParameterIsUnderstood(): void
    {
        $parsed = DsnParser::parse('mysql://root@localhost/shop?unix_socket=/tmp/mysql.sock');

        $this->assertSame('/tmp/mysql.sock', $parsed['socket']);
    }

    /** libpq's form: no host in the authority, the directory as ?host=. */
    public function testALibpqSocketUrlWithoutAHostParses(): void
    {
        $parsed = DsnParser::parse('postgres://app:secret@/orders?host=/var/run/postgresql');

        $this->assertSame('/var/run/postgresql', $parsed['socket']);
        $this->assertSame('orders', $parsed['name']);
        $this->assertSame('app', $parsed['user']);
        $this->assertSame('secret', $parsed['password']);
    }

    public function testAUrlWithoutASocketCarriesNone(): void
    {
        $server = DsnParser::toServerAndDb(DsnParser::parse('postgres://app@db.example.com:5432/orders'))['server'];

        $this->assertArrayNotHasKey('socket', $server);
        $this->assertSame('db.example.com', $server['host']);
    }

    public function testMigrationsConnectThroughTheSocketTheirUrlNames(): void
    {
        $config = new MigrationConfig(null, ['db_url' => 'mysql://root@localhost/shop?socket=/tmp/mysql.sock']);

        $this->assertSame('/tmp/mysql.sock', $config->toConnectionConfig()['unix_socket']);
    }

    public function testMigrationsConnectThroughAPostgresSocketDirectory(): void
    {
        $config = new MigrationConfig(null, ['db_url' => 'postgres://app@/orders?host=/var/run/postgresql']);

        $this->assertSame('/var/run/postgresql', $config->toConnectionConfig()['host']);
    }
}
