<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableChangeColumnSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $newDef = $this->obj->diff->getNewValue();
        $oldDef = $this->obj->diff->getOldValue();
        return $this->aroundDependentViews(
            $this->dialect->changeColumn($this->obj->table, $this->obj->column, $newDef, $oldDef)
        );
    }

    public function getDown(): string {
        $oldDef = $this->obj->diff->getOldValue();
        $newDef = $this->obj->diff->getNewValue();
        return $this->aroundDependentViews(
            $this->dialect->changeColumn($this->obj->table, $this->obj->column, $oldDef, $newDef)
        );
    }

    /**
     * Wrap a column change in the drop and recreate of the views that read it.
     *
     * PostgreSQL refuses to retype a column any view depends on:
     *
     *     ERROR:  cannot alter type of a column used by a view or rule
     *     DETAIL: rule _RETURN on materialized view customer_totals depends on
     *             column "amount"
     *
     * so the statement was valid SQL that could not run (issue #226). The
     * dependants stand aside and go back exactly as they were — the views are
     * not what changed.
     *
     * Only a type change needs this. `SET NOT NULL`, `DROP DEFAULT` and the
     * rest are allowed with views in place, and dropping a view to run one
     * would be destructive for no reason.
     *
     * Deepest first on the way down and shallowest first on the way back:
     * a view built on another view has to go before the one it reads, and come
     * back after it.
     */
    private function aroundDependentViews(string $statements): string {
        $dependants = $this->obj->dependentViews ?? [];
        if ($dependants === [] || !self::changesType($statements)) {
            return $statements;
        }

        $deepestFirst = $dependants;
        usort($deepestFirst, static fn($a, $b) => $b['depth'] <=> $a['depth']);
        $shallowestFirst = array_reverse($deepestFirst);

        $lines = [];
        foreach ($deepestFirst as $view) {
            $lines[] = 'DROP ' . self::keyword($view['kind']) . ' IF EXISTS '
                . $this->dialect->quote($view['name']) . ';';
        }

        $lines[] = $statements;

        foreach ($shallowestFirst as $view) {
            $lines[] = 'CREATE ' . self::keyword($view['kind']) . ' '
                . $this->dialect->quote($view['name']) . ' AS ' . $view['definition'] . ';';
            // A matview's indexes go with it, and are not recreated by anything
            // else — the index path never sees a matview.
            foreach ($view['indexes'] as $indexDef) {
                $lines[] = rtrim($indexDef, ';') . ';';
            }
        }

        return implode("\n", $lines);
    }

    /** Whether any of these statements retypes a column. */
    private static function changesType(string $statements): bool {
        return (bool) preg_match('/\bALTER\s+COLUMN\s+\S+\s+TYPE\b/i', $statements);
    }

    /** MATERIALIZED VIEW for relkind 'm', VIEW otherwise. */
    private static function keyword(string $relkind): string {
        return $relkind === 'm' ? 'MATERIALIZED VIEW' : 'VIEW';
    }
}
