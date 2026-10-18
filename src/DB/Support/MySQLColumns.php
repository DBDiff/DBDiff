<?php namespace DBDiff\DB\Support;

/** MySQL column definitions, as compared. */
final class MySQLColumns {

    /**
     * A column definition as compared, not as written. A collation names its
     * character set, and MySQL prints the set beside it or not depending on
     * how the column came to have it: `COLLATE utf8mb4_unicode_ci` on one
     * that took the table's, `CHARACTER SET utf8mb4 COLLATE
     * utf8mb4_unicode_ci` once it was altered. Read apart, the CHANGE that
     * set it was offered again after every run.
     */
    public static function comparable(string $def): string {
        return preg_replace('/\bCHARACTER SET (\w+) (COLLATE \1_\w+)/i', '$2', $def);
    }
}
