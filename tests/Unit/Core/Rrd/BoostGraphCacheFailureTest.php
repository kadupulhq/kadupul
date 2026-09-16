<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace BoostGraphCacheFailureTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php'), 'boost_graph_cache_check'));
function set_error_handler(...$args) {}
function restore_error_handler() {}
function boost_poller_id_check()
{
    return true;
}
function boost_check_correct_enabled()
{
    return true;
}
function db_fetch_assoc_prepared(...$args)
{
    return array(array('local_data_id' => 1), array('local_data_id' => 2));
}
function cacti_sizeof($value)
{
    return count($value);
}
function boost_process_poller_output(...$args)
{
    return array_shift($GLOBALS['cache_results']);
}
function boost_return_cached_image(...$args)
{
    throw new \RuntimeException('Stale cache consulted');
}
test('a failed Boost source cannot cancel a successful source and serve stale cache', function ($results) {
    $directory = sys_get_temp_dir() . '/boost-cache-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/poller.php', '<?php');
    $saved = $GLOBALS['config'] ?? null;
    $reporting = error_reporting();
    $GLOBALS['config'] = array('library_path' => $directory);
    $GLOBALS['cache_results'] = $results;
    $graph = array();
    try {
        expect(boost_graph_cache_check(1, 1, false, $graph))->toBeFalse();
    } finally {
        error_reporting($reporting);
        $GLOBALS['config'] = $saved;
        unset($GLOBALS['cache_results']);
        unlink($directory . '/poller.php');
        rmdir($directory);
    }
})->with(array(array(array(-1, 1)), array(array(1, -1))));
