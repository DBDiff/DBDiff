<?php namespace DBDiff\SQLGen\DiffToSQL;

/**
 * A changed object is dropped and created again — or, where both sides say
 * how to change it in place and agree on what cannot change (`same`, a text
 * search configuration's parser), cleared and filled where it stands, so
 * what uses it (an index over a configuration) is left alone. `sticky` marks
 * something set that only replacing takes away (a foreign server's version).
 */
abstract class ReplaceSchemaObjectSQL extends SchemaObjectSQL {

    public function getUp(): string {
        return $this->change($this->obj->targetInPlace, $this->obj->sourceInPlace)
            ?? self::statement($this->obj->targetDrop) . "\n" . self::statement($this->obj->sourceDefinition);
    }

    public function getDown(): string {
        return $this->change($this->obj->sourceInPlace, $this->obj->targetInPlace)
            ?? self::statement($this->obj->sourceDrop) . "\n" . self::statement($this->obj->targetDefinition);
    }

    private function change(?array $from, ?array $to): ?string {
        if ($from === null || $to === null || $from['same'] !== $to['same']
            // Something set that only replacing can take away.
            || (!empty($from['sticky']) && empty($to['sticky']))) {
            return null;
        }
        return implode("\n", array_map([self::class, 'statement'], array_filter([$from['clear'], $to['fill']])));
    }
}
