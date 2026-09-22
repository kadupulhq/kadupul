<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace DropdownLabelFollowupTest;

class State {
	public static $theme = 'classic';
	public static $autocomplete = 0;
}
function get_selected_theme() { return State::$theme; }
function read_config_option($name) { return State::$autocomplete; }
function db_fetch_assoc($sql) { return array(array('id' => '7', 'name' => 'Existing')); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function null_out_substitutions($value) { return $value; }

foreach (array('form_dropdown', 'form_callback', 'draw_edit_control', 'form_hidden_box', 'html_create_list', 'html_escape') as $helper) {
	$file = in_array($helper, array('html_create_list', 'html_escape'), true) ? 'html.php' : 'html_form.php';
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/' . $file);
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing production helper: ' . $helper);
	}
	eval('namespace DropdownLabelFollowupTest; ' . $match[0]);
}

function capture($callback, $session = array()) {
	$hadSession = isset($_SESSION);
	$saved = $_SESSION ?? null;
	$_SESSION = $session;
	ob_start();
	try {
		$callback();
		$output = ob_get_contents();
		$after = $_SESSION;
	} finally {
		ob_end_clean();
		if ($hadSession) { $_SESSION = $saved; } else { unset($_SESSION); }
	}
	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $output . '</body></html>');
	foreach (array('script', 'img', 'svg') as $tag) {
		expect($doc->getElementsByTagName($tag)->length)->toBe(0);
	}
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attribute) {
			expect(strncmp($attribute->name, 'on', 2))->not->toBe(0);
		}
	}
	return array($doc, $after);
}

dataset('dropdown label followup payloads', array(
	'None', 'None &amp; All', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'</option></select><img src=x onerror=alert(1)><script>alert(1)</script>',
	'&#39;&quot;&amp;', '&amp;#39;', '`', 'a.b:c[d]'
));

test('none labels remain text and retain selection in both dropdown implementations', function ($helper, $payload) {
	list($doc) = capture(function () use ($helper, $payload) {
		if ($helper === 'form_dropdown') {
			form_dropdown('field', array(), '', '', '', $payload, '');
		} else {
			form_callback('field', 'SELECT fixture', 'name', 'id', 'lookup', '', '', $payload, '');
		}
	});
	$option = $doc->getElementsByTagName('option')->item(0);
	expect($option->textContent)->toBe(html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	expect($option->getAttribute('value'))->toBe('0');
	expect($option->hasAttribute('selected'))->toBeTrue();
	expect($doc->getElementsByTagName('select')->length)->toBe(1);
})->with(array('form_dropdown', 'form_callback'))->with('dropdown label followup payloads');

test('callback select classes remain attributes for classic and positive-autocomplete settings', function ($theme, $payload) {
	State::$theme = $theme;
	State::$autocomplete = $theme === 'classic' ? 0 : 1;
	try {
		list($doc, $session) = capture(function () use ($payload) {
			form_callback('field', 'SELECT fixture', 'name', 'id', 'lookup', '7', 'Existing', 'None', '', $payload);
		}, array('sess_error_fields' => array('field' => true)));
	} finally {
		State::$theme = 'classic';
		State::$autocomplete = 0;
	}
	$select = $doc->getElementsByTagName('select')->item(0);
	expect($select->getAttribute('class'))->toBe(html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' txtErrorTextBox');
	expect($select->attributes->length)->toBe(3);
	expect($doc->getElementsByTagName('option')->item(1)->hasAttribute('selected'))->toBeTrue();
	expect($session['sess_error_fields'])->toBe(array());
})->with(array('classic', 'modern'))->with('dropdown label followup payloads');

test('template dropdown labels are text while the hidden selection is unchanged', function ($payload) {
	list($doc) = capture(function () use ($payload) {
		$field = array('method' => 'template_drop_array', 'value' => '7', 'array' => array('7' => $payload));
		draw_edit_control('field', $field);
	});
	expect($doc->getElementsByTagName('em')->item(0)->textContent)->toBe(html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	$input = $doc->getElementsByTagName('input')->item(0);
	expect($input->getAttribute('type'))->toBe('hidden');
	expect($input->getAttribute('name'))->toBe('field');
	expect($input->getAttribute('value'))->toBe('7');
})->with('dropdown label followup payloads');

test('empty none labels remain omitted and default selections survive', function () {
	list($doc) = capture(function () {
		form_dropdown('field', array(array('id' => '7', 'name' => 'Existing')), 'name', 'id', '', '', '7');
	});
	expect($doc->getElementsByTagName('option')->length)->toBe(1);
	expect($doc->getElementsByTagName('option')->item(0)->hasAttribute('selected'))->toBeTrue();
});

test('empty callback classes omit the attribute and retain defaults', function ($class) {
	list($doc) = capture(function () use ($class) {
		form_callback('field', 'SELECT fixture', 'name', 'id', 'lookup', '7', '', '', 'Existing', $class);
	});
	$select = $doc->getElementsByTagName('select')->item(0);
	expect($select->hasAttribute('class'))->toBeFalse();
	expect($select->getAttribute('name'))->toBe('field');
	expect($doc->getElementsByTagName('option')->item(0)->hasAttribute('selected'))->toBeTrue();
})->with(array('', null, false));

test('custom controls retain their explicitly trusted HTML contract', function () {
	$field = array('method' => 'custom', 'value' => '<strong>Trusted</strong>');
	ob_start();
	try {
		draw_edit_control('field', $field);
		expect(ob_get_contents())->toBe('<strong>Trusted</strong>');
	} finally { ob_end_clean(); }
});

test('new escaping preserves configured legacy charset and replaces malformed UTF-8', function ($charset) {
	// Keep non-UTF-8 bytes out of dataset labels and the JUnit XML report.
	$payload = $charset === 'ISO-8859-1' ? "caf\xe9 &amp; tea" : "broken\xff<";
	$expected = $charset === 'ISO-8859-1' ? "caf\xe9 &amp; tea" : "broken\xef\xbf\xbd&lt;";
	$original = ini_get('default_charset');
	ini_set('default_charset', $charset);
	ob_start();
	try {
		form_dropdown('field', array(), '', '', '', $payload, '');
		form_callback('callback', 'SELECT fixture', 'name', 'id', 'lookup', '', '', $payload, '', $payload);
		$field = array('method' => 'template_drop_array', 'value' => '7', 'array' => array('7' => $payload));
		draw_edit_control('template', $field);
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
		ini_set('default_charset', $original);
	}
	expect(substr_count($output, $expected))->toBe(4);
})->with(array('ISO-8859-1', 'UTF-8'));
