<?php namespace DBDiff\Params;


class DefaultParams {
    
    // Specify the source db connection details. If there is only one
    public $server1 = [];

    // Specify the target db connection details
    public $server2 = [];

    public $format = 'sql';

    // Specifies the output template, if any. By default will be plain SQL
    public $template = '';

    // Specifies the type of diff to do either on the schema, data or both
    public $type = 'schema';

    // Specified whether to include the up, down or both data in the output
    public $include = 'up';

    /* 
     By default automated comments starting with the hash (#) character are
     included in the output file, which can be removed with this parameter
    */
    public $nocomments = false;

    /* 
     By default, DBDiff will look for a .dbdiff file in the current directory
     which is valid YAML, which may also be overridden with a config file that
     lists the database host, user, port and password of the source and target
     DBs in YAML format (instead of using the command line for it), or any of
     the other settings e.g. the format, template, type, include, no­comments.
     Please note: a command­line parameter will always override any config file.
    */
    public $config = null;

    /* 
     By default will output to the same directory the command is run in if no directory is
     specified. If a directory is specified, it should exist, otherwise an error will be thrown
    */
    public $output = null;

    /* 
     Enable or disable warnings
    */
    public $debug = false;

    /*
     The database driver to use. Supported: mysql (default), pgsql, sqlite.
     Use 'pgsql' for Postgres and Supabase connections.
    */
    public $driver = 'mysql';

    /*
     An optional description/comment included in the migration output header.
    */
    public $description = '';

    /*
     Optional SSL mode for Postgres connections (e.g. 'require', 'verify-ca').
     Populated automatically when a DSN URL contains a sslmode parameter.
     Null means "not set" — DBManager will leave the adapter default intact.
    */
    public ?string $sslmode = null;

    /*
     PHP memory limit applied at startup by the CLI entry points (dbdiff / dbdiff.php).
     Defaults to 1G. Override via --memory-limit=<value> on the command line or by
     setting memory_limit: <value> in your .dbdiff / dbdiff.yml config file.
     Accepts any PHP shorthand: 512M, 1G, 2G, -1 (unlimited), etc.
     Set to null to leave the system php.ini value unchanged.
    */
    public ?string $memoryLimit = null;

    /*
     The penultimate parameter is what to compare: db1.table1:db2.table3 or​ db1:db2 
     This tool can compare just one table or all tables (entire db) from the database
    */
    public $input = [];

}
