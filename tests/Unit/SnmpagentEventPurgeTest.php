<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/ClogProductionFunctions.php';

// The production function stops at the filter setup, after the purge decision.
function snmpagentPurgeProgram(): string
{
    $program = <<<'CODE'
$_SERVER = $input['server'];
$_REQUEST = $input['request'];
$calls = array();
define('SNMPAGENT_EVENT_SEVERITY_LOW', 1);
define('SNMPAGENT_EVENT_SEVERITY_MEDIUM', 2);
define('SNMPAGENT_EVENT_SEVERITY_HIGH', 3);
define('SNMPAGENT_EVENT_SEVERITY_CRITICAL', 4);
function db_fetch_assoc() { return array(); }
function db_execute($sql) { $GLOBALS['calls'][] = $sql; }
function get_filter_request_var($name) { return $_REQUEST[$name] ?? ''; }
function get_request_var($name) { return $_REQUEST[$name] ?? ''; }
function isset_request_var($name) { return isset($_REQUEST[$name]); }
function set_request_var($name, $value) { $_REQUEST[$name] = $value; }
function cacti_log($message) { $GLOBALS['calls'][] = 'log'; }
function die_html_input_error() { throw new RuntimeException('input error'); }
function validate_store_request_vars() { throw new LogicException('stop'); }
CODE;

    return $program . clogProductionFunction('utilities.php', 'snmpagent_utilities_run_eventlog')
        . 'try { snmpagent_utilities_run_eventlog(); } catch (LogicException $e) {} echo json_encode($calls);';
}

test('a GET with purge does not truncate the SNMP Agent notification log', function () {
    $calls = clogRunProduction(snmpagentPurgeProgram(), array(
        'server'  => array('REQUEST_METHOD' => 'GET'),
        'request' => array('action' => 'view_snmpagent_events', 'purge' => '1'),
    ));

    expect($calls)->toBe(array('log'));
});

test('a POST with purge truncates the SNMP Agent notification log', function () {
    $calls = clogRunProduction(snmpagentPurgeProgram(), array(
        'server'  => array('REQUEST_METHOD' => 'POST'),
        'request' => array('action' => 'view_snmpagent_events', 'purge' => '1'),
    ));

    expect($calls)->toBe(array('TRUNCATE table snmpagent_notifications_log'));
});

test('the SNMP Agent notification log purge button posts the token', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/utilities.php');

    expect($source)->not->toContain('view_snmpagent_events&purge=1')
        ->and($source)->toContain("action: 'view_snmpagent_events',\n\t\t\tpurge: 1,");
});
