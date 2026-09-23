<?php

namespace DBDiff\DB\Support;

/**
 * How a column's type is spelled in generated DDL.
 *
 * Lifted out of PostgresAdapter, which the four cases below took past the
 * 20-method ceiling. It is a coherent unit on its own: everything here answers
 * one question — given a column, what type did it declare? — and the adapter is
 * left to fetching catalog rows.
 *
 * The cases are ordered. A domain names itself, the date/time family needs the
 * catalog rather than information_schema, a few types need renaming, and
 * everything else takes its length or scale from information_schema, which
 * reports those faithfully.
 */
class PostgresColumnType {

    /**
     * Types whose modifier is a bare precision, mapped to the short spelling.
     *
     * information_schema reports the *default* precision for these rather than
     * the absence of a modifier — `datetime_precision` is 6 both for
     * `timestamptz` and for `timestamptz(6)` — so it cannot answer the only
     * question that matters here. atttypmod can: -1 means no modifier was given,
     * and for these four anything else is the precision (issue #215).
     */
    private const PRECISION_ALIASES = [
        'timestamp with time zone'    => 'timestamptz',
        'timestamp without time zone' => 'timestamp',
        'time with time zone'         => 'timetz',
        'time without time zone'      => 'time',
    ];

    /**
     * The column's type as it was declared.
     *
     * A list of cases rather than a chain of early exits: each helper answers
     * null when the column is not its business, and the last one always answers.
     */
    public static function render(array $col): string {
        return self::domainType($col)
            ?? self::declaredPrecisionType($col)
            ?? self::aliasedType($col)
            ?? self::parameterisedType($col);
    }

    /** A domain names itself; its own definition carries the underlying type. */
    private static function domainType(array $col): ?string {
        return empty($col['domain_name']) ? null : $col['domain_name'];
    }

    /**
     * The date/time family, whose precision has to come from the catalog.
     *
     * Deciding on information_schema emitted a precision the column was never
     * declared with — behaviourally identical, but a different type in the
     * catalog, so a migration generated from the column did not reproduce it and
     * any post-apply verification called the column still drifted. It was wrong
     * in the other direction too: the old `datetime_precision > 0` test dropped a
     * declared `timestamp(0)`, 0 being a legal precision, and `time`/`timetz`
     * lost theirs entirely by being resolved as plain aliases before any
     * precision was considered (issue #215).
     */
    private static function declaredPrecisionType(array $col): ?string {
        $dataType = $col['data_type'];

        if (isset(self::PRECISION_ALIASES[$dataType])) {
            $base      = self::PRECISION_ALIASES[$dataType];
            $precision = (int) ($col['atttypmod'] ?? -1);
            return ($precision >= 0) ? "$base($precision)" : $base;
        }

        // interval's modifier encodes a field range as well as a precision
        // (`interval day to second(3)`), which no single number can carry, so the
        // server's own rendering is used verbatim.
        if ($dataType === 'interval') {
            return $col['formatted_type'] ?? 'interval';
        }

        return null;
    }

    /** Types that need renaming but take no modifier. */
    private static function aliasedType(array $col): ?string {
        return match ($col['data_type']) {
            'double precision' => 'double precision',
            'ARRAY'            => $col['udt_name'],
            // information_schema reports every enum, composite and extension
            // type as the literal string 'USER-DEFINED'; the real name is in
            // udt_name. Without this an enum column was emitted as
            //   "status" USER-DEFINED
            // which is a syntax error, so no table using an enum could be
            // created — and enums are ubiquitous in Supabase schemas.
            'USER-DEFINED'     => PostgresSchemaHelper::qualifiedUdt($col),
            default            => null,
        };
    }

    /**
     * Length and scale, which information_schema does report faithfully: it
     * leaves them null when no modifier was given.
     */
    private static function parameterisedType(array $col): string {
        $dataType = $col['data_type'];
        $result   = $dataType;

        if ($dataType === 'character varying' || $dataType === 'character') {
            $base   = ['character varying' => 'varchar', 'character' => 'char'][$dataType];
            $result = $col['character_maximum_length'] ? "$base({$col['character_maximum_length']})" : $base;
        } elseif ($dataType === 'numeric' || $dataType === 'decimal') {
            $p      = $col['numeric_precision'];
            $result = ($p !== null) ? "$dataType($p,{$col['numeric_scale']})" : $dataType;
        }

        return $result;
    }
}
