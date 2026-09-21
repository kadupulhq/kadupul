<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace FormControlAttributeTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_form.php');
foreach (array('form_file', 'form_text_box', 'form_filepath_box', 'form_dirpath_box', 'form_font_box') as $helper) {
	if (!preg_match('/function ' . $helper . '\(.*?^\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing form helper: ' . $helper);
	}
	eval('namespace FormControlAttributeTest; ' . $match[0]);
}

function __($text) {
	return $text;
}

function __esc($text) {
	return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}

function decoded($value) {
	return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function render_control($helper, $args, $session = array()) {
	$hadSession = isset($_SESSION);
	$savedSession = $_SESSION ?? null;
	$_SESSION = $session;
	ob_start();
	try {
		call_user_func_array(__NAMESPACE__ . '\\' . $helper, $args);
		$output = ob_get_contents();
		$after = $_SESSION;
	} finally {
		ob_end_clean();
		if ($hadSession) {
			$_SESSION = $savedSession;
		} else {
			unset($_SESSION);
		}
	}
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $output . '</body></html>');
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	foreach ($document->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attribute) {
			expect(strncmp($attribute->name, 'on', 2))->not->toBe(0);
		}
	}
	expect($output)->not->toContain(chr(96));
	return array($document, $after);
}

dataset('control helpers', array('form_text_box', 'form_filepath_box', 'form_dirpath_box', 'form_font_box'));
dataset('control payloads', array(
	'ordinary' => '0042',
	'Unicode' => 'réseau 日本語',
	'quotes' => '\'" autofocus onfocus="alert(1)',
	'tags' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'pre-escaped' => '&lt;example&gt;&amp;&quot;&#39;&#96;',
	'backtick' => '`value',
));

test('text controls keep untrusted metadata inside its attribute', function ($helper, $payload) {
	$args = array($payload, $payload, 'default', $payload, $payload, $payload, 1);
	if ($helper === 'form_text_box') {
		$args[] = $payload;
		$args[] = $payload;
	} elseif ($helper === 'form_font_box') {
		$args[] = $payload;
	} elseif ($helper === 'form_filepath_box') {
		$args[] = array('text' => $payload, 'error' => true);
	}
	list($document) = render_control($helper, $args);
	$inputs = $document->getElementsByTagName('input');
	expect($inputs->length)->toBe(1);
	$input = $inputs->item(0);
	foreach (array('id', 'name', 'value', 'size', 'maxlength', 'type') as $attribute) {
		expect($input->getAttribute($attribute))->toBe(decoded($payload));
	}
	if ($helper === 'form_text_box' || $helper === 'form_font_box') {
		expect($input->getAttribute('placeholder'))->toBe(decoded($payload));
	}
	if ($helper === 'form_text_box') {
		expect($input->getAttribute('title'))->toBe(decoded($payload));
	}
	if ($helper === 'form_filepath_box') {
		expect($document->getElementsByTagName('span')->item(0)->getAttribute('title'))->toBe(decoded($payload));
	}
})->with('control helpers')->with('control payloads');

test('file upload labels and metadata stay associated', function ($payload) {
	list($document, $session) = render_control('form_file', array($payload, $payload, $payload),
		array('sess_error_fields' => array($payload => true)));
	$input = $document->getElementsByTagName('input')->item(0);
	foreach (array('id', 'name', 'size', 'accept') as $attribute) {
		expect($input->getAttribute($attribute))->toBe(decoded($payload));
	}
	expect($input->getAttribute('type'))->toBe('file');
	expect($input->getAttribute('class'))->toContain('txtErrorTextBox');
	expect($document->getElementsByTagName('label')->item(0)->getAttribute('for'))->toBe(decoded($payload));
	expect($session['sess_error_fields'])->toBe(array());
})->with('control payloads');

test('text controls preserve default current and session values', function ($helper, $mode) {
	$name = 'field\'&quot;';
	$args = array($name, '', 'default &amp; value', 0, 30, 'text', $mode === 'existing' ? 7 : 0);
	$session = array();
	if ($mode === 'session') {
		$session = array('sess_field_values' => array($name => 'restored &lt;value&gt;'),
			'sess_error_fields' => array($name => true));
	}
	list($document, $after) = render_control($helper, $args, $session);
	$input = $document->getElementsByTagName('input')->item(0);
	$expected = $mode === 'existing' ? '' : ($mode === 'session' ? 'restored <value>' : 'default & value');
	expect($input->getAttribute('value'))->toBe($expected);
	expect($input->hasAttribute('maxlength'))->toBe(false);
	if ($mode === 'session') {
		expect($input->getAttribute('class'))->toContain('txtErrorTextBox');
		expect($after['sess_error_fields'])->toBe(array());
	}
})->with('control helpers')->with(array('default', 'existing', 'session'));

test('password autocomplete decoys and confirmation behavior are retained', function ($type) {
	list($document) = render_control('form_text_box', array('password', 'value', '', 64, 30, $type, 1));
	$inputs = $document->getElementsByTagName('input');
	expect($inputs->length)->toBe($type === 'password' ? 3 : 1);
	$input = $inputs->item($inputs->length - 1);
	expect($input->getAttribute('type'))->toBe($type);
	expect($input->getAttribute('autocomplete'))->toBe('current-password');
	expect($input->getAttribute('value'))->toBe('value');
})->with(array('password', 'password_confirm'));

test('invalid UTF-8 is replaced rather than discarding the field', function ($helper) {
	list($document) = render_control($helper, array('field', "before\xFFafter", '', 64));
	expect($document->getElementsByTagName('input')->item(0)->getAttribute('value'))->toBe("before\u{FFFD}after");
})->with('control helpers');
