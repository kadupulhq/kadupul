<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace DocumentMarkupTest;

function __($text) { return $text; }
function __esc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function get_cacti_version_text() { return 'LTS fixture'; }
const CACTI_LOCALE = 'fr-FR';

class RealtimeState {
	public static $enabled = 'on';
	public static $directory = true;
	public static $writable = true;
}
function read_config_option($name) { return $name === 'realtime_enabled' ? RealtimeState::$enabled : '/fixture'; }
function is_dir($path) { return RealtimeState::$directory; }
function is_writable($path) { return RealtimeState::$writable; }

function render($markup, $file, $queryId = 42) {
	$snmp_query = array('id' => $queryId);
	ob_start();
	try {
		if ($file === 'graphs_new.php') {
			eval('namespace DocumentMarkupTest; print "' . $markup . '";');
		} else {
			eval('namespace DocumentMarkupTest; ?>' . $markup);
		}
		return ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

$baseline = json_decode(file_get_contents(__DIR__ . '/document-markup-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = array();
foreach ($baseline as $index => $entry) { $cases[$entry['file'] . ':' . $index] = array($entry); }
dataset('document markup', $cases);

test('document corrections use production fragments and retain visible text', function ($entry) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $entry['file']);
	expect($source)->toContain($entry['after']);
	$before = render($entry['before'], $entry['file']);
	$after = render($entry['after'], $entry['file']);
	expect(trim(strip_tags($after)))->toBe(trim(strip_tags($before)));
	if (strpos($entry['after'], '<html lang=') !== false) {
		expect($after)->toContain("lang='fr-FR'");
	}
	if (strpos($entry['before'], '<select') !== false) {
		expect($after)->toMatch('/aria-label=\x27[^\x27]+\x27/');
		$restored = preg_replace('/\s+aria-label=\x27[^\x27]+\x27/', '', $after);
		expect($restored)->toBe($before);
	}
	if ($entry['file'] === 'graphs_new.php') {
		expect($after)->toContain("for='sgg_42'");
	}
	if (strpos($entry['before'], '<legend>') !== false) {
		expect($after)->toContain("<h1 class='loginHeading'>");
	}
	if (strpos($entry['before'], '<iframe') !== false) {
		expect($after)->toContain("title='Cacti website'");
	}
	if (strpos($entry['before'], '<font') !== false || strpos($entry['before'], '<tt>') !== false) {
		expect($after)->not->toMatch('/<(?:font|tt)\b/');
		expect($after)->toContain('<span');
	}
})->with('document markup');

test('Midwinter invalidates its imported core stylesheet cache after heading changes', function () {
	$theme = dirname(__DIR__, 4) . '/include/themes/midwinter/';
	$css = file_get_contents($theme . 'main.css');
	expect($css)->toContain('core.css?' . md5_file($theme . 'css/media/core.css'));
});

test('realtime error branches emit localized complete documents without changing messages', function ($enabled, $dir, $write, $message) {
	RealtimeState::$enabled = $enabled;
	RealtimeState::$directory = $dir;
	RealtimeState::$writable = $write;
	$source = file_get_contents(dirname(__DIR__, 4) . '/graph_realtime.php');
	$start = strpos($source, '$realtime_error =');
	$end = strpos($source, '$selectedTheme = get_selected_theme();', $start);
	$block = substr($source, $start, $end - $start);
	expect(substr_count($block, 'exit;'))->toBe(1);
	// Only replace process termination so the emitted error document can be inspected in this test.
	$block = str_replace('exit;', 'return;', $block);
	ob_start();
	try {
		eval('namespace DocumentMarkupTest; ' . $block);
		$markup = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	if ($message === '') {
		expect($markup)->toBe('');
		return;
	}
	expect($markup)->toStartWith('<!DOCTYPE html>');
	$doc = new \DOMDocument();
	$doc->loadHTML($markup);
	expect($doc->documentElement->getAttribute('lang'))->toBe('fr-FR');
	expect($doc->getElementsByTagName('title')->length)->toBe(1);
	expect($doc->getElementsByTagName('title')->item(0)->textContent)->toBe('Cacti Real-time Graphing');
	expect($doc->getElementsByTagName('strong')->item(0)->textContent)->toBe($message);
})->with(array(
	array('', true, true, 'Real-time has been disabled by your administrator.'),
	array('on', false, true, 'The Image Cache Directory does not exist.  Please first create it and set permissions and then attempt to open another Real-time graph.'),
	array('on', true, false, 'The Image Cache Directory is not writable.  Please set permissions and then attempt to open another Real-time graph.'),
	array('on', true, true, '')
));

test('realtime accessible names reuse existing gettext catalog messages', function () {
	$root = dirname(__DIR__, 4);
	$catalog = file_get_contents($root . '/locales/po/cacti.pot');
	foreach (array('Timespan', 'Refresh Interval', 'Size') as $message) {
		expect($catalog)->toContain('msgid "' . $message . '"');
	}
});

test('graph query captions target their matching production select on multi-query pages', function () use ($baseline) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/graphs_new.php');
	expect(preg_match('/<select class=\x27dqselect\x27[^\n]+>/', $source, $match))->toBe(1);
	$caption = array_values(array_filter($baseline, function ($entry) {
		return $entry['file'] === 'graphs_new.php';
	}))[0]['after'];
	$markup = '';
	foreach (array(42, 99) as $queryId) {
		$markup .= render($caption . $match[0] . "<option value='7' selected>Fixture</option></select>",
			'graphs_new.php', $queryId);
	}
	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><body>' . $markup . '</body></html>');
	$xpath = new \DOMXPath($doc);
	foreach (array(42, 99) as $queryId) {
		$id = 'sgg_' . $queryId;
		$labels = $xpath->query('//label[@for="' . $id . '"]');
		$controls = $xpath->query('//select[@id="' . $id . '"]');
		expect($labels->length)->toBe(1);
		expect($controls->length)->toBe(1);
		expect($labels->item(0)->textContent)->toBe('Select a Graph Type to Create');
		expect($controls->item(0)->getAttribute('name'))->toBe($id);
		expect($controls->item(0)->getAttribute('data-prefix'))->toBe($queryId . ',');
	}
});
