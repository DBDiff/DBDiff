<?php

use DBDiff\Migration\Config\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DsnParser.
 *
 * Covers:
 *  - parse() for all supported drivers and their aliases
 *  - default port assignment
 *  - SQLite path handling (absolute and relative)
 *  - query-string extras (?sslmode, ?pgbouncer)
 *  - Supabase heuristics (ssl auto, port-6543 pgbouncer)
 *  - URL-encoded credentials
 *  - isSupabaseHost() both TLDs
 *  - toServerAndDb() output shape
 *  - error cases (unsupported scheme, malformed URL)
 */
class DsnParserTest extends TestCase
{
    // ── parse() — MySQL ───────────────────────────────────────────────────────

    public function testMysqlUrl(): void
    {
        $r = DsnParser::parse('mysql://user:pass@localhost:3306/mydb');

        $this->assertSame('mysql', $r['driver']);
        $this->assertSame('user', $r['user']);
        $this->assertSame('pass', $r['password']);
        $this->assertSame('localhost', $r['host']);
        $this->assertSame(3306, $r['port']);
        $this->assertSame('mydb', $r['name']);
        $this->assertSame('', $r['sslmode']);
        $this->assertFalse($r['pgbouncer']);
    }

    public function testMysqlDefaultPort(): void
    {
        $r = DsnParser::parse('mysql://user:pass@localhost/mydb');
        $this->assertSame(3306, $r['port']);
    }

    // ── parse() — Postgres and its aliases ───────────────────────────────────

    public function testPgsqlUrl(): void
    {
        $r = DsnParser::parse('pgsql://user:pass@host:5432/pgdb');
        $this->assertSame('pgsql', $r['driver']);
        $this->assertSame(5432, $r['port']);
        $this->assertSame('pgdb', $r['name']);
    }

    public function testPostgresAlias(): void
    {
        $r = DsnParser::parse('postgres://user:pass@host:5432/pgdb');
        $this->assertSame('pgsql', $r['driver']);
    }

    public function testPostgresqlAlias(): void
    {
        $r = DsnParser::parse('postgresql://user:pass@host:5432/pgdb');
        $this->assertSame('pgsql', $r['driver']);
    }

    public function testPgsqlDefaultPort(): void
    {
        $r = DsnParser::parse('pgsql://user:pass@host/pgdb');
        $this->assertSame(5432, $r['port']);
    }

    // ── parse() — SQLite ─────────────────────────────────────────────────────

    public function testSqliteAbsolutePath(): void
    {
        $r = DsnParser::parse('sqlite:///var/db/myapp.sqlite');
        $this->assertSame('sqlite', $r['driver']);
        $this->assertSame('/var/db/myapp.sqlite', $r['path']);
        $this->assertSame('', $r['name']);
        $this->assertSame(0, $r['port']);
    }

    public function testSqliteRelativePath(): void
    {
        $r = DsnParser::parse('sqlite://./storage/app.sqlite');
        $this->assertSame('sqlite', $r['driver']);
        $this->assertStringStartsWith('.', $r['path']);
    }

    public function testSqliteBarePathGetsLeadingSlash(): void
    {
        // sqlite://rel/path has no leading / or . → resolveSqlitePath prepends /
        $r = DsnParser::parse('sqlite://rel/path/db.sqlite');
        $this->assertSame('sqlite', $r['driver']);
        $this->assertSame('/rel/path/db.sqlite', $r['path']);
    }

    public function testSqliteSchemeWithoutDoubleSlash(): void
    {
        // sqlite:/var/db.sqlite — single slash, unusual but seen in some ORMs
        $r = DsnParser::parse('sqlite:/var/db.sqlite');
        $this->assertSame('sqlite', $r['driver']);
        $this->assertSame('/var/db.sqlite', $r['path']);
    }

    public function testSqliteEmptyArrayKeysPresent(): void
    {
        $r = DsnParser::parse('sqlite:///tmp/test.db');
        foreach (['driver', 'host', 'port', 'name', 'path', 'user', 'password', 'sslmode', 'pgbouncer'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
    }

    // ── parse() — query-string extras ────────────────────────────────────────

    public function testSslModeQueryParam(): void
    {
        $r = DsnParser::parse('pgsql://user:pass@host/db?sslmode=require');
        $this->assertSame('require', $r['sslmode']);
    }

    public function testPgbouncerQueryParam(): void
    {
        $r = DsnParser::parse('pgsql://user:pass@host/db?pgbouncer=true');
        $this->assertTrue($r['pgbouncer']);
    }

    public function testPgbouncerQueryParamFalse(): void
    {
        $r = DsnParser::parse('pgsql://user:pass@host/db?pgbouncer=false');
        $this->assertFalse($r['pgbouncer']);
    }

    // ── Supabase heuristics ───────────────────────────────────────────────────

    public function testSupabaseLegacyHostSetsDefaultSsl(): void
    {
        $r = DsnParser::parse('postgres://postgres:pass@db.projref.supabase.co:5432/postgres');
        $this->assertSame('require', $r['sslmode']);
        $this->assertFalse($r['pgbouncer']);
    }

    public function testSupabasePoolerHostPort6543SetsPgbouncer(): void
    {
        $r = DsnParser::parse('postgres://postgres.projref:pass@aws-0-us-east-1.pooler.supabase.com:6543/postgres');
        $this->assertTrue($r['pgbouncer']);
        $this->assertSame('require', $r['sslmode']);
    }

    public function testSupabaseDirectConnectionPort5432NoPgbouncer(): void
    {
        $r = DsnParser::parse('postgres://postgres.projref:pass@aws-0-us-east-1.pooler.supabase.com:5432/postgres');
        $this->assertFalse($r['pgbouncer']);
        $this->assertSame('require', $r['sslmode']);
    }

    public function testSupabaseExplicitSslNotOverridden(): void
    {
        // User supplies sslmode=disable on a Supabase host; the query param wins
        $r = DsnParser::parse('postgres://user:pass@db.projref.supabase.co/db?sslmode=disable');
        // The current implementation: only sets sslMode when empty, so explicit
        // query param 'disable' is preserved.
        $this->assertSame('disable', $r['sslmode']);
    }

    public function testNonSupabaseHostNoAutoSsl(): void
    {
        $r = DsnParser::parse('pgsql://user:pass@mypghost:5432/db');
        $this->assertSame('', $r['sslmode']);
        $this->assertFalse($r['pgbouncer']);
    }

    // ── isSupabaseHost() ──────────────────────────────────────────────────────

    public function testIsSupabaseHostDotCo(): void
    {
        $this->assertTrue(DsnParser::isSupabaseHost('db.projref.supabase.co'));
    }

    public function testIsSupabaseHostDotCom(): void
    {
        $this->assertTrue(DsnParser::isSupabaseHost('aws-0-us-east-1.pooler.supabase.com'));
    }

    public function testIsNotSupabaseHost(): void
    {
        $this->assertFalse(DsnParser::isSupabaseHost('db.example.com'));
    }

    public function testIsNotSupabaseHostPartialmatch(): void
    {
        // must not match e.g. "notsupabase.co"
        $this->assertFalse(DsnParser::isSupabaseHost('notsupabase.co'));
    }

    // ── Error cases ───────────────────────────────────────────────────────────

    public function testUnsupportedSchemeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DsnParser::parse('mongodb://user:pass@host/db');
    }

    public function testMalformedUrlThrowsWhenNoScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DsnParser::parse('not-a-url');
    }

    // ── URL-encoded credentials ───────────────────────────────────────────────

    public function testUrlEncodedPassword(): void
    {
        $r = DsnParser::parse('postgres://user:p%40ss%21word@host/db');
        $this->assertSame('p@ss!word', $r['password']);
    }

    public function testUrlEncodedUser(): void
    {
        $r = DsnParser::parse('mysql://my%40user:pass@localhost/db');
        $this->assertSame('my@user', $r['user']);
    }

    // ── toServerAndDb() ───────────────────────────────────────────────────────

    public function testToServerAndDbShape(): void
    {
        $r = DsnParser::toServerAndDb(DsnParser::parse('mysql://user:pass@localhost:3306/mydb'));

        $this->assertArrayHasKey('server', $r);
        $this->assertArrayHasKey('db', $r);
        $this->assertArrayHasKey('driver', $r);
        $this->assertArrayHasKey('sslmode', $r);
        $this->assertSame('mydb', $r['db']);
        $this->assertSame('mysql', $r['driver']);
        $this->assertSame('user', $r['server']['user']);
        $this->assertSame('pass', $r['server']['password']);
        $this->assertSame('localhost', $r['server']['host']);
        $this->assertSame('3306', $r['server']['port']); // port is cast to string
    }

    public function testToServerAndDbSqliteUsesPath(): void
    {
        $r = DsnParser::toServerAndDb(DsnParser::parse('sqlite:///var/db/test.sqlite'));
        $this->assertSame('/var/db/test.sqlite', $r['db']);
    }

    public function testToServerAndDbPgsqlResult(): void
    {
        $r = DsnParser::toServerAndDb(DsnParser::parse('pgsql://u:p@myhost:5432/mydb'));
        $this->assertSame('pgsql', $r['driver']);
        $this->assertSame('mydb', $r['db']);
        $this->assertSame('myhost', $r['server']['host']);
    }
}
