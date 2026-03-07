<?php

/**
 * Returns an array containing all the values of needles with wildcard
 *
 * @param array $haystack
 * @param array $needles
 * @return array
 */
function array_value_includes($haystack, $needles)
{
    $result = [];

    foreach ((array) $needles as $needle) {
        $needle = str_replace('\*', '.*?', preg_quote($needle, '/'));
        $tmpResult = preg_grep('/^' . $needle . '$/i', array_values($haystack));

        $result = array_merge($result, $tmpResult);
    }

    return $result;
}

/**
 * Returns an array not containing all the values of needles with wildcard
 *
 * @param array $haystack
 * @param array $needles
 * @return array
 */
function array_value_excludes($haystack, $needles)
{
    foreach ((array) $needles as $needle) {
        $needle = str_replace('\*', '.*?', preg_quote($needle, '/'));
        $tmpResult = preg_grep('/^' . $needle . '$/i', array_values($haystack));

        $haystack = array_diff($haystack, $tmpResult);
    }

    return $haystack;
}

