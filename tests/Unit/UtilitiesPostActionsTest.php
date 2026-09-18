<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/ClogProductionFunctions.php';

// Run the production utilities.php action dispatch with every callee recorded,
// so the test shows which state-changing function a request reaches.
function utilitiesDispatchProgram(): string
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/utilities.php');
    $start  = strpos($source, '/* set default action */');
    $end    = strpos($source, '/* -----------------------');

    $program = <<<'CODE'
$_SERVER = $input['server'];
$_REQUEST = $input['request'];
define('MESSAGE_LEVEL_INFO', 1);
$calls = array();
register_shutdown_function(function () { echo json_encode($GLOBALS['calls']); });
function record($name) { $GLOBALS['calls'][] = $name; }
function set_default_action() {}
function get_request_var($name) { return $_REQUEST[$name] ?? ''; }
function isset_request_var($name) { return isset($_REQUEST[$name]); }
function cacti_log($message) { record('log'); }
function __($text) { return $text; }
function raise_message() {}
function top_header() {}
function bottom_footer() {}
function api_plugin_hook_function($name, $value) { return true; }
function repopulate_poller_cache() { record('repopulate_poller_cache'); }
function db_fetch_cell($sql) { return 1; }
function db_execute($sql) {}
function rebuild_resource_cache() { record('rebuild_resource_cache'); }
function utilities_clear_logfile() { record('utilities_clear_logfile'); }
function utilities_view_logfile() {}
function clog_purge_logfile() { record('clog_purge_logfile'); }
function utilities_clear_user_log() { record('utilities_clear_user_log'); }
function utilities_view_user_log() {}
function purge_data_source_statistics() { record('purge_data_source_statistics'); }
function snmpagent_cache_rebuilt() { record('snmpagent_cache_rebuilt'); }
CODE;

    return $program . substr($source, $start, $end - $start);
}

dataset('utilities state actions', array(
    array('clear_poller_cache', 'repopulate_poller_cache'),
    array('rebuild_resource_cache', 'rebuild_resource_cache'),
    array('clear_logfile', 'utilities_clear_logfile'),
    array('purge_logfile', 'clog_purge_logfile'),
    array('clear_user_log', 'utilities_clear_user_log'),
    array('purge_data_source_statistics', 'purge_data_source_statistics'),
    array('rebuild_snmpagent_cache', 'snmpagent_cache_rebuilt'),
));

test('a GET to a state-changing utilities action is rejected before it runs', function (string $action, string $callee) {
    $calls = clogRunProduction(utilitiesDispatchProgram(), array(
        'server'  => array('REQUEST_METHOD' => 'GET'),
        'request' => array('action' => $action),
    ));

    expect($calls)->toBe(array('log'));
})->with('utilities state actions');

test('a POST to a state-changing utilities action still runs it', function (string $action, string $callee) {
    $calls = clogRunProduction(utilitiesDispatchProgram(), array(
        'server'  => array('REQUEST_METHOD' => 'POST'),
        'request' => array('action' => $action),
    ));

    expect($calls)->toContain($callee)
        ->and($calls)->not->toContain('log');
})->with('utilities state actions');

test('utilities menu and purge buttons send state-changing actions by POST with the token', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/utilities.php');

    expect($source)->not->toContain("?action=clear_user_log&header=false")
        ->and($source)->toContain("action: 'clear_user_log'")
        ->and($source)->toContain("<a class='utilityPost' href='#' data-link='")
        ->and($source)->toContain('<input type="hidden" name="__csrf_magic">');

    foreach (array('clear_poller_cache', 'rebuild_resource_cache', 'purge_data_source_statistics', 'rebuild_snmpagent_cache') as $action) {
        expect(preg_match("/'link'  => 'utilities\\.php\\?action=" . $action . "',\\s+'post'  => true,/", $source))->toBe(1);
    }
});
