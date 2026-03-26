<?php namespace DBDiff\DB\Data;

/**
 * Wrapper for binary column values (BINARY, VARBINARY, BLOB).
 *
 * Binary data is stored as an uppercase hex string (from MySQL HEX()).
 * SQL generators check for this type to emit UNHEX('…') instead of a
 * raw string literal wrapped in addslashes().
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
     * Format a value for SQL output, handling BinaryValue and NULL.
     *
     * @param mixed $value
     */
    public static function formatSQL($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($value instanceof self) {
            return "UNHEX('" . $value->hex . "')";
        }
        return "'" . addslashes($value) . "'";
    }

    /**
     * Format "quoted_column = value" for WHERE / SET clauses.
     */
    public static function formatCondition(string $quotedColumn, $value): string
    {
        if ($value instanceof self) {
            return "$quotedColumn = UNHEX('" . $value->hex . "')";
        }
        return "$quotedColumn = '" . addslashes($value) . "'";
    }
}
