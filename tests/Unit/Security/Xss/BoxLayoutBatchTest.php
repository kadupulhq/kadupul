<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace BoxLayoutBatchTest;

class State {
	public static $page = 'devices.php';
	public static $request = array();
	public static $help = false;
}
function __($text) { return $text; }
function __esc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function get_current_page() { return State::$page; }
function isempty_request_var($key) { return empty(State::$request[$key]); }
function isset_request_var($key) { return isset(State::$request[$key]); }
function get_nfilter_request_var($key) { return State::$request[$key]; }
function clean_up_name($value) { return preg_replace('/[^a-zA-Z0-9_]/', '_', $value); }
function html_help_page($page) { return State::$help; }
function is_realm_allowed($realm) { return true; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function decoded($text) { return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html.php');
foreach (array('html_start_box', 'html_end_box') as $helper) {
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace BoxLayoutBatchTest; ' . $match[0]);
}

function capture($callback) {
	$hadConfig = isset($GLOBALS['config']);
	$saved = $GLOBALS['config'] ?? null;
	$GLOBALS['config'] = array('poller_id' => 1);
	ob_start();
	try {
		$callback();
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
		if ($hadConfig) { $GLOBALS['config'] = $saved; } else { unset($GLOBALS['config']); }
		State::$request = array();
		State::$page = 'devices.php';
		State::$help = false;
	}
	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $output . '</body></html>');
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
	expect($doc->getElementsByTagName('img')->length)->toBe(0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attr) { expect(strncmp($attr->name, 'on', 2))->not->toBe(0); }
	}
	expect($output)->not->toContain(chr(96));
	return $doc;
}

dataset('box metadata payloads', array('100%', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', '&amp;#39;', chr(96), 'a.b:c[d]'));

test('box layout attributes are safe in titled and untitled table and div branches', function ($div, $title, $payload) {
	$suffix = (new \ReflectionFunction(__NAMESPACE__ . '\\html_start_box'))->getStaticVariables()['table_suffix'];
	State::$page = $payload . '.php';
	$expectedId = decoded(basename(State::$page, '.php') . $suffix);
	$doc = capture(function () use ($div, $title, $payload) {
		html_start_box($title, $payload, $div, $payload, $payload, '');
		html_end_box(false, $div);
	});
	$xpath = new \DOMXPath($doc);
	$outer = $xpath->query('//body/div')->item(0);
	expect($outer->getAttribute('id'))->toBe($expectedId);
	expect($outer->getAttribute('style'))->toBe('width:' . decoded($payload) . ';text-align:' . decoded($payload) . ';');
	expect($outer->attributes->length)->toBe(3);
	$child = $xpath->query('//*[@id]')->item(1);
	expect($child->tagName)->toBe($div ? 'div' : 'table');
	expect($child->getAttribute('id'))->toBe($expectedId . '_child');
	if (!$div) { expect($child->getAttribute('style'))->toBe('padding:' . decoded($payload) . 'px;'); }
	expect($doc->getElementsByTagName('strong')->length)->toBe($title === '' ? 0 : 1);
})->with(array(false, true))->with(array('', '<strong>Trusted title</strong>'))->with('box metadata payloads');

test('legacy and icon toolbar attributes are safe without changing navigation', function ($icons, $payload) {
	$doc = capture(function () use ($icons, $payload) {
		$toolbar = $icons ? array(array('id' => $payload, 'title' => $payload, 'href' => $payload, 'class' => $payload, 'callback' => true)) : $payload;
		html_start_box('Title', '100%', true, 0, 'left', $toolbar, $payload);
		html_end_box(false, true);
	});
	$link = $doc->getElementsByTagName('a')->item(0);
	expect($link->getAttribute('href'))->toBe(decoded($payload));
	expect($link->getAttribute('class'))->toBe('linkOverDark');
	expect($link->parentNode->getAttribute('title'))->toBe(decoded($payload));
	expect($link->parentNode->attributes->length)->toBe(2);
	expect($link->attributes->length)->toBe($icons ? 3 : 2);
	if ($icons) { expect($link->getAttribute('id'))->toBe(decoded($payload)); }
	expect($doc->getElementsByTagName('i')->item(0)->getAttribute('class'))->toBe($icons ? decoded($payload) : 'fa fa-plus');
})->with(array(false, true))->with('box metadata payloads');

test('toolbar defaults and callback strictness are preserved', function () {
	$doc = capture(function () {
		html_start_box('Title', '100%', true, 0, 'left', array(array(), array('callback' => 1, 'class' => '')));
		html_end_box(false, true);
	});
	foreach ($doc->getElementsByTagName('a') as $link) {
		expect($link->getAttribute('href'))->toBe('#');
		expect($link->getAttribute('class'))->toBe('');
		expect($link->hasAttribute('id'))->toBeFalse();
		expect($link->parentNode->getAttribute('title'))->toBe('Add');
	}
	foreach ($doc->getElementsByTagName('i') as $icon) { expect($icon->getAttribute('class'))->toBe('fa fa-plus'); }
});

test('request prefix precedence and unique suffixes remain unchanged', function ($request, $prefix) {
	State::$request = $request;
	$suffix = (new \ReflectionFunction(__NAMESPACE__ . '\\html_start_box'))->getStaticVariables()['table_suffix'];
	$doc = capture(function () {
		html_start_box('', '100%', true, 0, 'left', '');
		html_end_box(false, true);
		html_start_box('', '100%', true, 0, 'left', '');
		html_end_box(false, true);
	});
	$outer = (new \DOMXPath($doc))->query('//body/div');
	expect($outer->item(0)->getAttribute('id'))->toBe($prefix . $suffix);
	expect($outer->item(1)->getAttribute('id'))->toBe($prefix . ($suffix + 1));
})->with(array(
	array(array('action' => 'edit', 'report' => 'daily', 'tab' => 'all'), 'devices_edit'),
	array(array('report' => 'daily', 'tab' => 'all'), 'devices_daily'),
	array(array('tab' => 'all'), 'devices_all'),
));

test('help page metadata is encoded and help remains once per request', function () {
	State::$help = '/help/\'" data-bad="yes.html';
	$doc = capture(function () {
		for ($i = 0; $i < 2; $i++) {
			html_start_box('Title', '100%', true, 0, 'left', '');
			html_end_box(false, true);
		}
	});
	$links = $doc->getElementsByTagName('a');
	expect($links->length)->toBe(1);
	expect($links->item(0)->getAttribute('data-page'))->toBe('\'" data-bad="yes.html');
	expect($links->item(0)->attributes->length)->toBe(3);
});
