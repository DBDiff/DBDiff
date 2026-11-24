<?php

declare(strict_types=1);

namespace Tests\Unit;

use DBDiff\DB\Adapters\MySQLAdapter;
use DBDiff\DB\Adapters\PostgresAdapter;
use DBDiff\DB\Support\TlsConfig;
use DBDiff\Migration\Config\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * A URL's TLS settings reach the connection: libpq's `sslrootcert`,
 * `sslcert` and `sslkey` for PostgreSQL, and the mysql client's `ssl-mode`
 * and `ssl-ca` for MySQL. Only `sslmode` used to, so verifying a server
 * against a CA of its own could not be done.
 */
class TlsConfigTest extends TestCase
{
    private static function server(string $url): array
    {
        return DsnParser::toServerAndDb(DsnParser::parse($url))['server'];
    }

    private static function pdoMysql(string $name): int
    {
        return defined("Pdo\\Mysql::{$name}") ? constant("Pdo\\Mysql::{$name}") : constant("PDO::MYSQL_{$name}");
    }

    public function testAPostgresUrlsCertificateFilesReachTheConnection(): void
    {
        $server = self::server('postgresql://u:p@db.example.com:5432/app?sslmode=verify-full&sslrootcert=/certs/ca.crt&sslcert=/certs/me.crt&sslkey=/certs/me.key');
        $config = (new PostgresAdapter())->buildConnectionConfig($server, 'app');

        $this->assertSame('verify-full', $config['sslmode']);
        $this->assertSame('/certs/ca.crt', $config['sslrootcert']);
        $this->assertSame('/certs/me.crt', $config['sslcert']);
        $this->assertSame('/certs/me.key', $config['sslkey']);
    }

    public function testAPostgresUrlWithoutThemNamesNone(): void
    {
        $config = (new PostgresAdapter())->buildConnectionConfig(self::server('postgresql://u:p@localhost/app'), 'app');

        foreach (TlsConfig::PG_FILES as $key) {
            $this->assertArrayNotHasKey($key, $config);
        }
        $this->assertSame('prefer', $config['sslmode']);
    }

    public function testEachServerKeepsItsOwnFiles(): void
    {
        $one = self::server('postgresql://u:p@a/x?sslrootcert=/a.crt');
        $two = self::server('postgresql://u:p@b/y');

        $this->assertSame('/a.crt', $one['sslrootcert']);
        $this->assertArrayNotHasKey('sslrootcert', $two);
    }

    public function testPgDumpGetsThemInLibpqsEnvironment(): void
    {
        $config = ['sslmode' => 'verify-full', 'sslrootcert' => '/c/ca.crt', 'sslcert' => '', 'sslkey' => null];
        $env = TlsConfig::libpqEnvironment(fn (string $key) => $config[$key] ?? null);

        $this->assertSame(['PGSSLMODE' => 'verify-full', 'PGSSLROOTCERT' => '/c/ca.crt'], $env);
    }

    public function testAMySQLUrlVerifiesAgainstItsCa(): void
    {
        $server = self::server('mysql://u:p@db.example.com:3306/app?ssl-mode=VERIFY_IDENTITY&ssl-ca=/certs/ca.pem');
        $options = (new MySQLAdapter())->buildConnectionConfig($server, 'app')['options'];

        $this->assertSame('/certs/ca.pem', $options[self::pdoMysql('ATTR_SSL_CA')]);
        $this->assertTrue($options[self::pdoMysql('ATTR_SSL_VERIFY_SERVER_CERT')]);
    }

    public function testAMySQLUrlTakesLibpqsSpellingsToo(): void
    {
        $server = self::server('mysql://u:p@h/app?sslmode=verify-full&sslrootcert=/ca.pem&sslcert=/me.pem&sslkey=/me.key');
        $options = TlsConfig::mysqlOptions($server);

        $this->assertSame('/ca.pem', $options[self::pdoMysql('ATTR_SSL_CA')]);
        $this->assertSame('/me.pem', $options[self::pdoMysql('ATTR_SSL_CERT')]);
        $this->assertSame('/me.key', $options[self::pdoMysql('ATTR_SSL_KEY')]);
        $this->assertTrue($options[self::pdoMysql('ATTR_SSL_VERIFY_SERVER_CERT')]);
    }

    /** As the mysql client does: a CA named and no mode is VERIFY_CA. */
    public function testAMySQLCaAloneVerifies(): void
    {
        $this->assertSame('VERIFY_CA', TlsConfig::mysqlMode(self::server('mysql://u:p@h/app?ssl-ca=/ca.pem')));
    }

    public function testMySQLRequiredEncryptsWithoutVerifying(): void
    {
        $options = TlsConfig::mysqlOptions(self::server('mysql://u:p@h/app?ssl-mode=REQUIRED'));

        $this->assertFalse($options[self::pdoMysql('ATTR_SSL_VERIFY_SERVER_CERT')]);
        $this->assertArrayHasKey(self::pdoMysql('ATTR_SSL_CIPHER'), $options);
    }

    public function testMySQLVerifyingWithoutACaIsRefusedNotRunUnverified(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ssl-ca');
        TlsConfig::mysqlOptions(self::server('mysql://u:p@h/app?ssl-mode=VERIFY_CA'));
    }

    public function testAnUnknownMySQLModeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TlsConfig::mysqlOptions(['sslmode' => 'sometimes']);
    }

    public function testMySQLWithoutTlsSettingsAddsNoOptions(): void
    {
        $this->assertSame([], (new MySQLAdapter())->buildConnectionConfig(self::server('mysql://u:p@h/app'), 'app')['options']);
        $this->assertSame([], TlsConfig::mysqlOptions(['sslmode' => 'DISABLED']));
        $this->assertSame([], TlsConfig::mysqlOptions(['sslmode' => 'preferred']));
    }
}
