<?php

require __DIR__ . '/vendor/autoload.php';

use DBDiff\Migration\Command\DiffCommand;

use DBDiff\Migration\Command\MigrationNewCommand;
use DBDiff\Migration\Command\MigrationUpCommand;
use DBDiff\Migration\Command\MigrationDownCommand;
use DBDiff\Migration\Command\MigrationStatusCommand;
use DBDiff\Migration\Command\MigrationValidateCommand;
use DBDiff\Migration\Command\MigrationRepairCommand;
use DBDiff\Migration\Command\MigrationBaselineCommand;
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
]);

// Set `diff` as the default command so that the legacy
//   dbdiff server1.db1:server2.db2 [options]
// invocation still works without explicitly typing `diff`.
$app->setDefaultCommand('diff');

$app->run();
