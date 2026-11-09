<?php namespace DBDiff\SQLGen;

use DBDiff\Migration\Runner\SqlStatementSplitter;

/**
 * Writes MySQL routines, triggers and events for the mysql client.
 *
 * The client ends a statement at the first `;` it sees, so a procedure body
 * reaches the server in pieces. mysqldump's answer, and the one the client
 * documents, is to change the delimiter around such a statement:
 *
 *     DELIMITER $$
 *     CREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END$$
 *     DELIMITER ;
 *
 * DBDiff's own runner reads DELIMITER lines too (SqlStatementSplitter), so a
 * migration written this way applies either way. Other statements are left
 * exactly as they were.
 */
final class MySqlDelimiter
{
    public static function wrap(string $sql, string $delimiter): string
    {
        $statements = SqlStatementSplitter::split($sql, 'mysql');
        if (!array_filter($statements, [SqlStatementSplitter::class, 'isRoutine'])) {
            return $sql;
        }
        $out = '';
        foreach ($statements as $statement) {
            if (SqlStatementSplitter::isRoutine($statement)) {
                $out .= "DELIMITER $delimiter\n$statement$delimiter\nDELIMITER ;\n";
            } elseif (self::onlyComments($statement)) {
                $out .= "$statement\n";
            } else {
                $out .= "$statement;\n";
            }
        }
        return rtrim($out, "\n");
    }

    private static function onlyComments(string $statement): bool
    {
        return trim(preg_replace(['~/\*.*?\*/~s', '/--[^\n]*/', '/#[^\n]*/'], '', $statement)) === '';
    }
}
