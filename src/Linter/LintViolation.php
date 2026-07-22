<?php namespace DBDiff\Linter;

/** Value object representing a single linting violation. */
class LintViolation {
    /** @var string 'error' or 'warning' */
    public string $level;

    /** @var string Violation type slug, e.g. 'drop-table', 'drop-column'. */
    public string $type;

    /** @var string Human-readable object description, e.g. "table `orders`". */
    public string $object;

    /** @var string The SQL statement that triggered this violation. */
    public string $sql;

    /** @var string Optional remediation hint shown in the error message. */
    public string $suggestion;

    public function __construct(
        string $level,
        string $type,
        string $object,
        string $sql,
        string $suggestion = ''
    ) {
        $this->level      = $level;
        $this->type       = $type;
        $this->object     = $object;
        $this->sql        = $sql;
        $this->suggestion = $suggestion;
    }
}
