<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/ClogProductionFunctions.php';

function clogRegexProgram(): string
{
    $program = <<<'CODE'
$config = array('url_path' => '/');
$user_auth_realm_filenames = array('host.php' => 3, 'data_sources.php' => 3, 'pollers.php' => 3, 'graph_view.php' => 7, 'user_admin.php' => 1, 'data_input.php' => 2, 'data_queries.php' => 13, 'graph_templates.php' => 10, 'automation_graph_rules.php' => 23);
function clog_admin() { return $GLOBALS['input']['admin']; }
function is_realm_allowed($realm) { return in_array($realm, $GLOBALS['input']['realms'], true); }
function is_device_allowed($id) { return in_array((int) $id, $GLOBALS['input']['devices'], true); }
function is_graph_allowed($id) { return in_array((int) $id, $GLOBALS['input']['graphs'], true); }
function db_fetch_cell_prepared($sql, $params) {
	if (strpos($sql, 'FROM host') !== false) return 'device-' . $params[0];
	if (strpos($sql, 'FROM user_auth') !== false) return 'user-' . $params[0];
	if (strpos($sql, 'graph_templates_graph') !== false) return 'graph-' . $params[0];
	return 'input-' . $params[0];
}
function db_fetch_assoc($sql) { return array(array('id' => 1, 'name' => 'object-1')); }
function db_fetch_assoc_prepared() { return array(); }
function array_rekey($rows, $key, $value) { $out = array(); foreach ($rows as $row) { $out[$row[$key]] = $row[$value]; } return $out; }
function get_data_source_title($id) { return 'source-' . $id; }
function api_plugin_hook_function($name, $value) { return $value; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function html_escape($value) { return htmlspecialchars($value, ENT_QUOTES); }
function __($text) { return $text; }
CODE;

    foreach (array('clog_get_datasource_titles', 'clog_get_regex_array', 'clog_regex_parser', 'clog_regex_allowed', 'clog_regex_device', 'clog_regex_datasource',
        'clog_regex_datainput', 'clog_regex_poller', 'clog_regex_dataquery', 'clog_regex_graphs', 'clog_regex_graphtemplates', 'clog_regex_users', 'clog_regex_rule') as $name) {
        $program .= clogProductionFunction('lib/clog_webapi.php', $name);
    }

    return $program . '$regex = clog_get_regex_array();'
        . 'echo json_encode(array(preg_replace_callback($regex["complete"], "clog_regex_parser", $input["line"])));';
}

function clogRegexLine(): string
{
    return 'POLLER: Poller[1] Device[3] Device[4] DS[5] Graph[7] Graph[8] User[2] DI[6] DQ[1] GT[1] Rule[1] done';
}

test('a log viewer without object access sees raw IDs instead of names', function () {
    list($out) = clogRunProduction(clogRegexProgram(), array(
        'admin'   => false,
        'realms'  => array(7, 19),
        'devices' => array(3),
        'graphs'  => array(7),
        'line'    => clogRegexLine(),
    ));

    expect($out)->toContain('device-3')
        ->and($out)->toContain('graph-7')
        ->and($out)->toContain(' Device[4]')
        ->and($out)->toContain(' User[2]')
        ->and($out)->toContain(' Poller[1]')
        ->and($out)->toContain(' DS[5]')
        ->and($out)->toContain(' DI[6]')
        ->and($out)->toContain(' DQ[1]')
        ->and($out)->toContain(' GT[1]')
        ->and($out)->toContain(' Rule[1]')
        ->and($out)->not->toContain('device-4')
        ->and($out)->not->toContain('graph-8')
        ->and($out)->not->toContain('user-2')
        ->and($out)->not->toContain('source-5')
        ->and($out)->not->toContain('input-6')
        ->and($out)->not->toContain('object-1');
});

test('a log administrator still sees every name', function () {
    list($out) = clogRunProduction(clogRegexProgram(), array(
        'admin'   => true,
        'realms'  => array(),
        'devices' => array(),
        'graphs'  => array(),
        'line'    => clogRegexLine(),
    ));

    foreach (array('device-3', 'device-4', 'graph-7', 'graph-8', 'user-2', 'source-5', 'input-6', 'object-1') as $name) {
        expect($out)->toContain($name);
    }
});

test('realm access expands the names on that realm pages', function () {
    list($out) = clogRunProduction(clogRegexProgram(), array(
        'admin'   => false,
        'realms'  => array(1, 3, 7, 19),
        'devices' => array(),
        'graphs'  => array(8),
        'line'    => clogRegexLine(),
    ));

    expect($out)->toContain('device-4')
        ->and($out)->toContain('user-2')
        ->and($out)->toContain('source-5')
        ->and($out)->toContain('graph-8')
        ->and($out)->not->toContain('graph-7')
        ->and($out)->not->toContain('input-6');
});
