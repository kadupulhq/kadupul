<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace StatefulControlBatchTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_form.php');
foreach (array('form_checkbox', 'form_radio_button', 'form_text_area', 'form_multi_dropdown', 'draw_edit_form') as $helper) {
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace StatefulControlBatchTest; ' . $match[0]);
}

function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return cacti_sizeof($value); }
function html_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false); }
function html_purify($value) { return $value; }
function read_config_option($key) { return ''; }
function display_tooltip($value) { return ''; }
function get_current_page() { return 'sites.php'; }
function kill_session_var($key) { unset($_SESSION[$key]); }
function db_fetch_cell_prepared($sql, $params) { return 'one,two'; }
class CactiSecureHeaders {
	public static function getNonceAttribute() { return 'nonce="test"'; }
}

function draw_edit_control($name, &$field) {
	form_text_area($name, 'value', 3, 20, '', '', 'changed()');
}

function capture($callback, $session = array()) {
	$had = isset($_SESSION);
	$saved = $_SESSION ?? null;
	$_SESSION = $session;
	ob_start();
	try {
		$callback();
		$output = ob_get_contents();
		$after = $_SESSION;
	} finally {
		ob_end_clean();
		if ($had) { $_SESSION = $saved; } else { unset($_SESSION); }
	}
	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $output . '</body></html>');
	expect($doc->getElementsByTagName('img')->length)->toBe(0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attribute) {
			expect(strncmp($attribute->name, 'on', 2))->not->toBe(0);
		}
	}
	expect(preg_replace('/<script\\b[^>]*>.*?<\\/script>/s', '', $output))->not->toContain(chr(96));
	return array($doc, $after, $output);
}

dataset('stateful helpers', array('checkbox', 'radio', 'textarea', 'multi'));
dataset('stateful payloads', array('field', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'</textarea><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', chr(96), 'a.b:c[d]',
	'&#39;', '&amp;#39;'));

test('spacer headers preserve text and collapsible markup without injection', function ($payload, $collapsible) {
	list($doc) = capture(function () use ($payload, $collapsible) {
		draw_edit_form(array('config' => array('no_form_tag' => true), 'fields' => array($payload => array(
			'method' => 'spacer', 'friendly_name' => $payload, 'collapsible' => $collapsible,
		))));
	});
	$decoded = html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$xpath = new \DOMXPath($doc);
	$header = $xpath->query("//div[contains(@class,'spacer')]")->item(0);
	expect($header->getAttribute('id'))->toBe('row_' . $decoded);
	expect($header->getAttribute('class'))->toBe('spacer formHeader' . ($collapsible ? ' collapsible' : ''));
	expect($xpath->query("//div[@class='formHeaderText']")->item(0)->textContent)->toBe($decoded);
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
})->with('stateful payloads')->with(array(false, true));

test('textarea placeholder omission remains explicit for empty inputs', function ($placeholder) {
	list($doc) = capture(function () use ($placeholder) {
		form_text_area('field', 'value', 3, 20, '', '', '', $placeholder);
	});
	expect($doc->getElementsByTagName('textarea')->item(0)->hasAttribute('placeholder'))->toBeFalse();
})->with(array(array(''), array(null), array(false)));

test('stateful controls encode paired identifiers, values and metadata', function ($helper, $payload) {
	list($doc, $session) = capture(function () use ($helper, $payload) {
		switch ($helper) {
		case 'checkbox': form_checkbox($payload, 'on', $payload, '', 1, $payload, 'changed()', $payload, true); break;
		case 'radio': form_radio_button($payload, $payload, $payload, $payload, '', $payload, 'changed()'); break;
		case 'textarea': form_text_area($payload, $payload, $payload, $payload, '', $payload, 'changed()', $payload); break;
		case 'multi': form_multi_dropdown($payload, array($payload => $payload), array(array('id' => $payload)), 'id', '', 'changed()'); break;
		}
	});
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
	$decoded = html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$tag = $helper === 'textarea' ? 'textarea' : ($helper === 'multi' ? 'select' : 'input');
	$control = $doc->getElementsByTagName($tag)->item(0);
	$id = $helper === 'radio' ? $decoded . '_' . $decoded : $decoded;
	expect($control->getAttribute('id'))->toBe($id);
	expect($control->getAttribute('name'))->toBe($decoded . ($helper === 'multi' ? '[]' : ''));
	$key = $helper === 'radio' ? $payload . '_' . $payload : $payload;
	expect($session['form_change_actions'])->toBe(array($key => 'changed()'));
	if ($helper === 'checkbox' || $helper === 'radio') {
		expect($control->hasAttribute('checked'))->toBeTrue();
		expect($control->getAttribute('aria-checked'))->toBe('true');
		expect($control->getAttribute('class'))->toBe('formCheckbox ' . $decoded);
		$labels = $doc->getElementsByTagName('label');
		expect($labels->item(1)->getAttribute('for'))->toBe($id);
		expect($labels->item(1)->textContent)->toBe($decoded);
		if ($helper === 'radio') { expect($control->getAttribute('value'))->toBe($decoded); }
		else { expect($control->getAttribute('title'))->toBe($decoded); }
	} elseif ($helper === 'textarea') {
		expect($control->textContent)->toBe($decoded);
		foreach (array('rows', 'cols', 'placeholder') as $attribute) {
			expect($control->getAttribute($attribute))->toBe($decoded);
		}
	} else {
		$option = $doc->getElementsByTagName('option')->item(0);
		expect($option->getAttribute('value'))->toBe($decoded);
		expect($option->textContent)->toBe($decoded);
		expect($option->hasAttribute('selected'))->toBeTrue();
		expect($control->getAttribute('class'))->toBe('multiselect multiselect');
	}
})->with('stateful helpers')->with('stateful payloads');

test('edit forms bind callbacks by script-safe literal IDs and clear registrations', function ($payload) {
	list($doc, $after) = capture(function () use ($payload) {
		draw_edit_form(array('config' => array('post_to' => $payload, 'form_name' => $payload, 'enctype' => $payload),
			'fields' => array($payload => array('method' => 'textarea', 'friendly_name' => 'Field'))));
	}, array('form_click_actions' => array($payload => 'clicked()')));
	$decoded = html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$form = $doc->getElementsByTagName('form')->item(0);
	foreach (array('action', 'name', 'enctype') as $attr) { expect($form->getAttribute($attr))->toBe($decoded); }
	$xpath = new \DOMXPath($doc);
	$row = $xpath->query("//div[contains(@class,'formRow')]")->item(0);
	expect($row->getAttribute('id'))->toBe('row_' . $decoded);
	$scripts = $doc->getElementsByTagName('script');
	expect($scripts->length)->toBe(1);
	expect($scripts->item(0)->getAttribute('nonce'))->toBe('test');
	$script = $scripts->item(0)->textContent;
	expect(preg_match_all('/document\\.getElementById\\(("(?:[^"\\\\]|\\\\.)*")\\)/', $script, $matches))->toBe(2);
	foreach ($matches[1] as $json) {
		$lookupId = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		expect($lookupId)->toBe($decoded);
		expect($lookupId)->toBe($doc->getElementsByTagName('textarea')->item(0)->getAttribute('id'));
	}
	expect($script)->toContain(".on('change', function() { changed(); });");
	expect($script)->toContain(".on('click', function() { clicked(); });");
	expect($after)->not->toHaveKey('form_change_actions');
	expect($after)->not->toHaveKey('form_click_actions');
})->with('stateful payloads');

test('saved field values and error consumption keep raw session keys', function ($saved) {
	list($doc, $after) = capture(function () {
		form_text_area('field', '', 3, 20, 'default', '', '');
		form_checkbox('check', '', 'Check', 'on');
		form_radio_button('radio', '', 'on', 'Radio', 'on');
	}, array('sess_field_values' => array('field' => $saved, 'check' => $saved, 'radio' => $saved),
		'sess_error_fields' => array('field' => true)));
	expect($doc->getElementsByTagName('textarea')->item(0)->textContent)->toBe(empty($saved) ? 'default' : $saved);
	expect($doc->getElementsByTagName('textarea')->item(0)->getAttribute('class'))->toContain('txtErrorTextBox');
	expect($after['sess_error_fields'])->not->toHaveKey('field');
	foreach ($doc->getElementsByTagName('input') as $input) {
		expect($input->hasAttribute('checked'))->toBe(empty($saved) || $saved === 'on');
	}
})->with(array('', '0', 'off', 'on'));

test('multi-selection accepts array, CSV and settings fallback', function ($selected) {
	list($doc) = capture(function () use ($selected) {
		form_multi_dropdown('field', array('one' => 'One', 'two' => 'Two', 'three' => 'Three'), $selected, 'id');
	});
	$options = $doc->getElementsByTagName('option');
	expect($options->item(0)->hasAttribute('selected'))->toBeTrue();
	expect($options->item(1)->hasAttribute('selected'))->toBeTrue();
	expect($options->item(2)->hasAttribute('selected'))->toBeFalse();
})->with(array(array(array(array('id' => 'one'), array('id' => 'two'))), array('one,two'), array(null)));

test('existing checkbox records do not acquire the new-record default', function ($current, $previous, $checked) {
	list($doc) = capture(function () use ($current, $previous) {
		form_checkbox('field', $previous, 'Label', 'on', $current);
	});
	expect($doc->getElementsByTagName('input')->item(0)->hasAttribute('checked'))->toBe($checked);
})->with(array(array(0, '', true), array(5, '', false), array(0, 'off', false), array(5, 'on', true)));

test('multi-select preserves error consumption and its legacy fixed classes', function () {
	list($doc, $after) = capture(function () {
		form_multi_dropdown('field', array(), array(), 'id', 'custom');
	}, array('sess_error_fields' => array('field' => true)));
	expect($doc->getElementsByTagName('option')->length)->toBe(0);
	expect($doc->getElementsByTagName('select')->item(0)->getAttribute('class'))->toBe('multiselect multiselect');
	expect($after['sess_error_fields'])->not->toHaveKey('field');
});

test('invalid UTF-8 IDs agree between controls and callback literals', function () {
	$name = "bad\xFF";
	list($doc) = capture(function () use ($name) {
		draw_edit_form(array('config' => array('no_form_tag' => true),
			'fields' => array($name => array('method' => 'textarea', 'friendly_name' => 'Field'))));
	});
	$id = $doc->getElementsByTagName('textarea')->item(0)->getAttribute('id');
	expect($id)->toBe("bad\u{FFFD}");
	$script = $doc->getElementsByTagName('script')->item(0)->textContent;
	expect(preg_match('/document\\.getElementById\\(("(?:[^"\\\\]|\\\\.)*")\\)/', $script, $match))->toBe(1);
	expect(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR))->toBe($id);
	expect($doc->getElementsByTagName('form')->length)->toBe(0);
});
