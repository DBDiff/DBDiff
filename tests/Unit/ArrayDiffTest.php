<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\DB\Data\ArrayDiff;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;

/**
 * Tests that ArrayDiff works correctly with plain PHP arrays
 * (verifies the fix for Bug #4 where Collection objects were passed
 * to array_merge).
 */
class ArrayDiffTest extends TestCase
{
    protected function tearDown(): void
    {
        \DBDiff\Params\ParamsFactory::reset();
    }

    /**
     * Minimal stub that mimics TableIterator but returns plain arrays.
     */
    private function makeIterator(array $batches): object
    {
        return new class($batches) {
            private array $batches;
            private int $index = 0;

            public function __construct(array $batches)
            {
                $this->batches = $batches;
            }

            public function hasNext(): bool
            {
                return $this->index < count($this->batches);
            }

            public function next(int $size): array
            {
                return $this->batches[$this->index++] ?? [];
            }
        };
    }

    public function testIdenticalDataProducesNoDiff(): void
    {
        $rows = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];

        \DBDiff\Params\ParamsFactory::set(new \stdClass());

        $diff = new ArrayDiff(
            ['id'],
            $this->makeIterator([$rows]),
            $this->makeIterator([$rows])
        );

        $result = $diff->getDiff('test_table');
        $this->assertEmpty($result, 'Identical data should produce no diff');
    }

    public function testNewRowDetected(): void
    {
        $source = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];
        $target = [['id' => 1, 'name' => 'Alice']];

        \DBDiff\Params\ParamsFactory::set(new \stdClass());

        $diff = new ArrayDiff(
            ['id'],
            $this->makeIterator([$source]),
            $this->makeIterator([$target])
        );

        $result = $diff->getDiff('test_table');
        $this->assertCount(1, $result);
        $this->assertInstanceOf(DiffOpAdd::class, $result[0]['diff']);
    }

    public function testDeletedRowDetected(): void
    {
        $source = [['id' => 1, 'name' => 'Alice']];
        $target = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];

        \DBDiff\Params\ParamsFactory::set(new \stdClass());

        $diff = new ArrayDiff(
            ['id'],
            $this->makeIterator([$source]),
            $this->makeIterator([$target])
        );

        $result = $diff->getDiff('test_table');
        $this->assertCount(1, $result);
        $this->assertInstanceOf(DiffOpRemove::class, $result[0]['diff']);
    }

    public function testChangedRowDetected(): void
    {
        $source = [['id' => 1, 'name' => 'Alice Updated']];
        $target = [['id' => 1, 'name' => 'Alice']];

        \DBDiff\Params\ParamsFactory::set(new \stdClass());

        $diff = new ArrayDiff(
            ['id'],
            $this->makeIterator([$source]),
            $this->makeIterator([$target])
        );

        $result = $diff->getDiff('test_table');
        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]['diff']);
        $this->assertArrayHasKey('name', $result[0]['diff']);
    }
}
