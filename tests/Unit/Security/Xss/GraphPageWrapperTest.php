<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace GraphPageWrapperTest;

class State {
	public static $value = '';
	public static $allowed = true;
	public static $custom = 'on';
	public static $plugin = array();
}
function get_request_var($name) { return State::$value; }
function is_realm_allowed($realm) { return State::$allowed; }
function read_user_setting($key) { return $key === 'custom_fonts' ? State::$custom : State::$value; }
function read_config_option($key) { return $key === 'realtime_enabled' ? 'on' : State::$value; }
function __esc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function html_escape($text) { return htmlspecialchars(str_replace('`', '&#96;', $text), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, ini_get('default_charset') ?: 'UTF-8', false); }
function api_plugin_hook($name, $args) { State::$plugin = array($name, $args); }

function render($payload, $allowed, $custom, $action = 'view') {
	$source = file_get_contents(dirname(__DIR__, 4) . '/graph.php');
	if (preg_match_all("/<div class='graphWrapper'.*?<\/td><\\?php \\} \\?>/s", $source, $matches) !== 2) {
		throw new \RuntimeException('Missing production graph block');
	}
	State::$value = $payload;
	State::$allowed = $allowed;
	State::$custom = $custom;
	State::$plugin = array();
	$graph = array('local_graph_id' => $payload, 'width' => $payload, 'height' => $payload, 'title_cache' => 'Graph title');
	$rra = array('id' => $payload);
	$graph_start = $graph_end = $payload;
	$graph_template_id = 0;
	$aggregate_url = '<strong>Trusted aggregate</strong>';
	$config = array('url_path' => '/kadupul/');
	ob_start();
	try {
		eval('namespace GraphPageWrapperTest; ?>' . $matches[0][$action === 'zoom' ? 1 : 0]);
		$output = ob_get_contents();
	} finally { ob_end_clean(); }
	expect($output)->not->toContain('`');
	$doc = new \DOMDocument();
	$previous = libxml_use_internal_errors(true);
	try {
		$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table><tr><td>' . $output . '</tr></table></body></html>');
	} finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
	return $doc;
}

dataset('graph page payloads', array('007', '-3600', 'réseau 日本語', '\'" onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&amp;#39;&#39;&quot;&amp;', '`', 'a.b:c[d]'));

test('graph page attributes preserve values without introducing markup', function ($allowed, $custom, $payload) {
	$doc = render($payload, $allowed, $custom);
	$xpath = new \DOMXPath($doc);
	$wrapper = $xpath->query('//div[@class="graphWrapper"]')->item(0);
	expect($wrapper->getAttribute('id'))->toBe('wrapper_' . $payload);
	foreach (array('graph_id', 'rra_id', 'graph_width', 'graph_height', 'graph_start', 'graph_end', 'title_font_size') as $name) {
		expect($wrapper->getAttribute($name))->toBe($payload);
	}
	expect($wrapper->attributes->length)->toBe(9);
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
	expect($doc->getElementsByTagName('img')->length)->toBe($allowed ? 3 : 0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attribute) {
			if ($attribute->name !== 'onclick') { expect(strncmp($attribute->name, 'on', 2))->not->toBe(0); }
		}
	}
	if (!$allowed) {
		expect(State::$plugin)->toBe(array());
		return;
	}
	expect($xpath->query('//td[@class="graphDrillDown noprint"]')->item(0)->getAttribute('id'))->toBe('dd' . $payload);
	$utility = $xpath->query('//a[@class="iconLink utils"]')->item(0);
	expect($utility->getAttribute('id'))->toBe('graph_' . $payload . '_util');
	foreach (array('graph_start', 'graph_end', 'rra_id') as $name) { expect($utility->getAttribute($name))->toBe($payload); }
	expect($xpath->query('//a[@class="iconLink csv"]')->item(0)->getAttribute('id'))->toBe('graph_' . $payload . '_csv');
	$handlers = $xpath->query('//*[@onclick]');
	expect($handlers->length)->toBe(1);
	$handler = $handlers->item(0)->getAttribute('onclick');
	expect(preg_match('/^window\\.open\\(("(?:\\\\.|[^"\\\\])*")\s*,\s*("(?:\\\\.|[^"\\\\])*")\s*,/', $handler, $args))->toBe(1);
	expect(json_decode($args[1], true, 512, JSON_THROW_ON_ERROR))->toBe('/kadupul/graph_realtime.php?top=0&left=0&local_graph_id=' . rawurlencode($payload));
	expect(json_decode($args[2], true, 512, JSON_THROW_ON_ERROR))->toBe('popup_' . $payload);
	expect($handler)->toEndWith("width=650,height=300');return false");
	expect(State::$plugin)->toBe(array('graph_buttons', array('hook' => 'view', 'local_graph_id' => $payload, 'rra' => $payload, 'view_type' => $payload)));
	expect($doc->getElementsByTagName('strong')->item(0)->textContent)->toBe('Trusted aggregate');
})->with(array(false, true))->with(array('on', ''))->with('graph page payloads');

test('zoom uses the same safe attribute boundary without changing its controls or plugin arguments', function ($allowed, $custom, $payload) {
	$doc = render($payload, $allowed, $custom, 'zoom');
	$xpath = new \DOMXPath($doc);
	$wrapper = $xpath->query('//div[@class="graphWrapper"]')->item(0);
	expect($wrapper->getAttribute('id'))->toBe('wrapper_' . $payload);
	foreach (array('graph_id', 'rra_id', 'graph_width', 'graph_height', 'title_font_size') as $name) {
		expect($wrapper->getAttribute($name))->toBe($payload);
	}
	expect($wrapper->attributes->length)->toBe(7);
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
	expect($doc->getElementsByTagName('img')->length)->toBe($allowed ? 2 : 0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attribute) { expect(strncmp($attribute->name, 'on', 2))->not->toBe(0); }
	}
	if (!$allowed) {
		expect(State::$plugin)->toBe(array());
		return;
	}
	expect($xpath->query('//td[@class="graphDrillDown noprint"]')->item(0)->getAttribute('id'))->toBe('dd' . $payload);
	$links = $xpath->query('//a[@class="iconLink properties"]');
	expect($links->length)->toBe(2);
	expect($links->item(0)->getAttribute('id'))->toBe('graph_' . $payload . '_properties');
	expect($links->item(1)->getAttribute('id'))->toBe('graph_' . $payload . '_csv');
	expect(State::$plugin)->toBe(array('graph_buttons', array('hook' => 'zoom', 'local_graph_id' => $payload, 'rra' => $payload, 'view_type' => $payload)));
})->with(array(false, true))->with(array('on', ''))->with('graph page payloads');
