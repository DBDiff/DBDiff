<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/**
 * An object whose definition can only be changed by replacing it.
 *
 * PostgreSQL has no CREATE OR REPLACE for a materialised view, no way to retype
 * a composite's attributes in place, and no ALTER that turns one domain into
 * another. Enums and views could be amended in narrow cases but not in general,
 * so all of them are applied the same way: drop, then create from the new
 * definition. The kinds differ only in the keyword DROP takes.
 *
 * A sequence is deliberately not one of these. It carries a value, so it is
 * altered in place by AlterSequenceSQL rather than recreated, which would reset
 * the counter.
 */
abstract class AbstractRecreateSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    /** The object keyword DROP takes, e.g. TYPE or MATERIALIZED VIEW. */
    abstract protected function dropKeyword(): string;

    public function getUp(): string {
        return $this->recreate($this->obj->sourceDefinition);
    }

    public function getDown(): string {
        return $this->recreate($this->obj->targetDefinition);
    }

    private function recreate(string $definition): string {
        $quoted = $this->dialect->quote($this->obj->name);
        return 'DROP ' . $this->dropKeyword() . " IF EXISTS $quoted;\n" . $definition . ';';
    }
}
