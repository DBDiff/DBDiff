<?php namespace DBDiff\DB\Data;

use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;

/**
 * Wrapper for binary column values (BINARY, VARBINARY, BLOB).
 *
 * Binary data is held as a hex string — from MySQL's HEX(), or read from
 * PostgreSQL's bytea stream — and each dialect writes it in the form its
 * engine reads: UNHEX('…'), '\x…'::bytea, X'…'.
 */
class BinaryValue
{
    public readonly string $hex;

    public function __construct(string $hex)
    {
        $this->hex = $hex;
    }

    public function __toString(): string
    {
        return $this->hex;
    }

    /**
     * Format a value as a literal for the dialect writing it — see
     * SQLDialectInterface::literal(). Without one, the registered dialect.
     */
    public static function formatSQL($value, ?SQLDialectInterface $dialect = null): string
    {
        return ($dialect ?? DialectRegistry::get())->literal($value);
    }

    /**
     * Format "quoted_column = value" for WHERE / SET clauses.
     */
    public static function formatCondition(string $quotedColumn, $value, ?SQLDialectInterface $dialect = null): string
    {
        if ($value === null) {
            return "$quotedColumn IS NULL";
        }
        return "$quotedColumn = " . self::formatSQL($value, $dialect);
    }

    /**
     * A fetched row with each binary stream read into a BinaryValue.
     *
     * PDO's PostgreSQL driver returns bytea as a stream, which the SQL
     * generators then handed to string functions: the data diff of any table
     * with a bytea column failed with "addslashes(): Argument #1 must be of
     * type string, resource given".
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fromStreams(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_resource($value)) {
                $row[$column] = new self(bin2hex((string) stream_get_contents($value)));
            }
        }
        return $row;
    }
}
