<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffSorter;

/**
 * Structural invariants every diff kind has to satisfy.
 *
 * Both are things a new diff kind gets wrong silently rather than loudly:
 *
 * DiffSorter looks its priority up with $orderMap[$shortName] and no fallback,
 * so a kind missing from either order list raises "Undefined array key" the
 * first time a diff happens to contain it — which may be long after the code
 * was added, and only for the schemas that trigger it.
 *
 * MigrationGenerator resolves the SQL writer by name, appending "SQL" to the
 * diff's short name, so a missing counterpart is a "class not found" at
 * generation time rather than anything a type checker would catch.
 *
 * Asserted by walking src/Diff rather than a hand-kept list, so a kind added
 * without either piece fails here instead of in the field.
 */
class DiffClassRegistrationTest extends TestCase
{
    /** @return string[] */
    private function diffClassNames(): array
    {
        $names = [];
        foreach (glob(__DIR__ . '/../../src/Diff/*.php') as $file) {
            $names[] = basename($file, '.php');
        }
        sort($names);

        return $names;
    }

    private function order(string $property): array
    {
        $reflected = new \ReflectionProperty(DiffSorter::class, $property);
        $reflected->setAccessible(true);

        return $reflected->getValue(new DiffSorter());
    }

    public function testThereAreDiffClassesToCheck(): void
    {
        // Guards the assertions below against a glob that silently finds none.
        $this->assertGreaterThan(20, count($this->diffClassNames()));
    }

    public function testEveryDiffClassHasAnUpOrderPriority(): void
    {
        $missing = array_diff($this->diffClassNames(), $this->order('up_order'));

        $this->assertSame(
            [],
            array_values($missing),
            'diff kinds missing from DiffSorter::$up_order: ' . implode(', ', $missing)
        );
    }

    public function testEveryDiffClassHasADownOrderPriority(): void
    {
        $missing = array_diff($this->diffClassNames(), $this->order('down_order'));

        $this->assertSame(
            [],
            array_values($missing),
            'diff kinds missing from DiffSorter::$down_order: ' . implode(', ', $missing)
        );
    }

    public function testEveryDiffClassHasASqlCounterpart(): void
    {
        $missing = [];
        foreach ($this->diffClassNames() as $name) {
            $path = __DIR__ . '/../../src/SQLGen/DiffToSQL/' . $name . 'SQL.php';
            if (!is_file($path)) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'diff kinds with no DiffToSQL counterpart: ' . implode(', ', $missing)
        );
    }

    public function testOrderListsNameOnlyRealDiffClasses(): void
    {
        $known = $this->diffClassNames();
        foreach (['up_order', 'down_order'] as $property) {
            $unknown = array_diff($this->order($property), $known);
            $this->assertSame(
                [],
                array_values($unknown),
                "DiffSorter::\$$property names classes that do not exist: "
                . implode(', ', $unknown)
            );
        }
    }
}
