<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace GraphDrilldownOutputTest;

class State {
	public static $mode = '1';
	public static $calls = array();
	public static $allowed = true;
}
function aggregate_build_children_url($id) { State::$calls['aggregate'] = $id; return '<strong>Aggregate</strong>'; }
function db_fetch_cell_prepared($sql, $args) { State::$calls['database'][] = $args; return 7; }
function is_realm_allowed($realm) { return State::$allowed; }
function read_config_option($key) { return 'on'; }
function read_user_setting($key) { return State::$mode; }
function __esc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8', false); }
function api_plugin_hook($type, $args) { State::$calls['plugin'] = array($type, $args); }

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html.php');
preg_match('/function graph_drilldown_icons\\(.*?^\\}/ms', $source, $match);
eval('namespace GraphDrilldownOutputTest; ' . $match[0]);

test('real drill-down renderer encodes identifiers including the popup JavaScript boundary', function ($mode, $payload) {
	State::$calls = array();
	State::$mode = $mode;
	State::$allowed = true;
	$hadConfig = isset($GLOBALS['config']);
	$saved = $GLOBALS['config'] ?? null;
	$GLOBALS['config'] = array('url_path' => '/kadupul/');
	ob_start();
	try {
		graph_drilldown_icons($payload, 'graph_buttons_thumbnails', 3, 4);
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
		if ($hadConfig) { $GLOBALS['config'] = $saved; } else { unset($GLOBALS['config']); }
	}
	$doc = new \DOMDocument();
	// Legacy template-edit markup closes the void img element explicitly.
	$previousErrors = libxml_use_internal_errors(true);
	try {
		$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $output . '</body></html>');
	} finally { libxml_clear_errors(); libxml_use_internal_errors($previousErrors); }
	$xpath = new \DOMXPath($doc);
	$decoded = html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
	expect($doc->getElementsByTagName('img')->length)->toBe(7);
	foreach (array('utils' => 'util', 'csvexport' => 'csv', 'mrtg' => 'mrtg') as $class => $suffix) {
		expect($xpath->query('//a[@class="iconLink ' . $class . '"]')->item(0)->getAttribute('id'))->toBe('graph_' . $decoded . '_' . $suffix);
	}
	foreach ($xpath->query('//*[@data-graph]') as $element) { expect($element->getAttribute('data-graph'))->toBe($decoded); }
	$handlers = $xpath->query('//*[@onclick]');
	expect($handlers->length)->toBe($mode === '2' ? 1 : 0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attr) {
			if ($attr->name !== 'onclick') { expect(strncmp($attr->name, 'on', 2))->not->toBe(0); }
		}
	}
	if ($mode === '2') {
		$handler = $handlers->item(0)->getAttribute('onclick');
		expect(preg_match('/^window\\.open\\(("(?:\\\\.|[^"\\\\])*")\s*,\s*("(?:\\\\.|[^"\\\\])*")\s*,/', $handler, $arguments))->toBe(1);
		expect(json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR))->toBe('/kadupul/graph_realtime.php?top=0&left=0&local_graph_id=' . rawurlencode($payload));
		expect(json_decode($arguments[2], true, 512, JSON_THROW_ON_ERROR))->toBe('popup_' . $payload);
		expect($handler)->toEndWith("width=650,height=300');return false");
	}
	expect(State::$calls['aggregate'])->toBe($payload);
	expect(State::$calls['database'])->toBe(array(array($payload), array($payload)));
	expect(State::$calls['plugin'])->toBe(array('graph_buttons_thumbnails', array('hook' => 'graph_buttons_thumbnails',
		'local_graph_id' => $payload, 'rra' => 0, 'view_type' => 'tree', 'tree_id' => 3, 'branch_id' => 4)));
	expect($doc->getElementsByTagName('strong')->item(0)->textContent)->toBe('Aggregate');
})->with(array('', '1', '2'))->with(array('007', 'réseau 日本語', '\'" onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', '&amp;#39;', chr(96), 'a.b:c[d]'));
