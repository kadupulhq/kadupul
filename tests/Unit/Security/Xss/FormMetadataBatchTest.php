<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace FormMetadataBatchTest;

class Database {
	public static $colors = array();
	public static $current = '';
	public static $parameters = array();
}
function db_fetch_assoc($sql) { return Database::$colors; }
function db_fetch_cell_prepared($sql, $parameters) {
	Database::$parameters = $parameters;
	return Database::$current;
}
function __($format, ...$values) { return sprintf($format, ...$values); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function decoded($value) { return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_form.php');
foreach (array('draw_edit_control', 'form_color_dropdown', 'form_hidden_box', 'form_checkbox') as $helper) {
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace FormMetadataBatchTest; ' . $match[0]);
}

function capture($callback) {
	$hadSession = isset($_SESSION);
	$saved = $_SESSION ?? null;
	$_SESSION = array();
	ob_start();
	try {
		$callback();
		$output = ob_get_contents();
		$session = $_SESSION;
	} finally {
		ob_end_clean();
		if ($hadSession) { $_SESSION = $saved; } else { unset($_SESSION); }
	}
	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table><tr><td>' . $output . '</td></tr></table></body></html>');
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
	expect($doc->getElementsByTagName('img')->length)->toBe(0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attr) {
			expect(strncmp($attr->name, 'on', 2))->not->toBe(0);
		}
	}
	expect($output)->not->toContain(chr(96));
	return array($doc, $session);
}

dataset('form metadata payloads', array('field', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', '&amp;#39;', chr(96), 'a.b:c[d]'));

test('color metadata is encoded without changing raw selection and callbacks', function ($payload) {
	Database::$colors = array(array('id' => $payload, 'hex' => $payload, 'name' => $payload));
	Database::$current = $payload;
	list($doc, $session) = capture(function () use ($payload) {
		form_color_dropdown($payload, $payload, '', '', $payload, 'changed()');
	});
	$select = $doc->getElementsByTagName('select')->item(0);
	expect($select->attributes->length)->toBe(4);
	foreach (array('id', 'name') as $attr) { expect($select->getAttribute($attr))->toBe(decoded($payload)); }
	expect($select->getAttribute('class'))->toBe('colordropdown ' . decoded($payload));
	expect($select->getAttribute('style'))->toBe('background-color: #' . decoded($payload) . ';');
	$option = $doc->getElementsByTagName('option')->item(0);
	expect($option->attributes->length)->toBe(4);
	expect($option->getAttribute('value'))->toBe(decoded($payload));
	expect($option->getAttribute('data-color'))->toBe(decoded($payload));
	expect($option->getAttribute('style'))->toBe('background-color: #' . decoded($payload) . ';');
	expect($option->textContent)->toBe(decoded($payload . ' (' . $payload . ')'));
	expect($option->hasAttribute('selected'))->toBeTrue();
	expect(Database::$parameters)->toBe(array($payload));
	expect($session['form_change_actions'])->toBe(array($payload => 'this.style.backgroundColor=this.options[this.selectedIndex].style.backgroundColor;changed()'));
})->with('form metadata payloads');

test('checkbox group IDs are safe in both layouts and retain raw child callbacks', function ($flex, $payload) {
	list($doc, $session) = capture(function () use ($flex, $payload) {
		$field = array('method' => 'checkbox_group', 'type' => $flex ? 'flex' : '', 'friendly_name' => 'Group',
			'items' => array($payload => array('value' => 'on', 'friendly_name' => 'Child', 'on_change' => 'changed()')));
		draw_edit_control($payload, $field);
	});
	$group = $doc->getElementsByTagName('div')->item(0);
	expect($group->getAttribute('id'))->toBe(decoded($payload) . '_group');
	expect($group->getAttribute('class'))->toBe('checkboxgroup1' . ($flex ? ' flexContainer' : ''));
	expect($group->attributes->length)->toBe(2);
	expect($doc->getElementsByTagName('input')->item(0)->getAttribute('name'))->toBe(decoded($payload));
	expect($session['form_change_actions'])->toBe(array($payload => 'changed()'));
})->with(array(false, true))->with('form metadata payloads');

test('read-only fields encode visible text but preserve literal hidden values', function ($payload) {
	list($doc) = capture(function () use ($payload) {
		$field = array('method' => 'readonly', 'value' => $payload);
		draw_edit_control($payload, $field);
	});
	expect($doc->getElementsByTagName('em')->item(0)->textContent)->toBe(decoded($payload));
	$hidden = $doc->getElementsByTagName('input')->item(0);
	expect($hidden->getAttribute('value'))->toBe($payload);
	expect($hidden->getAttribute('name'))->toBe($payload);
})->with('form metadata payloads');

test('color defaults, unnamed colors, loose selection and none entry remain compatible', function () {
	Database::$colors = array(array('id' => 7, 'hex' => 'aAbBcc', 'name' => ''), array('id' => 8, 'hex' => 'FFFFFF', 'name' => 'White'));
	Database::$current = 'aAbBcc';
	list($doc, $session) = capture(function () { form_color_dropdown('color', '', 'None &amp; All', '07'); });
	expect(Database::$parameters)->toBe(array('07'));
	expect($doc->getElementsByTagName('select')->item(0)->getAttribute('class'))->toBe('colordropdown');
	$options = $doc->getElementsByTagName('option');
	expect($options->length)->toBe(3);
	expect($options->item(0)->textContent)->toBe('None & All');
	expect($options->item(0)->hasAttribute('selected'))->toBeFalse();
	expect($options->item(1)->textContent)->toBe('Cacti Color (aAbBcc)');
	expect($options->item(1)->hasAttribute('selected'))->toBeTrue();
	expect($options->item(2)->hasAttribute('selected'))->toBeFalse();
	expect($session['form_change_actions']['color'])->toBe('this.style.backgroundColor=this.options[this.selectedIndex].style.backgroundColor;');
});

test('empty color inventory and trusted custom markup remain unchanged', function () {
	Database::$colors = array();
	Database::$current = '';
	list($doc) = capture(function () {
		form_color_dropdown('empty', '', '', '');
		$field = array('method' => 'custom', 'value' => '<strong>Trusted custom</strong>');
		draw_edit_control('custom', $field);
	});
	expect($doc->getElementsByTagName('option')->length)->toBe(0);
	expect($doc->getElementsByTagName('strong')->item(0)->textContent)->toBe('Trusted custom');
});
