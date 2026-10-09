<?php namespace DBDiff\DB\Support;

/**
 * `information_schema.columns`, without the privilege filter.
 *
 * The view lists a column only when the connecting role owns its table or
 * holds a privilege on the column. A source reached as a role that can see a
 * table but not read it — a natural choice for a "read-only" connection —
 * therefore had no columns at all, and every column of the target looked
 * extra: the diff proposed `DROP COLUMN … CASCADE` for each one.
 *
 * This is the view's own definition, the same on every supported PostgreSQL
 * version, with only that last predicate taken out, so every value it
 * produces — `data_type`, identity and generation details — is exactly what
 * the view would have returned to a role allowed to see the column. The
 * catalogs it reads are readable by every role.
 */
final class PostgresCatalogColumns
{
    /** A subquery to use in place of `information_schema.columns`; alias it. */
    public const SQL = <<<'SQL'
    (
        SELECT current_database()::information_schema.sql_identifier AS table_catalog,
            nc.nspname::information_schema.sql_identifier AS table_schema,
            c.relname::information_schema.sql_identifier AS table_name,
            a.attname::information_schema.sql_identifier AS column_name,
            a.attnum::information_schema.cardinal_number AS ordinal_position,
                CASE
                    WHEN a.attgenerated = ''::"char" THEN pg_get_expr(ad.adbin, ad.adrelid)
                    ELSE NULL::text
                END::information_schema.character_data AS column_default,
                CASE
                    WHEN a.attnotnull OR t.typtype = 'd'::"char" AND t.typnotnull THEN 'NO'::text
                    ELSE 'YES'::text
                END::information_schema.yes_or_no AS is_nullable,
                CASE
                    WHEN t.typtype = 'd'::"char" THEN
                    CASE
                        WHEN bt.typelem <> 0::oid AND bt.typlen = '-1'::integer THEN 'ARRAY'::text
                        WHEN nbt.nspname = 'pg_catalog'::name THEN format_type(t.typbasetype, NULL::integer)
                        ELSE 'USER-DEFINED'::text
                    END
                    ELSE
                    CASE
                        WHEN t.typelem <> 0::oid AND t.typlen = '-1'::integer THEN 'ARRAY'::text
                        WHEN nt.nspname = 'pg_catalog'::name THEN format_type(a.atttypid, NULL::integer)
                        ELSE 'USER-DEFINED'::text
                    END
                END::information_schema.character_data AS data_type,
            information_schema._pg_char_max_length(information_schema._pg_truetypid(a.*, t.*), information_schema._pg_truetypmod(a.*, t.*))::information_schema.cardinal_number AS character_maximum_length,
            information_schema._pg_char_octet_length(information_schema._pg_truetypid(a.*, t.*), information_schema._pg_truetypmod(a.*, t.*))::information_schema.cardinal_number AS character_octet_length,
            information_schema._pg_numeric_precision(information_schema._pg_truetypid(a.*, t.*), information_schema._pg_truetypmod(a.*, t.*))::information_schema.cardinal_number AS numeric_precision,
            information_schema._pg_numeric_precision_radix(information_schema._pg_truetypid(a.*, t.*), information_schema._pg_truetypmod(a.*, t.*))::information_schema.cardinal_number AS numeric_precision_radix,
            information_schema._pg_numeric_scale(information_schema._pg_truetypid(a.*, t.*), information_schema._pg_truetypmod(a.*, t.*))::information_schema.cardinal_number AS numeric_scale,
            information_schema._pg_datetime_precision(information_schema._pg_truetypid(a.*, t.*), information_schema._pg_truetypmod(a.*, t.*))::information_schema.cardinal_number AS datetime_precision,
            information_schema._pg_interval_type(information_schema._pg_truetypid(a.*, t.*), information_schema._pg_truetypmod(a.*, t.*))::information_schema.character_data AS interval_type,
            NULL::integer::information_schema.cardinal_number AS interval_precision,
            NULL::name::information_schema.sql_identifier AS character_set_catalog,
            NULL::name::information_schema.sql_identifier AS character_set_schema,
            NULL::name::information_schema.sql_identifier AS character_set_name,
                CASE
                    WHEN nco.nspname IS NOT NULL THEN current_database()
                    ELSE NULL::name
                END::information_schema.sql_identifier AS collation_catalog,
            nco.nspname::information_schema.sql_identifier AS collation_schema,
            co.collname::information_schema.sql_identifier AS collation_name,
                CASE
                    WHEN t.typtype = 'd'::"char" THEN current_database()
                    ELSE NULL::name
                END::information_schema.sql_identifier AS domain_catalog,
                CASE
                    WHEN t.typtype = 'd'::"char" THEN nt.nspname
                    ELSE NULL::name
                END::information_schema.sql_identifier AS domain_schema,
                CASE
                    WHEN t.typtype = 'd'::"char" THEN t.typname
                    ELSE NULL::name
                END::information_schema.sql_identifier AS domain_name,
            current_database()::information_schema.sql_identifier AS udt_catalog,
            COALESCE(nbt.nspname, nt.nspname)::information_schema.sql_identifier AS udt_schema,
            COALESCE(bt.typname, t.typname)::information_schema.sql_identifier AS udt_name,
            NULL::name::information_schema.sql_identifier AS scope_catalog,
            NULL::name::information_schema.sql_identifier AS scope_schema,
            NULL::name::information_schema.sql_identifier AS scope_name,
            NULL::integer::information_schema.cardinal_number AS maximum_cardinality,
            a.attnum::information_schema.sql_identifier AS dtd_identifier,
            'NO'::character varying::information_schema.yes_or_no AS is_self_referencing,
                CASE
                    WHEN a.attidentity = ANY (ARRAY['a'::"char", 'd'::"char"]) THEN 'YES'::text
                    ELSE 'NO'::text
                END::information_schema.yes_or_no AS is_identity,
                CASE a.attidentity
                    WHEN 'a'::"char" THEN 'ALWAYS'::text
                    WHEN 'd'::"char" THEN 'BY DEFAULT'::text
                    ELSE NULL::text
                END::information_schema.character_data AS identity_generation,
            seq.seqstart::information_schema.character_data AS identity_start,
            seq.seqincrement::information_schema.character_data AS identity_increment,
            seq.seqmax::information_schema.character_data AS identity_maximum,
            seq.seqmin::information_schema.character_data AS identity_minimum,
                CASE
                    WHEN seq.seqcycle THEN 'YES'::text
                    ELSE 'NO'::text
                END::information_schema.yes_or_no AS identity_cycle,
                CASE
                    WHEN a.attgenerated <> ''::"char" THEN 'ALWAYS'::text
                    ELSE 'NEVER'::text
                END::information_schema.character_data AS is_generated,
                CASE
                    WHEN a.attgenerated <> ''::"char" THEN pg_get_expr(ad.adbin, ad.adrelid)
                    ELSE NULL::text
                END::information_schema.character_data AS generation_expression,
                CASE
                    WHEN (c.relkind = ANY (ARRAY['r'::"char", 'p'::"char"])) OR (c.relkind = ANY (ARRAY['v'::"char", 'f'::"char"])) AND pg_column_is_updatable(c.oid::regclass, a.attnum, false) THEN 'YES'::text
                    ELSE 'NO'::text
                END::information_schema.yes_or_no AS is_updatable
           FROM pg_attribute a
             LEFT JOIN pg_attrdef ad ON a.attrelid = ad.adrelid AND a.attnum = ad.adnum
             JOIN (pg_class c
             JOIN pg_namespace nc ON c.relnamespace = nc.oid) ON a.attrelid = c.oid
             JOIN (pg_type t
             JOIN pg_namespace nt ON t.typnamespace = nt.oid) ON a.atttypid = t.oid
             LEFT JOIN (pg_type bt
             JOIN pg_namespace nbt ON bt.typnamespace = nbt.oid) ON t.typtype = 'd'::"char" AND t.typbasetype = bt.oid
             LEFT JOIN (pg_collation co
             JOIN pg_namespace nco ON co.collnamespace = nco.oid) ON a.attcollation = co.oid AND (nco.nspname <> 'pg_catalog'::name OR co.collname <> 'default'::name)
             LEFT JOIN (pg_depend dep
             JOIN pg_sequence seq ON dep.classid = 'pg_class'::regclass::oid AND dep.objid = seq.seqrelid AND dep.deptype = 'i'::"char") ON dep.refclassid = 'pg_class'::regclass::oid AND dep.refobjid = c.oid AND dep.refobjsubid = a.attnum
          WHERE NOT pg_is_other_temp_schema(nc.oid) AND a.attnum > 0 AND NOT a.attisdropped AND (c.relkind = ANY (ARRAY['r'::"char", 'v'::"char", 'f'::"char", 'p'::"char"]))
    )
    SQL;
}
