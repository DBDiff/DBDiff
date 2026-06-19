<?php namespace DBDiff\Exceptions;

use DBDiff\Linter\LintResult;

/**
 * Thrown by DBDiff::getDiffResult() when the diff contains destructive schema
 * changes and $params->allowDestructive is false (the default).
 *
 * The exception message is suitable for direct display to the user.
 * Pass --allow-destructive on the CLI to suppress this exception.
 */
class DestructiveChangeException extends BaseException {
    private LintResult $lintResult;

    public function __construct(LintResult $lintResult) {
        $this->lintResult = $lintResult;

        $errors   = $lintResult->getErrors();
        $warnings = $lintResult->getWarnings();
        $parts    = [];
        if (!empty($errors)) {
            $parts[] = count($errors) . ' destructive error(s)';
        }
        if (!empty($warnings)) {
            $parts[] = count($warnings) . ' warning(s)';
        }

        $lines = ['Destructive changes detected — ' . implode(', ', $parts) . ':'];
        foreach ($errors as $v) {
            $lines[] = "  [error]   {$v->type}: {$v->object}";
            if ($v->suggestion) {
                $lines[] = "             → {$v->suggestion}";
            }
        }
        foreach ($warnings as $v) {
            $lines[] = "  [warning] {$v->type}: {$v->object}";
        }
        $lines[] = '';
        $lines[] = 'Re-run with --allow-destructive to generate the migration anyway.';

        parent::__construct(implode("\n", $lines));
    }

    public function getLintResult(): LintResult {
        return $this->lintResult;
    }
}
