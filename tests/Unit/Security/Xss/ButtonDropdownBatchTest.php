<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace ButtonDropdownBatchTest;

class Locales {
	public static $values = array();
}
function get_installed_locales() { return Locales::$values; }
function __($value) { return $value; }
function cacti_count($value) { return count($value); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function null_out_substitutions($value) { return $value; }
function decoded($value) { return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

foreach (array('form_button', 'form_submit', 'form_dropdown', 'form_droplanguage', 'html_create_list') as $helper) {
	$file = $helper === 'html_create_list' ? 'html.php' : 'html_form.php';
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/' . $file);
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace ButtonDropdownBatchTest; ' . $match[0]);
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
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
	expect($doc->getElementsByTagName('img')->length)->toBe(0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attr) {
			expect(strncmp($attr->name, 'on', 2))->not->toBe(0);
		}
	}
	expect($output)->not->toContain(chr(96));
	return array($doc, $after);
}

dataset('button dropdown payloads', array('field', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', '&amp;#39;', chr(96), 'a.b:c[d]'));

test('buttons retain raw callback keys and safe DOM attributes', function ($helper, $payload) {
	list($doc, $session) = capture(function () use ($helper, $payload) {
		call_user_func(__NAMESPACE__ . '\\' . $helper, $payload, $payload, $payload, 'clicked()');
	});
	$inputs = $doc->getElementsByTagName('input');
	expect($inputs->length)->toBe(1);
	$input = $inputs->item(0);
	foreach (array('id', 'name', 'value', 'title') as $attr) {
		expect($input->getAttribute($attr))->toBe(decoded($payload));
	}
	expect($input->attributes->length)->toBe(6);
	expect($input->getAttribute('type'))->toBe($helper === 'form_button' ? 'button' : 'submit');
	expect($session['form_click_actions'])->toBe(array($payload => 'clicked()'));
})->with(array('form_button', 'form_submit'))->with('button dropdown payloads');

test('dropdown metadata stays encoded while raw selections and callbacks are preserved', function ($language, $payload) {
	Locales::$values = array($payload => $payload);
	list($doc, $session) = capture(function () use ($language, $payload) {
		if ($language) {
			form_droplanguage($payload, '', '', $payload, '', '', $payload, 'changed()');
		} else {
			form_dropdown($payload, array($payload => $payload), '', '', $payload, '', '', $payload, 'changed()');
		}
	});
	$select = $doc->getElementsByTagName('select')->item(0);
	foreach (array('id', 'name', 'class') as $attr) { expect($select->getAttribute($attr))->toBe(decoded($payload)); }
	expect($select->attributes->length)->toBe(3);
	$option = $doc->getElementsByTagName('option')->item(0);
	expect($option->getAttribute('value'))->toBe(decoded($payload));
	expect($option->textContent)->toBe(decoded($payload));
	expect($option->hasAttribute('selected'))->toBeTrue();
	expect($session['form_change_actions'])->toBe(array($payload => 'changed()'));
	if ($language) {
		$parts = explode('-', $payload);
		$flag = decoded(strtolower(count($parts) > 1 ? $parts[1] : $parts[0]));
		expect($option->getAttribute('data-class'))->toBe('fi-' . $flag);
		expect($doc->getElementsByTagName('span')->item(0)->getAttribute('class'))->toBe('fi fis fi-' . $flag);
	}
})->with(array(false, true))->with('button dropdown payloads');

test('dropdown defaults and session restoration preserve selection and error classes', function ($language, $saved) {
	Locales::$values = array('default' => 'Default', 'saved' => 'Saved');
	list($doc, $session) = capture(function () use ($language) {
		if ($language) { form_droplanguage('field', '', '', '', '', 'default', 'custom'); }
		else { form_dropdown('field', Locales::$values, '', '', '', 'None', 'default', 'custom'); }
	}, array('sess_field_values' => array('field' => $saved), 'sess_error_fields' => array('field' => true)));
	$xpath = new \DOMXPath($doc);
	$selected = $xpath->query('//option[@selected]');
	expect($selected->length)->toBe(1);
	expect($selected->item(0)->getAttribute('value'))->toBe(empty($saved) ? 'default' : $saved);
	expect($doc->getElementsByTagName('select')->item(0)->getAttribute('class'))->toBe('custom txtErrorTextBox');
	expect($session['sess_error_fields'])->not->toHaveKey('field');
	expect($session)->not->toHaveKey('form_change_actions');
})->with(array(false, true))->with(array('', '0', 'saved'));

test('language flags retain their existing region and language rules', function ($locale, $flag) {
	Locales::$values = array($locale => 'Language');
	list($doc) = capture(function () use ($locale) { form_droplanguage('locale', '', '', $locale, '', ''); });
	expect($doc->getElementsByTagName('option')->item(0)->getAttribute('data-class'))->toBe('fi-' . $flag);
})->with(array(array('en-US', 'us'), array('fr', 'fr'), array('zh-Hans-CN', 'hans')));

test('empty optional metadata and none-entry behavior remain unchanged', function () {
	list($doc, $session) = capture(function () {
		form_button('button', 'Go');
		form_submit('submit', 'Save');
		form_dropdown('select', array(), '', '', '', 'None &amp; All', '');
	});
	foreach ($doc->getElementsByTagName('input') as $input) { expect($input->hasAttribute('title'))->toBeFalse(); }
	expect($doc->getElementsByTagName('select')->item(0)->hasAttribute('class'))->toBeFalse();
	$option = $doc->getElementsByTagName('option')->item(0);
	expect($option->textContent)->toBe('None & All');
	expect($option->hasAttribute('selected'))->toBeTrue();
	expect($session)->toBe(array());
});
