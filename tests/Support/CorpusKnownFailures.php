<?php

use PHPUnit\Framework\AssertionFailedError;

/**
 * Known failures for the corpus suites, from tests/pg-conformance/corpus-known-failures.json.
 *
 * A case listed there must still fail, and one that starts passing fails the
 * suite until it is removed — so the list can only shrink, and a corpus
 * release with cases DBDiff cannot pass yet can be taken without hiding them.
 */
trait CorpusKnownFailures
{
    /** Run a case's assertions, holding a listed case to still failing. */
    protected function expectingKnownFailures(string $corpus, callable $case): void
    {
        $file = dirname(__DIR__) . '/pg-conformance/corpus-known-failures.json';
        $known = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)[$corpus] ?? [];
        $reason = $known[$this->dataName()] ?? null;

        if ($reason === null) {
            $case();
            return;
        }
        try {
            $case();
        } catch (AssertionFailedError | PDOException | RuntimeException $e) {
            // Still failing, as recorded.
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail("{$this->dataName()} now passes; remove it from corpus-known-failures.json ($reason)");
    }
}
