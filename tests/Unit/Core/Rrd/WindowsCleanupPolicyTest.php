<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WindowsCleanupPolicyTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
foreach (array(
    'lib/rrd_maintenance.php' => array('rrd_maintenance_cleanup_supported'),
    'lib/api_data_source.php' => array('api_data_source_remove', 'api_data_source_remove_multi'),
    'rrdcleaner.php' => array('rrdclean_truncate_tables', 'do_rrd', 'remove_all_rrds'),
    'poller_maintenance.php' => array('rrdfile_purge', 'remove_files'),
) as $file => $functions) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
    foreach ($functions as $function) {
        eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
    }
}
const MESSAGE_LEVEL_ERROR = 2;
function read_config_option($key, ...$args)
{
    return $GLOBALS['windows_cleanup_options'][$key] ?? '';
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function debounce_run_notification($key, $seconds)
{
    expect($key)->toBe('rrd_cleanup_unsupported')->and($seconds)->toBe(86400);
    return $GLOBALS['windows_notification_available'] ?? true;
}
function cacti_log(...$args)
{
    $GLOBALS['windows_cleanup_logs'][] = $args[0];
}
function raise_message(...$args)
{
    $GLOBALS['windows_cleanup_messages'][] = $args;
}
function __($text)
{
    return $text;
}
function api_plugin_hook_function(...$args) {}
function api_data_source_cache_crc_update(...$args) {}
function poller_push_to_remote_db_connect(...$args)
{
    return false;
}
function get_remote_poller_ids_from_data_sources(...$args)
{
    return array();
}
function db_fetch_cell_prepared(...$args)
{
    return 0;
}
function db_fetch_cell(...$args)
{
    return array_key_exists('windows_cleanup_count', $GLOBALS) ? $GLOBALS['windows_cleanup_count'] : 1;
}
function db_fetch_assoc(...$args)
{
    return empty($GLOBALS['cleanup_read_failure']) ? array() : false;
}
function maint_debug(...$args) {}
function db_fetch_row_prepared(...$args)
{
    return array('local_data_id' => 1, 'data_source_path' => '<path_rra>/sample.rrd');
}
function db_execute_prepared($sql, ...$args)
{
    $GLOBALS['windows_cleanup_queries'][] = $sql;
    return true;
}
function db_execute($sql, ...$args)
{
    return db_execute_prepared($sql);
}
function rrdclean_error_handler(...$args)
{
    return false;
}
function set_error_handler($callback)
{
    return \set_error_handler(__NAMESPACE__ . '\\' . $callback);
}

function with_policy($platform, $remote, $operation)
{
    $saved = $GLOBALS['config'] ?? null;
    $reporting = error_reporting();
    $GLOBALS['config'] = array('cacti_server_os' => $platform);
    $GLOBALS['windows_cleanup_options'] = array('storage_location' => $remote, 'rrd_autoclean' => 'on', 'rrd_autoclean_method' => '1');
    $GLOBALS['windows_cleanup_queries'] = $GLOBALS['windows_cleanup_logs'] = $GLOBALS['windows_cleanup_messages'] = array();
    try {
        $operation();
    } finally {
        $GLOBALS['config'] = $saved;
        error_reporting($reporting);
    }
}

test('data-source deletion keeps cleanup requests even when automatic execution is unsupported', function ($multiple, $platform, $remote, $supported) {
    with_policy($platform, $remote, function () use ($multiple, $supported) {
        $multiple ? api_data_source_remove_multi(array(1, 2)) : api_data_source_remove(1);
        $queries = $GLOBALS['windows_cleanup_queries'];
        expect(count(array_filter($queries, fn($q) => str_contains($q, 'INSERT INTO data_source_purge_action'))))->toBe(1);
        expect(count(array_filter($queries, fn($q) => str_contains($q, 'DELETE FROM data_local'))))->toBe(1);
        if (!$supported) {
            expect(implode(' ', $GLOBALS['windows_cleanup_logs']))->toContain('retained for manual cleanup');
        }
    });
})->with(array(
    array(false, 'win32', 0, false), array(true, 'win32', 0, false),
    array(false, 'unix', 0, true), array(true, 'unix', 0, true),
    array(false, 'win32', 1, true), array(true, 'win32', 1, true),
));

test('Windows cleaner rejects mutations and rescans preserve existing requests', function () {
    with_policy('win32', 0, function () {
        expect(do_rrd())->toBeFalse()->and(remove_all_rrds())->toBeFalse();
        expect($GLOBALS['windows_cleanup_queries'])->toBe(array());
        expect($GLOBALS['windows_cleanup_messages'])->toHaveCount(2);
        rrdclean_truncate_tables();
        expect($GLOBALS['windows_cleanup_queries'])->toBe(array('TRUNCATE TABLE `data_source_purge_temp`'));
    });
});

test('Windows maintenance skips the unsupported queue without reporting a transient failure', function () {
    with_policy('win32', 0, function () {
        expect(rrdfile_purge(false))->toBeTrue();
        $before = $GLOBALS['windows_cleanup_logs'];
        $GLOBALS['windows_notification_available'] = false;
        try {
            expect(rrdfile_purge(false))->toBeTrue()->and($GLOBALS['windows_cleanup_logs'])->toBe($before);
        } finally {
            unset($GLOBALS['windows_notification_available']);
        }
        expect(remove_files(array(array('name' => 'sample.rrd', 'action' => '1'))))->toBeFalse();
        expect($GLOBALS['windows_cleanup_queries'])->toBe(array());
        expect(implode(' ', $GLOBALS['windows_cleanup_logs']))->toContain('existing requests retained for manual cleanup');
    });
});


test('cleanup fails closed on unreadable counts before platform policy', function ($count, $platform) {
    $GLOBALS['windows_cleanup_count'] = $count;
    try {
        with_policy($platform, 0, function () {
            expect(rrdfile_purge(false))->toBeFalse();
            expect($GLOBALS['windows_cleanup_queries'])->toBe(array());
            expect(implode(' ', $GLOBALS['windows_cleanup_logs']))->toContain('Unable to count the RRD cleanup queue');
        });
    } finally {
        unset($GLOBALS['windows_cleanup_count']);
    }
})->with(array(array(false, 'win32'), array(false, 'unix'), array('', 'win32'), array(-1, 'unix')));


test('cleanup refuses a failed queue read without reporting an empty queue', function () {
    $GLOBALS['cleanup_read_failure'] = true;
    try {
        with_policy('unix', 0, function () {
            expect(rrdfile_purge(false))->toBeFalse();
            expect($GLOBALS['windows_cleanup_queries'])->toBe(array());
            expect(implode(' ', $GLOBALS['windows_cleanup_logs']))->toContain('Unable to read the RRD cleanup queue');
        });
    } finally {
        unset($GLOBALS['cleanup_read_failure']);
    }
});


test('forced local Windows storage cannot use a configured proxy to enable cleanup', function () {
    with_policy('win32', 1, function () {
        $GLOBALS['config']['force_storage_location_local'] = true;
        expect(rrd_maintenance_cleanup_supported())->toBeFalse();
        expect(do_rrd())->toBeFalse()->and(remove_all_rrds())->toBeFalse();
        expect(rrdfile_purge(false))->toBeTrue();
        expect(remove_files(array(array('name' => 'sample.rrd', 'action' => '1'))))->toBeFalse();
        expect($GLOBALS['windows_cleanup_queries'])->toBe(array());
    });
});
