<?php namespace DBDiff\Migration\Exceptions;

/**
 * Thrown when a migration file or runner operation fails.
 *
 * Covers scaffold failures, missing UP files, directory creation errors, and
 * any other I/O problem encountered while managing migration files on disk.
 */
class MigrationException extends \RuntimeException {}
