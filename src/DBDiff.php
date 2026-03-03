<?php namespace DBDiff;

use DBDiff\Params\ParamsFactory;
use DBDiff\DB\DiffCalculator;
use DBDiff\SQLGen\SQLGenerator;
use DBDiff\Exceptions\BaseException;
use DBDiff\Logger;
use DBDiff\Templater;


class DBDiff {

    /**
     * Legacy entry point — reads params from the CLI via aura/cli and writes
     * migration.sql (or the --output path) using the Templater.
     *
     * Kept for backward compatibility; new code should call getDiffResult()
     * directly and handle output with the format/template of their choice.
     *
     * @param object|null $params  Pre-built params object (bypasses CLIGetter when supplied).
     */
    public function run($params = null): void
    {
        // Increase memory limit
        ini_set('memory_limit', '512M');

        try {
            if ($params === null) {
                $params = ParamsFactory::get();
            }

            $result = $this->getDiffResult($params);

            if ($result['empty']) {
                Logger::info("Identical resources");
            } else {
                $templater = new Templater($params, $result['up'], $result['down']);
                $templater->output();
            }

            Logger::success("Completed");

        } catch (\Exception $e) {
            if ($e instanceof BaseException) {
                Logger::error($e->getMessage(), true);
            } else {
                Logger::error("Unexpected error: " . $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Compute the diff and return raw UP / DOWN SQL.
     *
     * This is the lower-level method used by DiffCommand so that the caller
     * can apply any output format (Flyway, Liquibase, Laravel, native…) rather
     * than being forced through the Templater.
     *
     * @param  object $params  A params object (DefaultParams-shaped stdClass or subclass).
     * @return array{empty: bool, up: string, down: string}
     */
    public function getDiffResult(object $params): array
    {
        ini_set('memory_limit', '512M');

        $diffCalculator = new DiffCalculator;
        $diff           = $diffCalculator->getDiff($params);

        if (empty($diff['schema']) && empty($diff['data'])) {
            return ['empty' => true, 'up' => '', 'down' => ''];
        }

        $sqlGenerator = new SQLGenerator($diff);
        $up   = ($params->include !== 'down') ? $sqlGenerator->getUp()   : '';
        $down = ($params->include !== 'up')   ? $sqlGenerator->getDown() : '';

        return ['empty' => false, 'up' => $up, 'down' => $down];
    }
}
