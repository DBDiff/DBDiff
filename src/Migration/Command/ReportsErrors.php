<?php namespace DBDiff\Migration\Command;

use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command that ends whatever stops it with a short message and exit 1 —
 * see ErrorReport. A mistyped option or argument is still Symfony's to
 * report, with the command's usage.
 */
trait ReportsErrors {

    public function run(InputInterface $input, OutputInterface $output): int {
        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ErrorReport::write($output, $e);
        }
    }
}
