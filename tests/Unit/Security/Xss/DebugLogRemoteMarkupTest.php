<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/*
 * run_data_query() stores the data query log returned by a Remote Data
 * Collector in the session, and host.php renders it with debug_log_return().
 * A hostile collector controls that JSON, so markup other than the section
 * helpers' own must not survive. A legitimate log, and anything written
 * locally, must render byte for byte as in 1.2.31.
 *
 * The functions are extracted from lib/functions.php and lib/data_query.php
 * into this namespace; database and transport helpers are stubbed.
 */

namespace DebugLogRemoteMarkupTest;

if (!defined('HOST_DOWN')) {
	define('HOST_DOWN', 1);
}

if (!function_exists(__NAMESPACE__ . '\debug_log_return')) {
	$root = dirname(__DIR__, 4);
	$code = '';

	$sources = array(
		'/lib/functions.php'  => array('debug_log_insert_section_start', 'debug_log_insert_section_end', 'debug_log_insert', 'debug_log_return', 'debug_log_escape'),
		'/lib/data_query.php' => array('run_data_query'),
	);

	foreach ($sources as $file => $names) {
		$source = file_get_contents($root . $file);

		foreach ($names as $name) {
			if (preg_match('/^function ' . $name . '\(.*?^}\n/ms', $source, $match)) {
				$code .= $match[0];
			}
		}
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $code);
}

function __() {
	$args = func_get_args();

	return cacti_sizeof($args) > 1 ? vsprintf(array_shift($args), $args) : $args[0];
}

function __esc() {
	return htmlspecialchars(call_user_func_array(__NAMESPACE__ . '\__', func_get_args()), ENT_QUOTES);
}

function html_escape($string) {
	return htmlspecialchars((string) $string, ENT_QUOTES);
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function generate_hash() {
	return str_repeat('ab', 16);
}

function read_config_option($name) {
	return '';
}

function db_column_exists($table, $column) {
	return true;
}

function db_fetch_row_prepared($sql, $params = array()) {
	return array('status' => 3, 'disabled' => '', 'poller_id' => 2);
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return 'collector.example';
}

function call_remote_data_collector($poller_id, $url) {
	return json_encode(array('data_query' => $GLOBALS['remote_log']));
}

function local_log() {
	$_SESSION = array();

	debug_log_insert('data_query', __('Total: %f, Delta: %f, %s', 0.1, 0.1, __esc('Running Data Query [%s].', 4)));
	debug_log_insert_section_start('data_query', __esc('Click to show Data Query output for field \'%s\'', 'ifName'), true);
	debug_log_insert('data_query', __esc('Found item [%s=\'%s\'] index: %s', 'ifName', 'eth0 & "lo"', 1));
	debug_log_insert_section_end('data_query');
	debug_log_insert('data_query', "Total: 0.2, Delta: 0.1, l'index R&D");

	return $_SESSION['debug_log']['data_query'];
}

function render_remote_log(array $remote_log) {
	$GLOBALS['config']     = array('poller_id' => 1, 'url_path' => '/cacti/');
	$GLOBALS['remote_log'] = $remote_log;

	$_SESSION = array();

	run_data_query(7, 4);

	return debug_log_return('data_query');
}

function rendered(array $log) {
	$html = "<table style='width:100%;'>";

	foreach ($log as $line) {
		$html .= '<tr><td>' . $line . '</td></tr>';
	}

	return $html . '</table>';
}

test('escapes markup a Remote Data Collector returns in its data query log', function () {
	$output = render_remote_log(array(
		'<img src=x onerror=alert(1)>',
		'<script>alert(1)</script>',
	));

	expect($output)->not->toContain('<img')
		->and($output)->not->toContain('<script')
		->and($output)->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

test('escapes a forged section header that carries extra attributes', function () {
	$forged = "<table class='cactiTable debug' id='clipboardHeaderabababababababababababababababab' onmouseover='alert(1)'><tr class='tableHeader'><td>x</td></tr><tr><td style='padding:0px;'><table style='display:none;'><tr><td><div style='font-family: monospace;'>";

	$output = render_remote_log(array($forged));

	expect($output)->not->toContain("<table class='cactiTable debug' id='clipboardHeader")
		->and($output)->toContain("&lt;table class='cactiTable debug' id='clipboardHeaderabababababababababababababababab' onmouseover='alert(1)'&gt;");
});

test('renders a legitimate remote log exactly as the local helpers produce it', function () {
	$log = local_log();

	expect(render_remote_log($log))->toBe(rendered($log));
});

test('renders locally written entries unchanged, including plugin markup', function () {
	$_SESSION = array();
	$GLOBALS['config'] = array('poller_id' => 1);

	debug_log_insert('new_graphs', __esc('Created graph: %s', 'Traffic <eth0> & "lo"'));
	debug_log_insert('new_graphs', '<b>plugin note</b>');

	expect(debug_log_return('new_graphs'))
		->toBe(rendered(array('Created graph: Traffic &lt;eth0&gt; &amp; &quot;lo&quot;', '<b>plugin note</b>')));
});
