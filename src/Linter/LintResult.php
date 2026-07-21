<?php namespace DBDiff\Linter;

/** Aggregate of all LintViolations produced by a single linter run. */
class LintResult {
    /** @var LintViolation[] */
    private array $violations;

    /** @param LintViolation[] $violations */
    public function __construct(array $violations = []) {
        $this->violations = $violations;
    }

    /** @return LintViolation[] */
    public function getViolations(): array {
        return $this->violations;
    }

    public function hasErrors(): bool {
        foreach ($this->violations as $v) {
            if ($v->level === 'error') {
                return true;
            }
        }
        return false;
    }

    public function hasWarnings(): bool {
        foreach ($this->violations as $v) {
            if ($v->level === 'warning') {
                return true;
            }
        }
        return false;
    }

    public function isEmpty(): bool {
        return empty($this->violations);
    }

    /** @return LintViolation[] */
    public function getErrors(): array {
        return array_values(array_filter($this->violations, fn($v) => $v->level === 'error'));
    }

    /** @return LintViolation[] */
    public function getWarnings(): array {
        return array_values(array_filter($this->violations, fn($v) => $v->level === 'warning'));
    }
}
