<?php

require __DIR__ . '/../vendor/autoload.php';

/**
 * DBDiff Test Bootstrap
 * 
 * This file handles project-wide test initialization.
 * It ensures PSR-4 autoloading is active and manages environment-specific tweaks.
 */

// Handle third-party vendor deprecations to keep test output clean.
// This is compatible with PHP 8.3+ and future-proof as it uses standard PHP error handling.
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Only intercept deprecations
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        // Specifically ignore issues inside the vendor directory (3rd party libraries)
        // while still allowing issues in 'src/' or 'tests/' to be reported.
        if (strpos($errfile, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
            return true; // Suppress
        }
    }
    // Return false to let PHP's (and PHPUnit's) default error handlers process everything else
    return false;
});

