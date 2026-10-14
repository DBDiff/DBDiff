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
        return $this->recreate($this->obj->sourceDefinition, 'up');
    }

    public function getDown(): string {
        return $this->recreate($this->obj->targetDefinition, 'down');
    }

    private function recreate(string $definition, string $direction): string {
        $quoted = $this->dialect->qualify($this->obj->name);
        $privileges = $this->obj->privileges ?? null;

        $inPlace = $this->inPlace($definition, $direction, $quoted, $privileges);
        if ($inPlace !== null) {
            return implode("\n", $inPlace);
        }

        // What reads the object comes off first and goes back once it exists
        // again — see ViewReplacementPlan.
        $replacement = $this->obj->replacement[$direction] ?? null;
        $lines = $replacement !== null ? (new ColumnDependantsSQL($replacement['drop']))->drops() : [];
        array_push($lines, 'DROP ' . $this->dropKeyword() . " IF EXISTS $quoted;", $definition . ';');
        // A view's grants go with it; put back the ones this side had.
        if ($privileges !== null) {
            array_push($lines, ...GrantSQL::restore($quoted, $privileges[$direction], $privileges['owner'], $privileges['defaultGrantees']));
        }
        if ($replacement !== null) {
            foreach ($replacement['triggers'] as $trigger) {
                $lines[] = rtrim(trim($trigger['definition']), ';') . ';';
            }
            array_push($lines, ...(new ColumnDependantsSQL($replacement['recreate'], $replacement['skip'], $direction))->recreates());
        }
        return implode("\n", $lines);
    }

    /**
     * The statements that change the object without dropping it, or null when
     * it has to be replaced.
     *
     * @return string[]|null
     */
    protected function inPlace(string $definition, string $direction, string $quoted, ?array $privileges): ?array {
        return null;
    }
}
