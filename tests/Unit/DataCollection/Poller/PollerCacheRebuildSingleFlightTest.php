<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__, 3) . '/Helpers/ClogProductionFunctions.php';

// Run the production utilities.php dispatch for clear_poller_cache with the
// database lock and the rebuild recorded in call order.
function pollerCacheRebuildProgram(): string
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/utilities.php');
    $start  = strpos($source, '/* set default action */');
    $end    = strpos($source, '/* -----------------------');

    $program = <<<'CODE'
$_SERVER = array('REQUEST_METHOD' => 'POST');
$_REQUEST = array('action' => 'clear_poller_cache');
define('MESSAGE_LEVEL_WARN', 2);
$calls = array();
// Report after every shutdown function the dispatch registers has run.
register_shutdown_function(function () { register_shutdown_function(function () { echo json_encode($GLOBALS['calls']); }); });
function set_default_action() {}
function get_request_var($name) { return $_REQUEST[$name] ?? ''; }
function cacti_log($message) {}
function __($text) { return $text; }
function raise_message($id) { $GLOBALS['calls'][] = 'message:' . $id; }
function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true, $db_conn = false) { $GLOBALS['calls'][] = array($sql, $params); return $GLOBALS['input']['lock']; }
function db_execute_prepared($sql, $params = array(), $log = true, $db_conn = false, $execute_name = 'Exec', $default_value = true, $return_func = 'no_return_function', $return_params = array()) { $GLOBALS['calls'][] = array($sql, $params); return true; }
function repopulate_poller_cache() { $GLOBALS['calls'][] = 'rebuild'; if ($GLOBALS['input']['dies']) { exit(1); } }
CODE;

    return $program . clogProductionFunction('utilities.php', 'utilities_poller_cache_release')
        . substr($source, $start, $end - $start);
}

test('a poller cache rebuild is refused while another holds the lock', function () {
    $calls = clogRunProduction(pollerCacheRebuildProgram(), array('lock' => '0', 'dies' => false));

    expect($calls)->toBe(array(
        array('SELECT GET_LOCK(?, 0)', array('kadupul.poller_cache_rebuild')),
        'message:poller_cache_busy',
    ));
});

test('a poller cache rebuild takes the lock and releases it when done', function () {
    $calls = clogRunProduction(pollerCacheRebuildProgram(), array('lock' => '1', 'dies' => false));

    expect($calls)->toBe(array(
        array('SELECT GET_LOCK(?, 0)', array('kadupul.poller_cache_rebuild')),
        'rebuild',
        array('DO RELEASE_LOCK(?)', array('kadupul.poller_cache_rebuild')),
    ));
});

test('a poller cache rebuild that dies part way still releases the lock at shutdown', function () {
    $calls = clogRunProduction(pollerCacheRebuildProgram(), array('lock' => '1', 'dies' => true), 1);

    expect($calls)->toBe(array(
        array('SELECT GET_LOCK(?, 0)', array('kadupul.poller_cache_rebuild')),
        'rebuild',
        array('DO RELEASE_LOCK(?)', array('kadupul.poller_cache_rebuild')),
    ));
});

// Run the production CLI rebuild flow from its timing setup to the final exit.
function pollerCacheCliProgram(): string
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/rebuild_poller_cache.php');
    $start  = strpos($source, '/* take time and log performance data */');
    $end    = strpos($source, "\nfunction pushout_master_handler");

    $program = <<<'CODE'
$type = 'rmaster';
$thread_id = 0;
$forcerun = false;
$debug = false;
$host_id = $host_template_id = $data_template_id = false;
$threads = 5;
$calls = array();
// Drop the script's console text so only the recorded calls reach stdout.
register_shutdown_function(function () { while (ob_get_level()) { ob_end_clean(); } echo json_encode($GLOBALS['calls']); });
ob_start();
function pushout_debug($message) {}
function register_process_start() { $GLOBALS['calls'][] = 'register'; return true; }
function unregister_process() { $GLOBALS['calls'][] = 'unregister'; }
function pushout_master_handler() { $GLOBALS['calls'][] = 'rebuild'; }
function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true, $db_conn = false) { $GLOBALS['calls'][] = array($sql, $params); return $GLOBALS['input']['lock']; }
function db_execute_prepared($sql, $params = array(), $log = true, $db_conn = false, $execute_name = 'Exec', $default_value = true, $return_func = 'no_return_function', $return_params = array()) { $GLOBALS['calls'][] = array($sql, $params); return true; }
CODE;

    return $program . substr($source, $start, $end - $start);
}

test('the CLI rebuild exits non-zero while the web rebuild holds the lock', function () {
    $calls = clogRunProduction(pollerCacheCliProgram(), array('lock' => '0'), 1);

    expect($calls)->toBe(array(
        'register',
        array('SELECT GET_LOCK(?, 0)', array('kadupul.poller_cache_rebuild')),
        'unregister',
    ));
});

test('the CLI rebuild takes the shared lock and releases it when done', function () {
    $calls = clogRunProduction(pollerCacheCliProgram(), array('lock' => '1'));

    expect($calls)->toBe(array(
        'register',
        array('SELECT GET_LOCK(?, 0)', array('kadupul.poller_cache_rebuild')),
        'rebuild',
        'unregister',
        array('DO RELEASE_LOCK(?)', array('kadupul.poller_cache_rebuild')),
    ));
});

test('an interrupted CLI rebuild stops its children and then releases the lock', function () {
    $program = <<<'CODE'
// Images built without pcntl lack the signal constants sig_handler() uses.
defined('SIGTERM') || define('SIGTERM', 15);
defined('SIGINT') || define('SIGINT', 2);
$type = 'rmaster';
$thread_id = 0;
$calls = array();
register_shutdown_function(function () { echo json_encode($GLOBALS['calls']); });
function cacti_log($message) { $GLOBALS['calls'][] = 'log'; }
function pushout_kill_running_processes() { $GLOBALS['calls'][] = 'kill'; }
function unregister_process() { $GLOBALS['calls'][] = 'unregister'; }
function db_execute_prepared($sql, $params = array(), $log = true, $db_conn = false, $execute_name = 'Exec', $default_value = true, $return_func = 'no_return_function', $return_params = array()) { $GLOBALS['calls'][] = array($sql, $params); return true; }
CODE;

    $program .= clogProductionFunction('cli/rebuild_poller_cache.php', 'sig_handler') . 'sig_handler(SIGTERM);';

    expect(clogRunProduction($program, array(), 1))->toBe(array(
        'log',
        'kill',
        array('DO RELEASE_LOCK(?)', array('kadupul.poller_cache_rebuild')),
        'unregister',
    ));
});
