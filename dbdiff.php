<?php

// Set a generous default memory limit for the CLI. Diffing large databases can
// pull substantial result sets into memory. PHP's default of 128M is often too
// low. This value is intentionally applied here (in the CLI entry point) rather
// than inside the library, so library consumers are never surprised.
// Override per-run via --memory-limit=<value> or memory_limit: <value> in your
// .dbdiff / dbdiff.yml config file.
ini_set('memory_limit', '1G');

require __DIR__ . '/vendor/autoload.php';

use DBDiff\Migration\Command\DiffCommand;

use DBDiff\Migration\Command\MigrationNewCommand;
use DBDiff\Migration\Command\MigrationUpCommand;
use DBDiff\Migration\Command\MigrationDownCommand;
use DBDiff\Migration\Command\MigrationStatusCommand;
use DBDiff\Migration\Command\MigrationValidateCommand;
use DBDiff\Migration\Command\MigrationRepairCommand;
use DBDiff\Migration\Command\MigrationBaselineCommand;
use DBDiff\Migration\Command\UrlEncodeCommand;
use Symfony\Component\Console\Application;

$app = new Application('DBDiff', \DBDiff\VERSION);

$app->addCommands([
    new DiffCommand,
    new MigrationNewCommand,
    new MigrationUpCommand,
    new MigrationDownCommand,
    new MigrationStatusCommand,
    new MigrationValidateCommand,
    new MigrationRepairCommand,
    new MigrationBaselineCommand,
    new UrlEncodeCommand,
]);

// Set `diff` as the default command so that the legacy
//   dbdiff server1.db1:server2.db2 [options]
// invocation still works without explicitly typing `diff`.
$app->setDefaultCommand('diff');

$app->run();
