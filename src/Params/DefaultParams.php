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
     The penultimate parameter is what to compare: db1.table1:db2.table3 or​ db1:db2 
     This tool can compare just one table or all tables (entire db) from the database
    */
    public $input = [];

}
