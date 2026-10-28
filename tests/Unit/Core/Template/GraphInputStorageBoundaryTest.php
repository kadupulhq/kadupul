<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace GraphInputStorageBoundaryTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 4) . '/lib/graph_template_input.php';

final class Fixture {
	public static array $request = array();
	public static array $inputs = array();
	public static array $writes = array();
	public static array $transactions = array();
	public static array $errors = array();
}

final class Redirect extends \RuntimeException {}
const MESSAGE_LEVEL_ERROR = 3;

function get_nfilter_request_var($name) { return Fixture::$request[$name] ?? ''; }
function get_request_var($name) { return get_nfilter_request_var($name); }
function get_filter_request_var($name) { return get_nfilter_request_var($name); }
function isset_request_var($name) { return array_key_exists($name, Fixture::$request); }
function isempty_request_var($name) { return !isset_request_var($name) || get_nfilter_request_var($name) === ''; }
function set_request_var($name, $value) { Fixture::$request[$name] = $value; }
function parse_validate_graph_template_id($name) { return 0; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log(...$args) {}
function __($message) { return $message; }
function raise_message($id, $message = '', $level = 0) { Fixture::$errors[] = $id; }
function is_error_message() { return count(Fixture::$errors) > 0; }
function header($value): never { throw new Redirect($value); }

function db_fetch_cell_prepared($sql, $parameters = array()) {
	if (str_contains($sql, 'FROM graph_local')) {
		return 2;
	}
	throw new \RuntimeException('Unexpected cell lookup');
}

function db_fetch_assoc_prepared($sql, $parameters = array()) {
	if (str_contains($sql, 'FROM graph_template_input_defs')) {
		return array(array('id' => 101), array('id' => 102));
	}
	if (str_contains($sql, 'FROM graph_template_input')) {
		return Fixture::$inputs;
	}
	throw new \RuntimeException('Unexpected input lookup');
}

function db_execute_prepared($sql, $parameters = array()) {
	Fixture::$writes[] = $parameters;
	return true;
}
function db_begin_transaction() { Fixture::$transactions[] = 'begin'; return true; }
function db_commit_transaction() { Fixture::$transactions[] = 'commit'; return true; }
function db_rollback_transaction() { Fixture::$transactions[] = 'rollback'; return true; }

$source = file_get_contents(dirname(__DIR__, 4) . '/graphs.php');
if ($source === false) {
	throw new \RuntimeException('Unable to read graph form controller');
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'form_save'));

beforeEach(function () {
	Fixture::$request = array('save_component_input' => '1', 'local_graph_id' => '9', 'host_id' => '1', 'host_id_prev' => '1', 'graph_template_id' => '2', 'graph_template_id_prev' => '2');
	Fixture::$inputs = array();
	Fixture::$writes = array();
	Fixture::$transactions = array();
	Fixture::$errors = array();
});

function submit(array $request, array $inputs): void {
	Fixture::$request = $request + Fixture::$request;
	Fixture::$inputs = $inputs;
	try {
		form_save();
	} catch (Redirect $redirect) {
		expect($redirect->getMessage())->toStartWith('Location: graphs.php');
	}
}

test('graph input limits follow the shipped column ranges and character lengths', function ($column, $value, $allowed) {
	expect(\graph_template_input_value_is_allowed($column, $value))->toBe($allowed);
})->with(array(
	array('graph_type_id', '255', true), array('graph_type_id', '256', false),
	array('consolidation_function_id', '255', true), array('consolidation_function_id', '256', false),
	array('task_item_id', '4294967295', true), array('task_item_id', '4294967296', false),
	array('cdef_id', '16777215', true), array('cdef_id', '16777216', false),
	array('cdef_id', '0000000012', true),
	array('vdef_id', '16777215', true), array('vdef_id', '16777216', false),
	array('gprint_id', '16777215', true), array('gprint_id', '16777216', false),
	array('line_width', '99.99', true), array('line_width', '100', false),
	array('line_width', '1,25', true), array('line_width', '99,99', true),
	array('line_width', '999.9', false), array('line_width', '1.234', false), array('line_width', '1,234', false),
	array('dashes', str_repeat('1', 20), true), array('dashes', str_repeat('1', 21), false),
	array('textalign', str_repeat('a', 10), true), array('textalign', str_repeat('a', 11), false),
	array('text_format', str_repeat('界', 255), true), array('text_format', str_repeat('界', 256), false),
	array('value', str_repeat('界', 255), true), array('value', "\xff", false),
	array('text_format', array('invalid'), false), array('text_format', "safe\ttext", true),
));

test('the graph controller binds a localized width in decimal storage form', function () {
	submit(array('line_width_7' => '1,25'), array(array('id' => 7, 'column_name' => 'line_width')));
	expect(Fixture::$writes)->toBe(array(array('1.25', 101), array('1.25', 102)))
		->and(Fixture::$transactions)->toBe(array('begin', 'commit'));
});

test('empty optional numeric form values retain zero storage semantics', function ($column) {
	submit(array($column . '_7' => ''), array(array('id' => 7, 'column_name' => $column)));
	expect(Fixture::$writes)->toBe(array(array('0', 101), array('0', 102)));
})->with(array('color_id', 'line_width', 'dash_offset', 'sequence'));

test('the graph controller preserves a valid multibyte label', function () {
	$label = str_repeat('界', 100);
	submit(array('text_format_7' => $label), array(array('id' => 7, 'column_name' => 'text_format')));
	expect(Fixture::$writes)->toBe(array(array($label, 101), array($label, 102)));
});

test('an omitted graph input is not overwritten', function () {
	submit(array('alpha_7' => 'FF'), array(array('id' => 7, 'column_name' => 'alpha'), array('id' => 8, 'column_name' => 'line_width')));
	expect(Fixture::$writes)->toBe(array(array('FF', 101), array('FF', 102)));
});

test('a later invalid input stops every write before the transaction starts', function () {
	submit(array('alpha_7' => 'FF', 'line_width_8' => '999.9'), array(array('id' => 7, 'column_name' => 'alpha'), array('id' => 8, 'column_name' => 'line_width')));
	expect(Fixture::$writes)->toBe(array())->and(Fixture::$transactions)->toBe(array())->and(Fixture::$errors)->not->toBeEmpty();
});
