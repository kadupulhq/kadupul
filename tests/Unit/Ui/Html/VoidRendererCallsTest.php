<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace VoidRendererCallsTest;

class State {
	public static $calls = array();
}

function record_call($name, $args) {
	State::$calls[] = array($name, $args);
	print '<span>renderer output</span>';
}
function graph_drilldown_icons(...$args) { record_call(__FUNCTION__, $args); }
function html_site_filter(...$args) { record_call(__FUNCTION__, $args); }
function html_host_filter(...$args) { record_call(__FUNCTION__, $args); }
function html_common_header(...$args) { record_call(__FUNCTION__, $args); }
function html_graph_tabs_right(...$args) { record_call(__FUNCTION__, $args); }
function draw_login_status(...$args) { record_call(__FUNCTION__, $args); }
function form_text_box(...$args) { record_call(__FUNCTION__, $args); }
function form_dropdown(...$args) { record_call(__FUNCTION__, $args); }
function api_device_remove_multi(...$args) { record_call(__FUNCTION__, $args); }
function get_request_var($name) { return 'fixture-' . $name; }
function __($text, ...$args) { return $text; }
const CACTI_VERSION = 'fixture';

function run_statement($statement) {
	$graph = array('local_graph_id' => 7);
	$tree_id = 8;
	$branch_id = 9;
	$host_where = $devices_where = 'fixture condition';
	$using_guest_account = true;
	$ids = array(7, 9);
	$auth_realms = array(0 => 'Local');
	$user_realm = 0;
	$usernames = array(array('id' => 7, 'username' => 'fixture'));
	State::$calls = array();
	ob_start();
	try {
		// Only fixed repository-owned call expressions from the baseline/source are evaluated.
		eval('namespace VoidRendererCallsTest; ' . $statement);
		return array(ob_get_contents(), State::$calls);
	} finally {
		ob_end_clean();
	}
}

$baseline = json_decode(file_get_contents(__DIR__ . '/void-call-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
dataset('void call baseline', array_map(function ($entry) { return array($entry); }, $baseline));

test('all 25 distinct Sonar findings are represented', function () use ($baseline) {
	expect(count($baseline))->toBe(25);
	expect(count(array_unique(array_column($baseline, 'key'))))->toBe(25);
});

test('void calls retain their arguments and output without consuming a nonexistent result', function ($entry) {
	$lines = file(dirname(__DIR__, 4) . '/' . $entry['file'], FILE_IGNORE_NEW_LINES);
	expect($lines)->not->toBeFalse();
	$current = $lines[$entry['line'] - 1];
	expect($current)->toBe($entry['after']);
	$pattern = '/(?:print |echo |\$host_id = )?' . preg_quote($entry['fn'], '/') . '\([^;]*;/';
	expect(preg_match($pattern, $entry['before'], $before))->toBe(1);
	expect(preg_match($pattern, $current, $after))->toBe(1);
	expect($after[0])->toStartWith($entry['fn'] . '(');
	$oldResult = run_statement($before[0]);
	$newResult = run_statement($after[0]);
	expect($newResult)->toBe($oldResult);
	expect($newResult[0])->toBe('<span>renderer output</span>');
	expect(count($newResult[1]))->toBe(1);
})->with('void call baseline');

test('production callees do not return a value', function ($file, $name) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	expect($source)->not->toBeFalse();
	$pattern = '/^function ' . preg_quote($name, '/') . '\([^\n]*\).*?\n\}/ms';
	expect(preg_match($pattern, $source, $match))->toBe(1);
	$tokens = token_get_all('<?php ' . $match[0]);
	foreach ($tokens as $index => $token) {
		if (!is_array($token) || $token[0] !== T_RETURN) {
			continue;
		}
		$next = $index + 1;
		while (isset($tokens[$next]) && is_array($tokens[$next])
			&& in_array($tokens[$next][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
			$next++;
		}
		expect($tokens[$next] ?? null)->toBe(';');
	}
})->with(array(
	array('lib/html.php', 'graph_drilldown_icons'),
	array('lib/html.php', 'html_site_filter'),
	array('lib/html.php', 'html_host_filter'),
	array('lib/html.php', 'html_common_header'),
	array('lib/html.php', 'html_graph_tabs_right'),
	array('lib/functions.php', 'draw_login_status'),
	array('lib/html_form.php', 'form_text_box'),
	array('lib/html_form.php', 'form_dropdown'),
	array('lib/api_device.php', 'api_device_remove_multi'),
));
