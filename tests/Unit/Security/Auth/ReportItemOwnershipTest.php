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
 * Report item save, edit and move took report_id and the item id from the
 * request without checking either against the caller, so a Reports Creation
 * user could add, rewrite or reorder items in another user's report. Each path
 * must now authorise the report and confirm the item belongs to it before it
 * reads or writes the item row.
 *
 * The functions are also extracted from lib/html_reports.php and run with
 * request, database and redirect stubs. For an owner or a report admin the
 * pinned redirects and rows are the ones release/1.2.31 produces from the same
 * requests. The stubs live in this namespace because other unit tests load
 * lib/functions.php into the global namespace.
 */

namespace ReportItemOwnershipTest;

$root = dirname(__DIR__, 4);
$src  = file_get_contents($root . '/lib/html_reports.php');
expect($src)->toBeString();

function _report_item_fn_body(string $src, string $fn) : string {
	$start = strpos($src, "function $fn(");
	expect($start)->not->toBeFalse();
	$end = strpos($src, "\nfunction ", $start + 1);

	return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
}

// reports_admin.php and reports_user.php print general_header() before calling
// these, so a Location header cannot be sent and the refusal must return instead
$refusals = array(
	'reports_item_edit' => "if (!cacti_authorize_resource(\$_SESSION['sess_user_id'], \$report_id, 'reports') ||",
	'reports_edit'      => "if (!empty(\$report) && !cacti_authorize_resource(",
);

foreach ($refusals as $fn => $guard_text) {
	test("$fn refuses without sending a redirect after output", function () use ($src, $fn, $guard_text) {
		$body  = _report_item_fn_body($src, $fn);
		$guard = strpos($body, $guard_text);

		expect($guard)->not->toBeFalse();

		// the guard's own closing brace sits at the guard line's indentation
		$line   = strrpos(substr($body, 0, $guard), "\n") + 1;
		$indent = substr($body, $line, $guard - $line);
		$block  = substr($body, $guard, strpos($body, "\n" . $indent . '}', $guard) - $guard);
		expect($block)->toContain("raise_message('permission_denied');");
		expect($block)->toContain('return;');
		expect($block)->not->toContain('header(');
		expect($block)->not->toContain('exit;');
	});
}

test('report redirects never point at the missing reports.php', function () use ($src) {
	expect($src)->not->toContain("header('Location: reports.php')");
});

preg_match_all("/^define\('((?:REPORTS|MESSAGE_LEVEL)_[A-Z0-9_]+)',\s*([0-9]+)\);/m", file_get_contents($root . '/include/global_constants.php'), $constants, PREG_SET_ORDER);

foreach ($constants as $constant) {
	if (!defined($constant[1])) {
		define($constant[1], (int) $constant[2]);
	}
}

if (!function_exists(__NAMESPACE__ . '\reports_form_save')) {
	$code = '';

	foreach (array('reports_form_save', 'reports_address_malformed', 'reports_item_movedown', 'reports_item_moveup', 'reports_item_edit') as $fn) {
		preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $src, $match);

		// release/1.2.31 and lts/1.2 have no address check for the save to call
		if ($fn == 'reports_address_malformed' && empty($match)) {
			continue;
		}

		expect($match)->not->toBeEmpty();

		$code .= $match[0];
	}

	// each exit follows a recorded redirect, and would otherwise end the run
	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . str_replace('exit;', 'return;', $code));
}

function isset_request_var($name) {
	return isset($GLOBALS['ro_request'][$name]);
}

function isempty_request_var($name) {
	return (!isset($GLOBALS['ro_request'][$name]) || $GLOBALS['ro_request'][$name] == '');
}

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['ro_request'][$name] ?? $default;
}

function get_request_var($name, $default = '') {
	return $GLOBALS['ro_request'][$name] ?? $default;
}

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
	return $GLOBALS['ro_request'][$name] ?? '';
}

/* lib/functions.php form_input_validate() without its logging */
function form_input_validate($field_value, $field_name, $regexp_match, $allow_nulls, $custom_message = 3) {
	if ($allow_nulls == true && $field_value == '') {
		return $field_value;
	}

	if ($allow_nulls == false && $field_value == '') {
		raise_message($custom_message);

		$_SESSION['sess_error_fields'][$field_name] = $field_name;
	} elseif ($regexp_match != '' && !preg_match('/' . $regexp_match . '/', $field_value)) {
		raise_message($custom_message);

		$_SESSION['sess_error_fields'][$field_name] = $field_name;
	}

	return $field_value;
}

function raise_message($message_id, $message = '', $message_level = 0) {
	$GLOBALS['ro_messages'][] = $message_id;
}

function is_error_message() {
	return (isset($_SESSION['sess_error_fields']) && count($_SESSION['sess_error_fields']));
}

/* lib/auth.php: user 1 stands for an admin, anyone else must own the report */
function cacti_authorize_resource($user_id, $resource_id, $resource_type) {
	$user_id     = (int) $user_id;
	$resource_id = (int) $resource_id;

	if ($user_id <= 0 || $resource_id <= 0) {
		return false;
	} elseif ($user_id == 1) {
		return true;
	} elseif ($resource_type == 'report_item') {
		$resource_id = $GLOBALS['ro_items'][$resource_id] ?? 0;
	}

	return (($GLOBALS['ro_reports'][$resource_id] ?? 0) === $user_id);
}

function db_fetch_cell_prepared($sql, $params = array()) {
	$sql = preg_replace('/\s+/', ' ', $sql);

	if ($sql == 'SELECT user_id FROM reports WHERE id = ?') {
		return (isset($GLOBALS['ro_reports'][$params[0]]) ? (string) $GLOBALS['ro_reports'][$params[0]] : false);
	} elseif ($sql == 'SELECT report_id FROM reports_items WHERE id = ?') {
		return (isset($GLOBALS['ro_items'][$params[0]]) ? (string) $GLOBALS['ro_items'][$params[0]] : false);
	} elseif (strpos($sql, 'SELECT MAX(sequence)+1 FROM reports_items') === 0) {
		return '4';
	}

	return false;
}

/* reports_item_edit() goes on to build the form, so stop once the row loads */
function db_fetch_row_prepared($sql, $params = array()) {
	$GLOBALS['ro_loaded'][] = array_map('intval', $params);

	throw new \RuntimeException('item row loaded');
}

function sql_save($save, $table_name) {
	$GLOBALS['ro_saved'][] = array($table_name, $save);

	return (empty($save['id']) ? 42 : (int) $save['id']);
}

function reports_item_resequence($report_id) {
}

function read_config_option($name) {
	return 300;
}

function get_reports_page() {
	return 'reports_user.php';
}

function header($header) {
	$GLOBALS['ro_headers'][] = $header;
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function move_item_down($table, $id, $where) {
	$GLOBALS['ro_moves'][] = array('down', $table, $id, $where);
}

function move_item_up($table, $id, $where) {
	$GLOBALS['ro_moves'][] = array('up', $table, $id, $where);
}

/* sql_save() stores an empty or numeric string in an integer column as that
   number, so compare the values a row would hold rather than their PHP types */
function saved_rows() {
	$rows = array();

	foreach ($GLOBALS['ro_saved'] as $saved) {
		foreach (array('id', 'report_id', 'user_id', 'sequence') as $column) {
			if (array_key_exists($column, $saved[1])) {
				$saved[1][$column] = (int) $saved[1][$column];
			}
		}

		$rows[] = $saved;
	}

	return $rows;
}

function report_request($id) {
	return array(
		'save_component_report' => '1',
		'id'              => $id,
		'name'            => 'Daily',
		'email'           => 'Ops Team <ops@example.com>, noc@example.com',
		'enabled'         => 'on',
		'font_size'       => '10',
		'alignment'       => '1',
		'graph_columns'   => '2',
		'graph_width'     => '300',
		'graph_height'    => '150',
		'intrvl'          => '1',
		'count'           => '1',
		'mailtime'        => '2099-01-01 10:00',
		'subject'         => '',
		'from_name'       => 'Cacti',
		'from_email'      => 'cacti@example.com',
		'bcc'             => '',
		'attachment_type' => '1',
	);
}

function item_request($report_id, $id) {
	return array(
		'save_component_report_item' => '1',
		'report_id'      => $report_id,
		'id'             => $id,
		'sequence'       => '2',
		'item_type'      => '1',
		'local_graph_id' => '12',
		'timespan'       => '7',
		'align'          => '1',
		'font_size'      => '10',
	);
}

function item_row($report_id, $id, $sequence) {
	return array(
		'id'                => $id,
		'report_id'         => $report_id,
		'sequence'          => $sequence,
		'item_type'         => '1',
		'tree_id'           => 0,
		'branch_id'         => 0,
		'tree_cascade'      => '',
		'graph_name_regexp' => '',
		'site_id'           => 0,
		'host_template_id'  => 0,
		'host_id'           => 0,
		'graph_template_id' => 0,
		'local_graph_id'    => '12',
		'timespan'          => '7',
		'item_text'         => '',
		'align'             => '1',
		'font_size'         => '10',
	);
}

function edit_item($request) {
	$GLOBALS['ro_request'] = $request;

	try {
		reports_item_edit();
	} catch (\RuntimeException $e) {
	}
}

beforeEach(function () {
	date_default_timezone_set('UTC');

	// report id => owner, item id => report id; user 1 is an admin
	$GLOBALS['ro_reports']  = array(7 => 5, 8 => 6, 9 => 5);
	$GLOBALS['ro_items']    = array(70 => 7, 80 => 8, 90 => 9);
	$GLOBALS['ro_request']  = array();
	$GLOBALS['ro_messages'] = array();
	$GLOBALS['ro_saved']    = array();
	$GLOBALS['ro_headers']  = array();
	$GLOBALS['ro_moves']    = array();
	$GLOBALS['ro_loaded']   = array();

	$_SESSION = array('sess_user_id' => 5);
});

dataset('item saves 1.2.31 allows', array(
	'owner adds an item'                 => array(5, '7', '', 'Location: reports_user.php?action=item_edit&id=7&item_id=42', item_row(7, 0, 4)),
	'owner edits an item'                => array(5, '7', '70', 'Location: reports_user.php?action=item_edit&id=7&item_id=70', item_row(7, 70, 2)),
	'admin edits another user\'s item'   => array(1, '8', '80', 'Location: reports_user.php?action=item_edit&id=8&item_id=80', item_row(8, 80, 2)),
));

test('an item save 1.2.31 allows redirects and writes as it did', function ($user, $report_id, $id, $location, $row) {
	$_SESSION['sess_user_id'] = $user;
	$GLOBALS['ro_request']    = item_request($report_id, $id);

	reports_form_save();

	expect($GLOBALS['ro_headers'])->toBe(array($location));
	expect(saved_rows())->toBe(array(array('reports_items', $row)));
	expect($GLOBALS['ro_messages'])->toBe(array('reports_item_save'));
})->with('item saves 1.2.31 allows');

dataset('item saves outside the caller\'s report', array(
	'item for another user\'s report'  => array('8', ''),
	'item id from another report'      => array('7', '80'),
));

test('item save writes nothing outside the caller\'s report', function ($report_id, $id) {
	$GLOBALS['ro_request'] = item_request($report_id, $id);

	reports_form_save();

	expect($GLOBALS['ro_saved'])->toBe(array());
	expect($GLOBALS['ro_messages'])->toContain('permission_denied');
})->with('item saves outside the caller\'s report');

dataset('report saves 1.2.31 allows', array(
	'owner saves their report'          => array(5, '7', 'Location: reports_user.php?action=edit&header=false&id=7', 5, 7),
	'owner creates a report'            => array(5, '', 'Location: reports_user.php?action=edit&header=false&id=42', 5, 0),
	'admin saves another user\'s report' => array(1, '8', 'Location: reports_user.php?action=edit&header=false&id=8', 6, 8),
));

test('a report save 1.2.31 allows redirects and keeps its owner as it did', function ($user, $id, $location, $owner, $row_id) {
	$_SESSION['sess_user_id'] = $user;
	$GLOBALS['ro_request']    = report_request($id);

	reports_form_save();

	$rows = saved_rows();

	expect($GLOBALS['ro_headers'])->toBe(array($location));
	expect($rows)->toHaveCount(1);
	expect($rows[0][0])->toBe('reports');
	expect($rows[0][1]['user_id'])->toBe($owner);
	expect($rows[0][1]['id'])->toBe($row_id);
	expect($rows[0][1]['email'])->toBe('Ops Team <ops@example.com>, noc@example.com');
	expect($rows[0][1]['from_email'])->toBe('cacti@example.com');
	expect($GLOBALS['ro_messages'])->toBe(array('reports_save'));
})->with('report saves 1.2.31 allows');

test('a refused report save sends the caller to their reports page', function () {
	$GLOBALS['ro_request'] = report_request('8');

	reports_form_save();

	expect($GLOBALS['ro_saved'])->toBe(array());
	expect($GLOBALS['ro_headers'])->toBe(array('Location: reports_user.php?header=false'));
});

dataset('item moves 1.2.31 allows', array(
	'owner moves their item' => array(5, '70', '7'),
	'admin moves any item'   => array(1, '80', '8'),
));

test('an item move 1.2.31 allows moves the same row', function ($user, $item_id, $report_id) {
	$_SESSION['sess_user_id'] = $user;

	foreach (array('down' => 'reports_item_movedown', 'up' => 'reports_item_moveup') as $direction => $fn) {
		$GLOBALS['ro_request'] = array('item_id' => $item_id, 'id' => $report_id);
		$GLOBALS['ro_moves']   = array();

		$fn = __NAMESPACE__ . '\\' . $fn;
		$fn();

		expect($GLOBALS['ro_moves'])->toBe(array(array($direction, 'reports_items', $item_id, 'report_id=' . $report_id)));
	}
})->with('item moves 1.2.31 allows');

test('an item move leaves an item of another report alone', function () {
	foreach (array('reports_item_movedown', 'reports_item_moveup') as $fn) {
		$GLOBALS['ro_request'] = array('item_id' => '80', 'id' => '7');

		$fn = __NAMESPACE__ . '\\' . $fn;
		$fn();
	}

	expect($GLOBALS['ro_moves'])->toBe(array());
});

dataset('item edits 1.2.31 allows', array(
	'owner opens their item'                    => array(5, '7', '70'),
	'owner opens an item of their other report' => array(5, '9', '90'),
	'admin opens any item'                      => array(1, '8', '80'),
));

test('an item edit 1.2.31 allows loads the same row', function ($user, $report_id, $item_id) {
	$_SESSION['sess_user_id'] = $user;

	edit_item(array('id' => $report_id, 'item_id' => $item_id));

	expect($GLOBALS['ro_loaded'])->toBe(array(array((int) $item_id)));
	expect($GLOBALS['ro_messages'])->toBe(array());
	expect($GLOBALS['ro_headers'])->toBe(array());
})->with('item edits 1.2.31 allows');

test('an item edit refuses an item outside the caller\'s report', function () {
	edit_item(array('id' => '7', 'item_id' => '80'));
	edit_item(array('id' => '8', 'item_id' => '80'));

	expect($GLOBALS['ro_loaded'])->toBe(array());
	expect($GLOBALS['ro_messages'])->toBe(array('permission_denied', 'permission_denied'));
	expect($GLOBALS['ro_headers'])->toBe(array());
});

dataset('item ids filed under a different report', array(
	'owner pairs their two reports' => array(5, '7', '90'),
	'admin pairs two reports'       => array(1, '8', '70'),
));

test('an item edit refuses an item filed under a different report', function ($user, $report_id, $item_id) {
	$_SESSION['sess_user_id'] = $user;

	edit_item(array('id' => $report_id, 'item_id' => $item_id));

	expect($GLOBALS['ro_loaded'])->toBe(array());
	expect($GLOBALS['ro_messages'])->toBe(array('permission_denied'));
	expect($GLOBALS['ro_headers'])->toBe(array());
})->with('item ids filed under a different report');
