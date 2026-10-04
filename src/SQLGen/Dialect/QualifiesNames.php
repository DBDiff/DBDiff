<?php namespace DBDiff\SQLGen\Dialect;

/**
 * Naming an object in the schema a change is in.
 *
 * A migration is applied with `public` on the search path, so an object there
 * is written as it always has been, by its name alone; one in any other
 * schema is written in full. Columns, constraints, triggers, policies and
 * indexes being created belong to their table and are never qualified.
 */
trait QualifiesNames {

    private ?string $schema = null;

    /** This dialect, naming objects in the given schema. */
    public function inSchema(?string $schema): static {
        $copy = clone $this;
        $copy->schema = $schema;
        return $copy;
    }

    /** A table, view, type, sequence or other schema object's name, quoted. */
    public function qualify(string $name): string {
        return $this->schema === null || $this->schema === 'public'
            ? $this->quote($name)
            : $this->quote($this->schema) . '.' . $this->quote($name);
    }
}
