<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Child process for the tests/Unit/Core/Rrd/*CharacterizationTest.php files.
// It loads the real lib/rrd.php and the real helpers it calls, and replaces
// only the database layer with fixture rows so command construction can be
// pinned byte for byte.

list(, $root, $directory, $collect) = $argv;
if ($collect === '1') {
    // Pest 1 declares implicitly nullable parameters, which PHP 8.4 reports as
    // deprecated. Only the application code below runs with every level on.
    error_reporting(E_ALL & ~E_DEPRECATED);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
    error_reporting(E_ALL);
}

$scenario = json_decode(file_get_contents($directory . '/scenario.json'), true, 512, JSON_THROW_ON_ERROR);

// Legends and business hours format dates in the process zone.
date_default_timezone_set('UTC');
putenv('TZ=UTC');
putenv('LANG=en_US.UTF-8');
putenv('RRDCACHED_ADDRESS');
$config = array(
    'cacti_server_os' => 'unix',
    'is_web' => false,
    'poller_id' => 1,
    'base_path' => $root,
    'url_path' => '/',
    'library_path' => $root . '/lib',
    'include_path' => $root . '/include',
    'rra_path' => $directory . '/rra',
    'config_options_array' => ($scenario['options'] ?? array()) + array(
        'path_rrdtool' => $directory . '/rrdtool',
        'log_destination' => 0,
        'log_verbosity' => 0,
        'storage_location' => 0,
    ),
);

function fixture_query($kind, $sql, $params)
{
    global $scenario;

    $normalized = trim(preg_replace('/\s+/', ' ', $sql));
    foreach ($scenario['db'] ?? array() as $entry) {
        if (strpos($normalized, $entry['sql']) === false) {
            continue;
        }
        if (array_key_exists('params', $entry) && array_map('strval', $entry['params']) !== array_map('strval', array_values($params))) {
            continue;
        }
        return $entry['result'];
    }

    // While writing a scenario, FIXTURE_DISCOVER=1 lists every query it still needs.
    if (getenv('FIXTURE_DISCOVER')) {
        fwrite(STDERR, 'MISS ' . $kind . ' ' . $normalized . ' ' . json_encode($params) . PHP_EOL);

        return $kind === 'cell' ? false : array();
    }

    throw new RuntimeException('Unexpected ' . $kind . ' query: ' . $normalized . ' ' . json_encode($params));
}

function db_fetch_cell($sql, $col_name = '', $log = true, $db_conn = false)
{
    return fixture_query('cell', $sql, array());
}

function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true, $db_conn = false)
{
    return fixture_query('cell', $sql, $params);
}

function db_fetch_row($sql, $log = true, $db_conn = false)
{
    return fixture_query('row', $sql, array());
}

function db_fetch_row_prepared($sql, $params = array(), $log = true, $db_conn = false)
{
    return fixture_query('row', $sql, $params);
}

function db_fetch_assoc($sql, $log = true, $db_conn = false)
{
    return fixture_query('assoc', $sql, array());
}

function db_fetch_assoc_prepared($sql, $params = array(), $log = true, $db_conn = false)
{
    return fixture_query('assoc', $sql, $params);
}

function db_execute($sql, $log = true, $db_conn = false)
{
    throw new RuntimeException('Unexpected write: ' . $sql);
}

function db_execute_prepared($sql, $params = array(), $log = true, $db_conn = false, $execute_name = 'Exec', $default_value = true, $return_func = 'no_return_function', $return_params = array())
{
    throw new RuntimeException('Unexpected write: ' . $sql);
}

function db_table_exists($table, $log = true, $db_conn = false)
{
    return in_array($table, $GLOBALS['scenario']['tables'] ?? array(), true);
}

function db_column_exists($table, $column, $log = true, $db_conn = false)
{
    return false;
}

// global_languages.php starts gettext and reads the database; an untranslated
// session formats its arguments the same way.
function __()
{
    $args = func_get_args();

    return count($args) > 1 ? vsprintf(array_shift($args), $args) : $args[0];
}

define('CACTI_LOCALE', 'en-US');

// The real one formats through intl in the session locale; en-US grouping
// with no decimals is what it produces for the defaults used here.
function number_format_i18n($number, $decimals = null, $baseu = 1024)
{
    return number_format((float) $number, $decimals ?? 0);
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

function get_installed_locales()
{
    return array('en-US' => 'English');
}

function get_new_user_default_language()
{
    return 'en-US';
}

function __esc()
{
    return htmlspecialchars(call_user_func_array('__', func_get_args()), ENT_QUOTES);
}

// include/global.php loads this before lib/rrd.php in the application.
require $root . '/include/vendor/autoload.php';
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/auth.php';
require $root . '/lib/plugins.php';
require $root . '/include/global_arrays.php';
// include/global.php defines this list; settings only enumerate it for logging.
$no_http_header_files = array();
require $root . '/include/global_settings.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/mib_cache.php';
require $root . '/lib/variables.php';
require $root . '/lib/rrd.php';

$plugins_integrated = array();
$_COOKIE = $scenario['cookies'] ?? array();

// Each call reports its return value, anything it printed, every command the
// fake RRDtool received, and its arguments afterwards so by-reference outputs
// are pinned too. The clock brackets the call for output that embeds time().
$results = array();
// Calls in one scenario share settings and globals on purpose: a later call
// sees what an earlier one set, the way a single request would. Scenarios do
// not share anything, because each one runs in its own child process.
foreach ($scenario['calls'] as $call) {
    $config['config_options_array'] = array_replace($config['config_options_array'], $call['options'] ?? array());
    $args = $call['args'];
    mt_srand(20260923);
    // Warnings are part of today's behavior. Record them without file and line
    // so moving the code does not change the golden.
    $diagnostics = array();
    set_error_handler(function ($level, $message) use (&$diagnostics) {
        $diagnostics[] = $level . ': ' . $message;

        return true;
    });
    // Entry points such as poller_realtime.php set single $config keys.
    foreach ($call['config'] ?? array() as $name => $value) {
        $config[$name] = $value;
    }
    foreach ($call['globals'] ?? array() as $name => $value) {
        $GLOBALS[$name] = $value;
    }
    // A proxy scenario gives the call one session, as a request would, since
    // the fake proxy takes a single connection.
    if (isset($call['rrdp_argument'])) {
        $args[$call['rrdp_argument']] = rrd_init();
    }
    $before = time();
    ob_start();
    try {
        $returned = $call['fn'](...$args);
    } catch (Throwable $thrown) {
        // Only calls that expect to fail may; anything else still aborts the child.
        if (empty($call['catch'])) {
            throw $thrown;
        }
        $returned = array('thrown' => get_class($thrown), 'message' => $thrown->getMessage());
    }
    $printed = ob_get_clean();
    if (isset($call['rrdp_argument'])) {
        rrd_close($args[$call['rrdp_argument']]);
        $args[$call['rrdp_argument']] = '<rrdp>';
    }
    $after = time();
    restore_error_handler();
    $sent = array();
    if (is_file($directory . '/stdin.log')) {
        foreach (file($directory . '/stdin.log') as $line) {
            $sent[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        unlink($directory . '/stdin.log');
    }
    $results[] = array(
        'returned' => $returned,
        'printed' => $printed,
        'sent' => $sent,
        'args' => $args,
        'diagnostics' => $diagnostics,
        'clock' => array($before, $after),
    );
}

echo json_encode(array('results' => $results), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
