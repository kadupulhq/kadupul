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
 * help.php?error= is open to any session, including the guest account. It puts
 * the page name from the request into the HTML admin notice as is, a page[]
 * array made basename() throw, and every new page value added a settings row
 * and could send another mail. The error branch is extracted from help.php and
 * run with the real debounce functions from lib/functions.php over an in-memory
 * settings table. The parity cases pin the log line, the mail text and the page
 * window to what release/1.2.31 sends; the limit cases describe what is added.
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

	$branch    = substr($source, $start + strlen($open), $end - $start - strlen($open));
	$functions = file_get_contents($root . '/lib/functions.php');
	$debounce  = '';

	// release/1.2.31 and lts/1.2 have no debounce_claim_notification()
	foreach (array('debounce_run_notification', 'debounce_claim_notification') as $fn) {
		if (preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $functions, $match)) {
			$debounce .= $match[0];
		}
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; function report_page_error() { global $config; ' . $branch . ' } ' . $debounce);
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

function cacti_sizeof($array) {
	return (is_array($array) ? count($array) : 0);
}

function time() {
	return $GLOBALS['help_now'];
}

function cacti_debug_backtrace($message) {
}

/* the settings table debounce_run_notification() reads and writes */
function read_config_option($name) {
	return $GLOBALS['help_settings'][$name] ?? '';
}

function set_config_option($name, $value) {
	$GLOBALS['help_settings'][$name] = (string) $value;
}

/*
 * The same table for debounce_claim_notification(). Each statement matches the
 * row the way InnoDB does under its row lock, and help_race stands for another
 * request whose claim commits just before this request's update.
 */
function db_execute_prepared($sql, $params = array()) {
	$GLOBALS['help_affected'] = 0;

	if (strpos($sql, 'INSERT IGNORE INTO settings') === 0) {
		if (!isset($GLOBALS['help_settings'][$params[0]])) {
			$GLOBALS['help_settings'][$params[0]] = (string) $params[1];
			$GLOBALS['help_affected'] = 1;
		}
	} elseif (strpos($sql, 'UPDATE settings') === 0) {
		if (isset($GLOBALS['help_race'])) {
			$GLOBALS['help_settings'][$params[1]] = (string) $GLOBALS['help_race'];
		}

		$value = $GLOBALS['help_settings'][$params[1]] ?? null;

		if ($value !== null && (!ctype_digit($value) || (int) $value < $params[2])) {
			$GLOBALS['help_settings'][$params[1]] = (string) $params[0];
			$GLOBALS['help_affected'] = 1;
		}
	}

	return true;
}

function db_affected_rows() {
	return $GLOBALS['help_affected'];
}

/* a report from a fresh session, as each browser tab or client that drops its
   cookie sends */
function report($page, $now, $user_id = 3) {
	$_SESSION = array('sess_user_id' => $user_id);

	report_in_session($page, $now);
}

function report_in_session($page, $now) {
	$GLOBALS['help_request'] = array('error' => '500', 'page' => $page);
	$GLOBALS['help_now']     = $now;

	report_page_error();
}

function page_keys() {
	return array_values(array_filter(array_keys($GLOBALS['help_settings']), function ($key) {
		return strpos($key, 'debounce_page_error_user_') !== 0;
	}));
}

beforeEach(function () use ($root) {
	$GLOBALS['config']        = array('base_path' => $root);
	$GLOBALS['help_username'] = 'admin';
	$GLOBALS['help_log']      = array();
	$GLOBALS['help_mail']     = array();
	$GLOBALS['help_settings'] = array();
	$GLOBALS['help_affected'] = 0;
	$GLOBALS['help_race']     = null;
	$GLOBALS['help_now']      = 1000000;

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

test('a session user that no longer exists is logged and mailed as in 1.2.31', function () {
	// get_username() returns the false db_fetch_cell_prepared() gives for no row
	$GLOBALS['help_username'] = false;

	report('graph_view.php', 1000000);

	expect($GLOBALS['help_log'])->toBe(array('WARNING: Cacti Page:graph_view.php for User: Generated a Fatal Error:500'));
	expect($GLOBALS['help_mail'])->toBe(array('WARNING: Cacti Page:graph_view.php for User: Generated a Fatal Error 500!'));
});

test('an ordinary page error mails once in the 1.2.31 two hour window', function () {
	report('graph_view.php?action=tree', 1000000, 3);
	report('graph_view.php?action=tree', 1000100, 4);
	report('graph_view.php?action=tree', 1007200, 5);
	report('graph_view.php?action=tree', 1007201, 6);

	expect($GLOBALS['help_log'])->toHaveCount(4);
	expect($GLOBALS['help_mail'])->toHaveCount(2);
});

test('the admin mail escapes angle brackets in the page and user names', function () {
	$GLOBALS['help_username'] = '<b>guest</b>';

	report('index.php?<img src=x onerror=alert(1)>', 1000000);

	expect($GLOBALS['help_mail'])->toBe(array('WARNING: Cacti Page:index.php?&lt;img src=x onerror=alert(1)&gt; for User:&lt;b&gt;guest&lt;/b&gt; Generated a Fatal Error 500!'));
});

test('a page array is refused as malformed input', function () {
	$GLOBALS['help_request'] = array('error' => '500', 'page' => array('graph_view.php'));

	expect(fn () => report_page_error())->toThrow(\RuntimeException::class, 'input error: page');
	expect($GLOBALS['help_log'])->toBe(array());
	expect($GLOBALS['help_mail'])->toBe(array());
});

test('one session logs one report every ten seconds', function () {
	report_in_session('graph_view.php', 1000000);
	report_in_session('host.php', 1000009);
	report_in_session('host.php', 1000010);

	expect($GLOBALS['help_log'])->toHaveCount(2);
});

test('one user mails the admin at most once in five minutes', function () {
	report('graph_view.php', 1000000);
	report('host.php', 1000100);
	report('data_sources.php', 1000300);
	report('data_sources.php', 1000301);

	expect($GLOBALS['help_log'])->toHaveCount(4);
	expect($GLOBALS['help_mail'])->toHaveCount(2);
});

test('distinct junk page values share one settings row', function () {
	for ($i = 0; $i < 200; $i++) {
		report('no_such_page_' . $i . '.php?x=' . $i, 1000000 + $i, 1000 + $i);
	}

	expect(page_keys())->toBe(array('debounce_page_error_unknown'));
	expect($GLOBALS['help_mail'])->toHaveCount(1);
});

test('query strings on a real script share that script\'s settings row', function () {
	for ($i = 0; $i < 200; $i++) {
		// short enough that 1.2.31 keeps each value's key whole
		report('/cacti/graph_view.php?node=' . $i, 1000000 + $i, 1000 + $i);
	}

	expect(page_keys())->toBe(array('debounce_page_error_graph_view.php'));
	expect($GLOBALS['help_mail'])->toHaveCount(1);
});

test('a claim takes a key once and refuses it until the window has passed', function () {
	expect(function_exists(__NAMESPACE__ . '\debounce_claim_notification'))->toBeTrue();

	expect(debounce_claim_notification('page_error_user_3', 300))->toBeTrue();
	expect(debounce_claim_notification('page_error_user_3', 300))->toBeFalse();

	$GLOBALS['help_now'] = 1000300;

	expect(debounce_claim_notification('page_error_user_3', 300))->toBeFalse();

	$GLOBALS['help_now'] = 1000301;

	expect(debounce_claim_notification('page_error_user_3', 300))->toBeTrue();
	expect($GLOBALS['help_settings'])->toBe(array('debounce_page_error_user_3' => '1000301'));
});

test('of two concurrent claims on a stale key only the first sends', function () {
	expect(function_exists(__NAMESPACE__ . '\debounce_claim_notification'))->toBeTrue();

	$GLOBALS['help_settings'] = array('debounce_page_error_host.php' => '1');
	$GLOBALS['help_race']     = 1000000;

	expect(debounce_claim_notification('page_error_host.php'))->toBeFalse();
	expect($GLOBALS['help_settings'])->toBe(array('debounce_page_error_host.php' => '1000000'));
});

test('a claim takes a key that does not hold a timestamp', function () {
	expect(function_exists(__NAMESPACE__ . '\debounce_claim_notification'))->toBeTrue();

	$GLOBALS['help_settings'] = array('debounce_page_error_user_3' => 'junk');

	expect(debounce_claim_notification('page_error_user_3', 300))->toBeTrue();
	expect($GLOBALS['help_settings']['debounce_page_error_user_3'])->toBe('1000000');
});
