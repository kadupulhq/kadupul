<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Stands in for include/cli_check.php when RealtimeCreateNativeTest runs a copy
// of poller_realtime.php. It loads the real lib/rrd.php and its helpers and
// answers the database with two data sources, 11 and 12, each with one
// realtime sample taken at REALTIME_TIME. Data source 12 stores REALTIME_MINIMUM
// and REALTIME_HEARTBEAT; 11 is always valid. RRDtool is the real binary,
// reached through a path that contains a blank.

$root = getenv('REALTIME_ROOT');
$directory = dirname(realpath($_SERVER['argv'][0]));
if (getenv('REALTIME_COVERAGE') === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/poller_realtime.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/poller_realtime.php');
    // Pest 1 declares implicitly nullable parameters, which PHP 8.4 reports as
    // deprecated; only the application code runs with every level on.
    $reporting = error_reporting(error_reporting() & ~E_DEPRECATED);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
    error_reporting($reporting);
}

// cacti_log() switches to the system zone, so start there; sample times then
// convert the same way before and after the first log line.
date_default_timezone_set('UTC');
putenv('TZ=UTC');
putenv('RRDCACHED_ADDRESS');
$config = array(
    'cacti_server_os' => 'unix',
    'is_web' => false,
    'poller_id' => 1,
    'base_path' => $directory,
    'url_path' => '/',
    'library_path' => $directory . '/lib',
    'include_path' => $root . '/include',
    'rra_path' => $directory . '/rra',
    'config_options_array' => array(
        'path_rrdtool' => $directory . '/rrd tool',
        'path_php_binary' => '/usr/bin/true',
        'realtime_cache_path' => $directory . '/cache',
        'realtime_interval' => '10',
        'log_destination' => 1,
        'path_cactilog' => $directory . '/cacti.log',
        'log_verbosity' => 2,
        'storage_location' => 0,
        'extended_paths' => '',
        'default_interface_speed' => '',
    ),
);

function realtime_fixture_rows($local_data_id)
{
    $sample = array('output' => '42', 'time' => getenv('REALTIME_TIME'), 'rrd_path' => '', 'rrd_name' => 'value', 'rrd_num' => '1', 'data_template_id' => '0');
    $source = array('id' => '301', 'data_source_name' => 'value', 'rrd_heartbeat' => '600', 'rrd_minimum' => '0', 'rrd_maximum' => 'U', 'data_source_type_id' => '1');
    if ($local_data_id === '12') {
        $source = array('rrd_minimum' => getenv('REALTIME_MINIMUM'), 'rrd_heartbeat' => getenv('REALTIME_HEARTBEAT')) + $source;
    }

    return array(
        'FROM poller_output_realtime AS port' => array(array('local_data_id' => '11') + $sample, array('local_data_id' => '12') + $sample),
        'SELECT name, data_source_path FROM data_template_data' => array('name' => 'Traffic', 'data_source_path' => '<path_rra>/traffic_' . $local_data_id . '.rrd'),
        'LEFT JOIN data_source_profiles_cf AS dspc' => array(array(
            'rrd_step' => '300', 'x_files_factor' => '0.5', 'steps' => '1', 'rows' => '600',
            'consolidation_function_id' => '1', 'rra_order' => '600',
        )),
        'SELECT data_template_id FROM data_local' => '0',
        'FROM data_template_rrd AS dtr WHERE local_data_id' => array($source),
        'SELECT host_id, snmp_query_id, snmp_index FROM data_local' => array('host_id' => '0', 'snmp_query_id' => '0', 'snmp_index' => ''),
        'field_name="ifHighSpeed"' => '',
        'field_name="ifSpeed"' => '',
        'dtr.data_source_name, dtd.name FROM data_template_rrd' => array('data_source_name' => 'value', 'name' => 'Traffic'),
    );
}

/** Rows keyed by local data id answer for the id the query is bound to. */
function realtime_fixture_query($sql, $params)
{
    $normalized = trim(preg_replace('/\s+/', ' ', $sql));
    foreach (realtime_fixture_rows((string) ($params[0] ?? '')) as $fragment => $result) {
        if (strpos($normalized, $fragment) !== false) {
            return $result;
        }
    }

    throw new RuntimeException('Unexpected query: ' . $normalized);
}

function db_fetch_cell_prepared($sql, $params = array(), ...$args)
{
    return realtime_fixture_query($sql, $params);
}

function db_fetch_row_prepared($sql, $params = array(), ...$args)
{
    return realtime_fixture_query($sql, $params);
}

function db_fetch_assoc_prepared($sql, $params = array(), ...$args)
{
    return realtime_fixture_query($sql, $params);
}

function db_execute_prepared($sql, $params = array(), ...$args)
{
    if (strpos($sql, 'DELETE FROM poller_output_realtime') === false) {
        throw new RuntimeException('Unexpected write: ' . $sql);
    }
    file_put_contents($GLOBALS['directory'] . '/deleted.json', json_encode($params) . "\n", FILE_APPEND);

    return true;
}

function db_close() {}

function db_table_exists(...$args)
{
    return false;
}

function db_column_exists(...$args)
{
    return false;
}

function __()
{
    $args = func_get_args();

    return count($args) > 1 ? vsprintf(array_shift($args), $args) : $args[0];
}

function __x()
{
    $args = func_get_args();
    array_shift($args);

    return count($args) > 1 ? vsprintf(array_shift($args), $args) : $args[0];
}

function __n($singular, $plural, $number, $domain = 'cacti')
{
    return $number == 1 ? $singular : $plural;
}

function __esc()
{
    return htmlspecialchars(call_user_func_array('__', func_get_args()), ENT_QUOTES);
}

define('CACTI_LOCALE', 'en-US');

function get_installed_locales()
{
    return array('en-US' => 'English');
}

function get_new_user_default_language()
{
    return 'en-US';
}

// Libraries include/global.php loads before lib/rrd.php.
require $root . '/include/vendor/autoload.php';
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/auth.php';
require $root . '/lib/plugins.php';
require $root . '/include/global_arrays.php';
require $root . '/lib/rrd.php';
$plugins_integrated = array();
