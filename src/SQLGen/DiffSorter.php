<?php namespace DBDiff\SQLGen;


class DiffSorter {

    /**
     * Order slots that are not diff classes: a diff of the class on the right
     * sorts in the slot on the left when orderClass() says so.
     */
    public const SUB_SLOTS = [
        'CreateRoutineEarly' => 'CreateRoutine',
        'DropRoutineEarly'   => 'DropRoutine',
    ];

    private $up_order = [
        "SetDBCharset",
        "SetDBCollation",
        "CreateSchema",
        // Before anything that may use one: a type, an operator class.
        "CreateExtension",

        "DropView",
        // Both depend on tables, so they go before any table is touched.
        "DropMatView",
        "DropPolicy",
        "DropTrigger",

        "AlterTableDropConstraint",

        // Enums must exist before anything can reference them: a CREATE TABLE
        // with an enum column, or a column added or retyped to one, fails with
        // 'type "x" does not exist' when CREATE TYPE comes later in the file.
        // These previously sat after AddTable, alongside views and routines.
        "CreateEnum",
        "AlterEnum",

        // Same reason as enums: a column may be typed by a composite or a
        // domain, or default to nextval() of a sequence.
        "CreateCompositeType",
        "AlterCompositeType",
        "CreateDomain",
        "AlterDomain",
        "CreateSequence",
        // A routine a new table's or column's default, generated column,
        // constraint or index calls has to exist first (issue #238) — see
        // CreationOrderPlan. Any other routine stays with the programmable
        // objects below, after the tables its body may read.
        "CreateRoutineEarly",
        "AlterSequence",

        "AddTable",

        "DeleteData",
        "DropTable",

        "AlterTableEngine",
        "AlterTablePersistence",
        "AlterTableOptions",
        "AlterTableCollation",

        // An index goes before its column: DROP COLUMN ... CASCADE takes the
        // index with it, and a DROP INDEX after it would fail.
        "AlterTableDropKey",

        "AlterTableAddColumn",
        "AlterTableChangeColumn",
        // After a column is added or retyped: a type change resets storage.
        "AlterTableColumnStorage",
        "AlterTableDropColumn",

        "AlterTableAddKey",
        "AlterTableChangeKey",

        "AlterTableAddConstraint",
        "AlterTableChangeConstraint",

        "InsertData",
        "UpdateData",

        // Routines first: both a view and a trigger may call a function, and
        // creating either before the function exists fails with
        //   ERROR: function f() does not exist
        // CreateRoutine used to sit last, after the objects depending on it.
        "CreateRoutine",
        "AlterRoutine",
        "CreateView",
        "AlterView",
        // After views: a materialised view may select from one.
        "CreateMatView",
        "AlterMatView",
        "CreateTrigger",
        "AlterTrigger",

        // Last: both need their table to exist, and enabling RLS without the
        // policies would leave the table denying every row.
        "AlterRowSecurity",
        "CreatePolicy",
        "AlterPolicy",

        // After everything is made or remade; what is dropped needs none.
        "AlterComment",

        // Very last: what the source no longer has, once nothing uses it. The
        // tables, columns, defaults, constraints, views, triggers and policies
        // that called a routine or were typed by a type have been dropped or
        // changed above; dropping these first failed with "cannot drop ...
        // because other objects depend on it" (issue #238). Dependants first:
        // a routine can take a type, a composite can hold a domain, and a
        // domain or a composite can be built on an enum.
        "DropRoutine",
        "DropRoutineEarly",
        "DropSequence",
        "DropCompositeType",
        "DropDomain",
        "DropEnum",
        // After everything that used it, before its schema.
        "DropExtension",
        "DropSchema",
    ];

    private $down_order = [
        "SetDBCharset",
        "SetDBCollation",
        "DropSchema",
        "DropExtension",

        // Before the routines, views, policies and triggers the DOWN puts
        // back: they may name a label it restores, and a label swap takes
        // their current versions aside and back (EnumSwapPlan).
        "AlterEnum",

        // What the UP dropped is recreated in the order the UP would create
        // it (issue #238): the types first, and a routine a table's default,
        // constraint or index calls, so the tables and columns below can use
        // them...
        "DropEnum",
        "DropDomain",
        "DropCompositeType",
        "DropSequence",
        "DropRoutineEarly",

        "AlterRoutine",
        // Policies and the RLS flags come off before the tables they sit on.
        "AlterPolicy",
        "CreatePolicy",
        "AlterRowSecurity",
        "AlterTrigger",
        "CreateTrigger",
        "AlterMatView",
        "CreateMatView",
        "AlterView",
        "CreateView",
        "AlterCompositeType",
        "AlterDomain",
        "AlterSequence",

        "AlterTableAddConstraint",
        "AlterTableChangeConstraint",

        "InsertData",
        "AddTable",

        "DropTable",

        "AlterTableEngine",
        "AlterTablePersistence",
        "AlterTableOptions",
        "AlterTableCollation",

        // Undoing an added index before undoing its added column (see UP).
        "AlterTableAddKey",

        "AlterTableAddColumn",
        "AlterTableChangeColumn",
        // After a column is added or retyped: a type change resets storage.
        "AlterTableColumnStorage",
        "AlterTableDropColumn",

        "AlterTableChangeKey",
        "AlterTableDropKey",

        "AlterTableDropConstraint",

        "DeleteData",
        "UpdateData",

        // ...and, once the tables are back, the routines whose bodies may read
        // them, then the views, triggers and policies that read or call them.
        "DropRoutine",
        "DropView",
        "DropMatView",
        "DropTrigger",
        "DropPolicy",

        // What the UP created goes last, once nothing reverted above still
        // uses it: a routine after the triggers, policies, views, defaults and
        // columns that call it; then the types, dependants first — a composite
        // or a domain can be built on an enum. Dropping them earlier failed
        // with "cannot drop ... because other objects depend on it" — the
        // DOWN of any new type, domain or sequence with a new column using it,
        // and of any new routine a reverted view or default still called
        // (issue #238).
        "CreateRoutine",
        "CreateRoutineEarly",
        "CreateCompositeType",
        "CreateDomain",
        "CreateEnum",
        "CreateSequence",
        "CreateExtension",
        "CreateSchema",
        // Last: after everything DOWN puts back.
        "AlterComment",
    ];

    public function sort($diff, $type) {
        usort($diff, [$this, 'compare'.ucfirst($type)]);
        return $diff;
    }
    
    private function compareUp($a, $b) {
        return $this->compare($this->up_order, $a, $b, 'up');
    }

    private function compareDown($a, $b) {
        return $this->compare($this->down_order, $a, $b, 'down');
    }

    private function compare($order, $a, $b, string $direction = 'up'): int {
        $orderMap     = array_flip($order);
        $sqlGenClassA = self::orderClass($a);
        $sqlGenClassB = self::orderClass($b);
        $indexA = $orderMap[$sqlGenClassA];
        $indexB = $orderMap[$sqlGenClassB];
        if ($indexA !== $indexB) {
            $override = $this->generatedColumnOrdering($a, $b, $sqlGenClassA, $sqlGenClassB, $direction);
            return $override ?? ($indexA <=> $indexB);
        }
        return $this->compareSamePriority($a, $b, $direction, $sqlGenClassA);
    }

    /** The class name a diff sorts as; an early routine has its own slot (SUB_SLOTS). */
    private static function orderClass(object $diff): string {
        $class = (new \ReflectionClass($diff))->getShortName();
        return in_array($class, ['CreateRoutine', 'DropRoutine'], true) && !empty($diff->early)
            ? $class . 'Early'
            : $class;
    }

    private function generatedColumnOrdering($a, $b, string $classA, string $classB, string $direction): ?int {
        if ($direction !== 'up') {
            return null;
        }
        return $this->generatedColumnPairOrder($a, $classA, $classB)
            ?? $this->generatedColumnPairOrder($b, $classB, $classA, true);
    }

    private function generatedColumnPairOrder($x, string $classX, string $classOther, bool $invert = false): ?int {
        if ($classOther !== 'AlterTableChangeColumn') {
            return null;
        }
        $rank = null;
        if ($classX === 'AlterTableDropColumn' && !empty($x->isGeneratedDep)) {
            $rank = -1;
        } elseif ($classX === 'AlterTableAddColumn' && !empty($x->isGenerated)) {
            $rank = 1;
        }
        if ($rank === null) {
            return null;
        }
        return $invert ? -$rank : $rank;
    }

    private function compareSamePriority($a, $b, string $direction, string $sqlGenClassA): int {
        $sortA = $a->sortOrder ?? null;
        $sortB = $b->sortOrder ?? null;
        if ($sortA === null || $sortB === null || $sortA === $sortB) {
            return $this->compareByName($a, $b);
        }
        if ($sqlGenClassA === 'AlterTablePersistence') {
            return self::comparePersistenceRank($a, $b, $direction);
        }
        // CREATE: ascending (parents first); DROP: descending (children first)
        $isCreate = ($direction === 'up'   && $sqlGenClassA === 'AddTable')
                 || ($direction === 'down'  && $sqlGenClassA === 'DropTable');
        return $isCreate ? ($sortA <=> $sortB) : ($sortB <=> $sortA);
    }

    /**
     * LOGGED/UNLOGGED changes by foreign-key rank, which is parents-first.
     *
     * Becoming LOGGED goes parents-first (a logged table cannot reference an
     * unlogged one); becoming UNLOGGED goes children-first, for the same
     * reason. A change of each kind is independent of the other, so any fixed
     * order between them will do.
     */
    private static function comparePersistenceRank($a, $b, string $direction): int {
        $becomesLogged = fn($d) => !($direction === 'up' ? $d->unlogged : $d->prevUnlogged);
        if ($becomesLogged($a) !== $becomesLogged($b)) {
            return $becomesLogged($a) ? -1 : 1;
        }
        return $becomesLogged($a)
            ? ($a->sortOrder <=> $b->sortOrder)
            : ($b->sortOrder <=> $a->sortOrder);
    }

    private function compareByName($a, $b): int {
        $tableCmp = strcmp($a->schema ?? '', $b->schema ?? '') ?: strcmp($a->table ?? '', $b->table ?? '');
        if ($tableCmp !== 0) {
            return $tableCmp;
        }

        return $this->compareWithinTable($a, $b);
    }

    private function compareWithinTable($a, $b): int {
        $genA = !empty($a->isGenerated);
        $genB = !empty($b->isGenerated);
        if ($genA !== $genB) {
            $classA = (new \ReflectionClass($a))->getShortName();
            $generatedFirst = $classA !== 'AlterTableAddColumn';
            return ($genA === $generatedFirst) ? -1 : 1;
        }

        $ordA = $a->ordinal ?? null;
        $ordB = $b->ordinal ?? null;
        if ($ordA !== null && $ordB !== null && $ordA !== $ordB) {
            return $ordA <=> $ordB;
        }

        $itemA  = $a->column ?? $a->key ?? $a->name ?? '';
        $itemB  = $b->column ?? $b->key ?? $b->name ?? '';
        return strcmp((string) $itemA, (string) $itemB)
            ?: $this->compareDataKeys($a, $b);
    }

    private function compareDataKeys($a, $b): int {
        if (is_array($a->diff) && isset($a->diff['keys']) && is_array($b->diff) && isset($b->diff['keys'])) {
            return strcmp(json_encode($a->diff['keys']), json_encode($b->diff['keys']));
        }
        return 0;
    }
}
