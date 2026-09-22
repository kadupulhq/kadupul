<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace GraphRendererBatchTest;

class State {
	public static $settings = array();
	public static $allowed = true;
	public static $calls = array();
	public static $lookups = array();
	public static $row = array('host_id' => 1, 'disabled' => '');
}
class CactiSecureHeaders {
	public static function getNonceAttribute() { return 'nonce="test-nonce"'; }
}
function read_user_setting($key) {
	return State::$settings[$key] ?? array('num_columns' => 2, 'page_refresh' => 30,
		'custom_fonts' => '', 'show_graph_title' => 'on', 'default_width' => 100, 'default_height' => 50)[$key];
}
function read_config_option($key) { return '12'; }
function get_current_graph_start() { return -3600; }
function get_current_graph_end() { return -60; }
function is_realm_allowed($realm) { return State::$allowed; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function __($value) { return $value; }
function graph_drilldown_icons(...$args) { State::$calls[] = $args; }
function db_fetch_row_prepared($sql, $args) { State::$lookups[] = $args; return State::$row; }
function decoded($text) { return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html.php');
foreach (array('html_graph_area', 'html_graph_thumbnail_area') as $helper) {
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace GraphRendererBatchTest; ' . $match[0]);
}

beforeEach(function () {
	State::$settings = array();
	State::$allowed = true;
	State::$calls = array();
	State::$lookups = array();
	State::$row = array('host_id' => 1, 'disabled' => '');
});

function graph($id = 1, $query = 'Query') {
	return array('local_graph_id' => $id, 'host_id' => 1, 'disabled' => '', 'width' => 300,
		'height' => 100, 'title_cache' => 'Title', 'data_query_name' => $query);
}

function render($thumbnail, $graphs, $columns = 0, $header = '', $empty = '') {
	ob_start();
	try {
		$helper = __NAMESPACE__ . ($thumbnail ? '\\html_graph_thumbnail_area' : '\\html_graph_area');
		$helper($graphs, $empty, '', $header, $columns, 7, 8);
		$output = ob_get_contents();
	} finally { ob_end_clean(); }
	$doc = new \DOMDocument();
	// Legacy full-size output includes an extra closing row when a row is full.
	$previousErrors = libxml_use_internal_errors(true);
	try {
		$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table>' . $output . '</table></body></html>');
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors($previousErrors);
	}
	expect($doc->getElementsByTagName('script')->length)->toBe(1);
	$script = $doc->getElementsByTagName('script')->item(0);
	expect($script->getAttribute('nonce'))->toBe('test-nonce');
	expect($script->textContent)->toContain('var refreshMSeconds = 30000;', 'var graph_start     = -3600;', 'var graph_end       = -60;');
	expect($doc->getElementsByTagName('img')->length)->toBe(0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attr) { expect(strncmp($attr->name, 'on', 2))->not->toBe(0); }
	}
	expect($output)->not->toContain(chr(96));
	return new \DOMXPath($doc);
}

dataset('graph renderer payloads', array('17', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', '&amp;#39;', chr(96), 'a.b:c[d]'));

test('graph renderers encode metadata but pass raw identifiers to drill-down handlers', function ($thumbnail, $payload) {
	$graph = graph($payload, $payload);
	$graph['width'] = $graph['height'] = $graph['title_cache'] = $payload;
	State::$settings = array('default_width' => $payload, 'default_height' => $payload,
		'custom_fonts' => 'on', 'title_size' => $payload);
	$xpath = render($thumbnail, array($graph));
	$wrapper = $xpath->query('//div[@class="graphWrapper"]')->item(0);
	expect($wrapper->getAttribute('id'))->toBe('wrapper_' . decoded($payload));
	foreach (array('graph_width', 'graph_height') as $attr) { expect($wrapper->getAttribute($attr))->toBe(decoded($payload)); }
	if (!$thumbnail) { expect($wrapper->getAttribute('title_font_size'))->toBe(decoded($payload)); }
	expect($wrapper->attributes->length)->toBe($thumbnail ? 4 : 6);
	expect($xpath->query('//td[@class="noprint graphDrillDown"]')->item(0)->getAttribute('id'))->toBe('dd' . decoded($payload));
	expect($xpath->query('//span[@class="center"]')->item(0)->textContent)->toBe(decoded($payload));
	if ($thumbnail) {
		expect($xpath->query('//td[@class="graphSubHeaderColumn textHeaderDark"]')->item(0)->textContent)->toBe('Data Query: ' . decoded($payload));
	}
	expect(State::$calls)->toBe(array(array($payload, $thumbnail ? 'graph_buttons_thumbnails' : 'graph_buttons', 7, 8)));
})->with(array(false, true))->with('graph renderer payloads');

test('permissions, title visibility, disabled state and default dimensions are preserved', function ($thumbnail, $allowed) {
	State::$allowed = $allowed;
	State::$settings['show_graph_title'] = '';
	$graph = graph();
	$graph['disabled'] = 'on';
	unset($graph['title_cache']);
	$xpath = render($thumbnail, array($graph), 0);
	expect($xpath->query('//span[@class="center"]')->length)->toBe(0);
	expect($xpath->query('//td[@class="noprint graphDrillDown"]')->length)->toBe($allowed ? 1 : 0);
	$outer = $xpath->query('//td[@class="graphWrapperOuter"]')->item(0);
	expect($outer->getAttribute('data-disabled'))->toBe('true');
	expect($outer->getAttribute('style'))->toBe('width:50%;');
	$wrapper = $xpath->query('//div[@class="graphWrapper"]')->item(0);
	expect($wrapper->getAttribute('graph_width'))->toBe($thumbnail ? '100' : '300');
	expect($wrapper->getAttribute('graph_height'))->toBe($thumbnail ? '50' : '100');
	if (!$thumbnail) { expect($wrapper->getAttribute('title_font_size'))->toBe('12'); }
})->with(array(false, true))->with(array(false, true));

test('host lookup retains raw parameters and populates disabled state', function ($thumbnail) {
	$graph = graph('007');
	unset($graph['host_id']);
	State::$row = array('host_id' => 2, 'disabled' => 'on');
	$xpath = render($thumbnail, array($graph), 1);
	expect(State::$lookups)->toBe(array(array('007')));
	expect($xpath->query('//td[@class="graphWrapperOuter"]')->item(0)->getAttribute('data-disabled'))->toBe('true');
})->with(array(false, true));

test('thumbnail groups remain consecutive and template grouping takes precedence', function () {
	$graphs = array(graph(1, 'A'), graph(2, 'A'), graph(3, 'B'), graph(4, 'A'), graph(5, 'Ignored'));
	$graphs[4]['graph_template_name'] = 'Template';
	$xpath = render(true, $graphs, 2, '<tr><td><strong>Trusted header</strong></td></tr>');
	$groups = $xpath->query('//td[@class="graphSubHeaderColumn textHeaderDark"]');
	expect($groups->length)->toBe(3);
	foreach (array('A', 'B', 'A') as $index => $query) {
		expect($groups->item($index)->textContent)->toBe('Data Query: ' . $query);
		expect($groups->item($index)->getAttribute('colspan'))->toBe('2');
	}
	expect($xpath->query('//div[@class="graphWrapper"]')->length)->toBe(5);
	expect($xpath->query('//strong')->item(0)->textContent)->toBe('Trusted header');
});

test('missing thumbnail hosts and trusted empty messages keep existing behavior', function ($thumbnail) {
	$xpath = render($thumbnail, array(), 2, '', '<strong>No graphs</strong>');
	expect($xpath->query('//em/strong')->item(0)->textContent)->toBe('No graphs');
	if ($thumbnail) {
		$graph = graph();
		unset($graph['host_id']);
		State::$row = array();
		$xpath = render(true, array($graph));
		expect($xpath->query('//div[@class="graphWrapper"]')->length)->toBe(0);
	}
})->with(array(false, true));
