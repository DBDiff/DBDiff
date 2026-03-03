<?php namespace DBDiff\Migration\Format;

/**
 * FormatRegistry maps --format string values to FormatInterface implementations.
 *
 * Supported format keys:
 *   native          - Default DBDiff SQL file with UP/DOWN sections
 *   flyway          - Flyway V{ts}__{desc}.sql (UP) + optional U{ts}__{desc}.sql (DOWN)
 *   liquibase-xml   - Liquibase changelog.xml with <changeSet> blocks
 *   liquibase-yaml  - Liquibase changelog.yaml with changeSet blocks
 *   laravel         - Laravel anonymous Migration class (PHP file)
 */
class FormatRegistry
{
    /** @var array<string, FormatInterface> */
    private static array $formats = [];

    public static function boot(): void
    {
        self::$formats = [
            'native'         => new NativeFormat(),
            'flyway'         => new FlywayFormat(),
            'liquibase-xml'  => new LiquibaseXmlFormat(),
            'liquibase-yaml' => new LiquibaseYamlFormat(),
            'laravel'        => new LaravelFormat(),
        ];
    }

    /**
     * Retrieve a format by key.
     *
     * @throws \InvalidArgumentException for unknown format keys
     */
    public static function get(string $key): FormatInterface
    {
        if (empty(self::$formats)) {
            self::boot();
        }

        $key = strtolower(trim($key));

        if (!isset(self::$formats[$key])) {
            throw new \InvalidArgumentException(
                "Unknown format '{$key}'. Supported formats: " . implode(', ', array_keys(self::$formats))
            );
        }

        return self::$formats[$key];
    }

    /**
     * Returns all registered format keys.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        if (empty(self::$formats)) {
            self::boot();
        }

        return array_keys(self::$formats);
    }

    /**
     * Returns a display-friendly list for help text.
     */
    public static function describe(): string
    {
        if (empty(self::$formats)) {
            self::boot();
        }

        $lines = [];
        foreach (self::$formats as $key => $format) {
            $lines[] = "  {$key}  — {$format->getLabel()}";
        }

        return implode("\n", $lines);
    }
}
