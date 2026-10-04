<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/** `COMMENT ON <object> IS ...`; see PostgresComments. */
class AlterCommentSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return self::comment($this->obj->name, $this->obj->up);
    }

    public function getDown(): string {
        return self::comment($this->obj->name, $this->obj->down);
    }

    private static function comment(string $on, ?string $value): string {
        return $value === null ? '' : "COMMENT ON $on IS $value;";
    }
}
