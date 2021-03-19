<?php

namespace DBDiff;

use DBDiff\Params\ParamsFactory;

class DiffFilter {
    const TYPE_CHARSET = 'charset';
    const TYPE_COLUMNS = 'columns';
    const TYPE_TABLES = 'tables';

    public static function isFilteredOut(string $typeCheck): bool {
        $params = ParamsFactory::get();

        if (empty($params->diff_filters)) {
            return false;
        }

        if (in_array($typeCheck, $params->diff_filters)) {
            return false;
        }

        return true;
    }
}
