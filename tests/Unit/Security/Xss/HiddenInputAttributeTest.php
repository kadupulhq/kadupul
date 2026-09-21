<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace HiddenInputAttributeTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_form.php');
if (!preg_match('/function form_hidden_box\(.*?^\}/ms', $source, $match)) {
	throw new \RuntimeException('Hidden-input helper not found');
}
eval('namespace HiddenInputAttributeTest; ' . $match[0]);

function render_input($name, $previous, $default, $inForm = false) {
	ob_start();
	try {
		form_hidden_box($name, $previous, $default, $inForm);
		return ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

test('hidden input attributes retain literal names and values', function ($payload, $useDefault) {
	$name = 'field_' . $payload;
	$output = render_input($name, $useDefault ? '' : $payload, $payload, $useDefault);
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'
		. $output . '</body></html>');
	$inputs = $document->getElementsByTagName('input');
	expect($inputs->length)->toBe(1);
	$input = $inputs->item(0);
	expect($input->attributes->length)->toBe(5);
	expect($input->getAttribute('id'))->toBe($name);
	expect($input->getAttribute('name'))->toBe($name);
	expect($input->getAttribute('value'))->toBe($payload);
	expect($input->getAttribute('type'))->toBe('hidden');
	expect($input->getAttribute('style'))->toBe('height:0px;');
	expect($input->parentNode->nodeName)->toBe('div');
	expect($input->parentNode->getAttribute('style'))->toBe('display:none;');
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	expect($output)->not->toContain(chr(96));
})->with(array(
	'ordinary ID' => '42',
	'leading zeros' => '0042',
	'empty text' => '',
	'Unicode' => 'réseau 日本語',
	'quote boundary' => '\' autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'URL syntax' => '7&tab=other#fragment%20+ space',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick' => chr(96) . ' value',
))->with(array(false, true));

test('hidden input keeps existing scalar default selection', function ($previous, $expected) {
	$output = render_input('id', $previous, 'fallback');
	$document = new \DOMDocument();
	$document->loadHTML($output);
	expect($document->getElementsByTagName('input')->item(0)->getAttribute('value'))->toBe($expected);
})->with(array(
	'empty' => array('', 'fallback'),
	'null' => array(null, 'fallback'),
	'false' => array(false, 'fallback'),
	'integer zero' => array(0, '0'),
	'string zero' => array('0', '0'),
	'true' => array(true, '1'),
));
