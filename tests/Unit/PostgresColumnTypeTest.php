<?php

use PHPUnit\Framework\TestCase;
use DBDiff\DB\Support\PostgresColumnType;

/**
 * How a column's declared type is spelled, for the types a real schema uses
 * and a synthetic one rarely does: arrays with modifiers, extension types with
 * a modifier, and domains whose names need quoting.
 */
class PostgresColumnTypeTest extends TestCase
{
    private static function col(array $over): array
    {
        return $over + [
            'data_type' => 'text', 'udt_name' => 'text', 'udt_schema' => 'pg_catalog',
            'domain_name' => null, 'domain_schema' => null, 'atttypmod' => -1,
            'formatted_type' => null, 'character_maximum_length' => null,
            'numeric_precision' => null, 'numeric_scale' => null,
        ];
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function arrays(): array
    {
        return [
            'varchar(20)[]'       => [['udt_name' => '_varchar', 'atttypmod' => 24, 'formatted_type' => 'character varying(20)[]'], 'character varying(20)[]'],
            'numeric(10,2)[]'     => [['udt_name' => '_numeric', 'atttypmod' => 655366, 'formatted_type' => 'numeric(10,2)[]'], 'numeric(10,2)[]'],
            'bit(4)[]'            => [['udt_name' => '_bit', 'atttypmod' => 4, 'formatted_type' => 'bit(4)[]'], 'bit(4)[]'],
            'mixed-case enum[]'   => [['udt_name' => '_Mood', 'udt_schema' => 'public', 'formatted_type' => '"Mood"[]'], '"Mood"[]'],
            'other-schema enum[]' => [['udt_name' => '_status', 'udt_schema' => 'app', 'formatted_type' => 'app.status[]'], 'app.status[]'],
        ];
    }

    /**
     * The catalog's internal array name (`_varchar`) drops the element's
     * modifier and cannot name a mixed-case or non-public element type.
     *
     * @dataProvider arrays
     */
    public function testArraysAreSpelledByTheServer(array $over, string $expected): void
    {
        $this->assertSame($expected, PostgresColumnType::render(self::col(['data_type' => 'ARRAY'] + $over)));
    }

    public function testArrayWithoutServerSpellingFallsBackToUdtName(): void
    {
        $this->assertSame('_int4', PostgresColumnType::render(self::col(['data_type' => 'ARRAY', 'udt_name' => '_int4'])));
    }

    /** vector(1536) without its dimension is a different column, and an HNSW index on it fails. */
    public function testExtensionTypeKeepsItsModifier(): void
    {
        $col = self::col(['data_type' => 'USER-DEFINED', 'udt_name' => 'vector', 'udt_schema' => 'public',
                          'atttypmod' => 1536, 'formatted_type' => 'vector(1536)']);
        $this->assertSame('vector(1536)', PostgresColumnType::render($col));
    }

    /** Without a modifier a user-defined type keeps its quoted, qualified-outside-public spelling. */
    public function testUserDefinedTypeWithoutModifierIsUnchanged(): void
    {
        $col = self::col(['data_type' => 'USER-DEFINED', 'udt_name' => 'mpaa_rating', 'udt_schema' => 'public',
                          'formatted_type' => 'mpaa_rating']);
        $this->assertSame('"mpaa_rating"', PostgresColumnType::render($col));
    }

    /** A bare `Name` folds to PostgreSQL's own `name` type. */
    public function testMixedCaseDomainIsQuoted(): void
    {
        $col = self::col(['data_type' => 'character varying', 'domain_name' => 'Name', 'domain_schema' => 'public']);
        $this->assertSame('"Name"', PostgresColumnType::render($col));
    }

    public function testPlainDomainStaysBare(): void
    {
        $col = self::col(['data_type' => 'integer', 'domain_name' => 'year', 'domain_schema' => 'public']);
        $this->assertSame('year', PostgresColumnType::render($col));
    }

    public function testDomainOutsidePublicIsQualified(): void
    {
        $col = self::col(['data_type' => 'integer', 'domain_name' => 'pos', 'domain_schema' => 'app']);
        $this->assertSame('"app"."pos"', PostgresColumnType::render($col));
    }
}
