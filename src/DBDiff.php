<?php namespace DBDiff;

use DBDiff\Params\ParamsFactory;
use DBDiff\DB\DiffCalculator;
use DBDiff\SQLGen\SQLGenerator;
use DBDiff\Exceptions\BaseException;
use DBDiff\Logger;
use DBDiff\Templater;


class DBDiff {

    public function run() {

        // Increase memory limit
        ini_set('memory_limit', '512M');

        try {
            $params = ParamsFactory::get();

            // Diff
            $diffCalculator = new DiffCalculator;
            $diff = $diffCalculator->getDiff($params);

            // Empty diff
            if (empty($diff['schema']) && empty($diff['data'])) {
                Logger::info("Identical resources");
            } else {
                // SQL
                $sqlGenerator = new SQLGenerator($diff);
                $up =''; $down = '';
                if ($params->include !== 'down') {
                    $up = $sqlGenerator->getUp();
                }
                if ($params->include !== 'up') {
                    $down = $sqlGenerator->getDown();
                }

                // Generate
                $templater = new Templater($params, $up, $down);
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
}
