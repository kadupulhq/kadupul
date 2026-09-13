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
 * help.php?error= is open to any session, including the guest account. It put
 * the page name from the request into the HTML admin notice as is, and a
 * page[] array made basename() throw. The error branch is extracted from
 * help.php and run with the real debounce_run_notification() from
 * lib/functions.php over an in-memory settings table. The ordinary-page cases
 * pin the log line, the mail and its cadence to what release/1.2.31 sends.
 *
 * The stubs and the extracted code live in this namespace because other unit
 * tests load lib/functions.php, which defines the same global names.
 */

namespace HelpPageErrorNotificationTest;

$root = dirname(__DIR__, 4);

if (!function_exists(__NAMESPACE__ . '\report_page_error')) {
	$source = file_get_contents($root . '/help.php');
	$open   = "if (isset_request_var('error')) {\n";
	$start  = strpos($source, $open);
	$end    = strpos($source, "\n} elseif (isset_request_var('page')) {");

	expect($start)->not->toBeFalse();
	expect($end)->not->toBeFalse();

	$branch = substr($source, $start + strlen($open), $end - $start - strlen($open));

	preg_match('/^function debounce_run_notification\(.*?^}\n/ms', file_get_contents($root . '/lib/functions.php'), $debounce);

	expect($debounce)->not->toBeEmpty();

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; function report_page_error() { ' . $branch . ' } ' . $debounce[0]);
}

function isset_request_var($name) {
	return isset($GLOBALS['help_request'][$name]);
}

function get_nfilter_request_var($name) {
	return $GLOBALS['help_request'][$name] ?? '';
}

function get_filter_request_var($name) {
	return (int) ($GLOBALS['help_request'][$name] ?? 0);
}

function get_username($user_id) {
	return $GLOBALS['help_username'];
}

function cacti_log($message, $stdout = false) {
	$GLOBALS['help_log'][] = $message;
}

function admin_email($subject, $message) {
	$GLOBALS['help_mail'][] = $message;
}

function die_html_input_error($variable = '', $value = '', $message = '') {
	throw new \RuntimeException('input error: ' . $variable);
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function time() {
	return $GLOBALS['help_now'];
}

function read_config_option($name) {
	return $GLOBALS['help_settings'][$name] ?? '';
}

function set_config_option($name, $value) {
	$GLOBALS['help_settings'][$name] = $value;
}

function cacti_debug_backtrace($message) {
}

function report($page, $now) {
	$GLOBALS['help_request'] = array('error' => '500', 'page' => $page);
	$GLOBALS['help_now']     = $now;

	report_page_error();
}

beforeEach(function () {
	$GLOBALS['help_username'] = 'admin';
	$GLOBALS['help_log']      = array();
	$GLOBALS['help_mail']     = array();
	$GLOBALS['help_settings'] = array();

	$_SESSION = array('sess_user_id' => 3);
});

// page= is the failed request's URL as include/layout.js encodes it
dataset('ordinary pages', array(
	'bare page'          => array('graph_view.php', 'admin', 'graph_view.php'),
	'path and query'     => array('/cacti/graph_view.php?action=tree&node=tree_anchor-1&hyper=true', 'admin', 'graph_view.php?action=tree&node=tree_anchor-1&hyper=true'),
	'quoted filter'      => array('host.php?action=edit&id=12&filter=O\'Brien "core"', 'O\'Brien & Sons', 'host.php?action=edit&id=12&filter=O\'Brien "core"'),
	'plugin page'        => array('/cacti/plugins/thold/thold_graph.php?tab=thold&status=-1', 'admin', 'thold_graph.php?tab=thold&status=-1'),
	'non-ascii filter'   => array('graph_view.php?filter=Zürich&rows=-1', 'admin', 'graph_view.php?filter=Zürich&rows=-1'),
));

test('an ordinary page is logged and mailed byte for byte as in 1.2.31', function ($page, $username, $name) {
	$GLOBALS['help_username'] = $username;

	report($page, 1000000);

	expect($GLOBALS['help_log'])->toBe(array("WARNING: Cacti Page:$name for User:$username Generated a Fatal Error:500"));
	expect($GLOBALS['help_mail'])->toBe(array("WARNING: Cacti Page:$name for User:$username Generated a Fatal Error 500!"));
})->with('ordinary pages');

test('each page value keeps the 1.2.31 two hour notification cadence', function () {
	report('graph_view.php?action=tree', 1000000);
	report('graph_view.php?action=tree', 1000100);
	report('graph_view.php?action=list', 1000100);
	report('host.php', 1000101);
	report('graph_view.php?action=tree', 1007200);
	report('graph_view.php?action=tree', 1007201);

	expect($GLOBALS['help_log'])->toHaveCount(6);
	expect($GLOBALS['help_mail'])->toHaveCount(4);
	expect(array_keys($GLOBALS['help_settings']))->toBe(array(
		'debounce_page_error_graph_view.php?action=tree',
		'debounce_page_error_graph_view.php?action=list',
		'debounce_page_error_host.php',
	));
});

test('the admin mail escapes angle brackets in the page and user names', function () {
	$GLOBALS['help_username'] = '<b>guest</b>';

	report('index.php?<img src=x onerror=alert(1)>', 1000000);

	expect($GLOBALS['help_mail'])->toBe(array('WARNING: Cacti Page:index.php?&lt;img src=x onerror=alert(1)&gt; for User:&lt;b&gt;guest&lt;/b&gt; Generated a Fatal Error 500!'));
});

test('a page array is refused as malformed input', function () {
	$GLOBALS['help_request'] = array('error' => '500', 'page' => array('graph_view.php'));
	$GLOBALS['help_now']     = 1000000;

	expect(fn () => report_page_error())->toThrow(\RuntimeException::class, 'input error: page');
	expect($GLOBALS['help_log'])->toBe(array());
	expect($GLOBALS['help_mail'])->toBe(array());
});
