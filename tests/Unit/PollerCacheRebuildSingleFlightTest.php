<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/ClogProductionFunctions.php';

// Run the production utilities.php dispatch for clear_poller_cache with the
// database lock and the rebuild recorded in call order.
function pollerCacheRebuildProgram(): string
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/utilities.php');
    $start  = strpos($source, '/* set default action */');
    $end    = strpos($source, '/* -----------------------');

    $program = <<<'CODE'
$_SERVER = array('REQUEST_METHOD' => 'POST');
$_REQUEST = array('action' => 'clear_poller_cache');
define('MESSAGE_LEVEL_WARN', 2);
$calls = array();
register_shutdown_function(function () { echo json_encode($GLOBALS['calls']); });
function set_default_action() {}
function get_request_var($name) { return $_REQUEST[$name] ?? ''; }
function cacti_log($message) {}
function __($text) { return $text; }
function raise_message($id) { $GLOBALS['calls'][] = 'message:' . $id; }
function db_fetch_cell($sql) { $GLOBALS['calls'][] = $sql; return $GLOBALS['input']['lock']; }
function db_execute($sql) { $GLOBALS['calls'][] = $sql; }
function repopulate_poller_cache() { $GLOBALS['calls'][] = 'rebuild'; }
CODE;

    return $program . substr($source, $start, $end - $start);
}

test('a poller cache rebuild is refused while another holds the lock', function () {
    $calls = clogRunProduction(pollerCacheRebuildProgram(), array('lock' => '0'));

    expect($calls)->toBe(array(
        "SELECT GET_LOCK('kadupul.poller_cache_rebuild', 0)",
        'message:poller_cache_busy',
    ));
});

test('a poller cache rebuild takes the lock and releases it when done', function () {
    $calls = clogRunProduction(pollerCacheRebuildProgram(), array('lock' => '1'));

    expect($calls)->toBe(array(
        "SELECT GET_LOCK('kadupul.poller_cache_rebuild', 0)",
        'rebuild',
        "SELECT RELEASE_LOCK('kadupul.poller_cache_rebuild')",
    ));
});
