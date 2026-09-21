<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SelectOptionOutputTest;

const VALID_HOST_FIELDS = '(description)';

foreach (array('html_create_list' => 'html.php', 'null_out_substitutions' => 'variables.php') as $helper => $file) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/' . $file);
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace SelectOptionOutputTest; ' . $match[0]);
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function render_options($data, $format, $selected) {
	ob_start();
	try {
		html_create_list($data, $format === 'map' ? '' : 'label', 'key', $selected);
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><select>'
		. $output . '</select></body></html>');
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	expect($output)->not->toContain(chr(96));
	return $document->getElementsByTagName('option');
}

test('option values and labels retain legacy entity and selection behavior', function ($format, $payload, $selected) {
	$row = array('key' => $payload, 'label' => '|host_description| - ' . $payload);
	if ($format === 'host') {
		$row['host_id'] = 0;
	}
	$data = $format === 'map' ? array($payload => $row['label']) : array($row);
	$options = render_options($data, $format, $selected ? $payload : 'not-this-option');
	expect($options->length)->toBe(1);
	$option = $options->item(0);
	$decoded = html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	expect($option->getAttribute('value'))->toBe($decoded);
	expect($option->textContent)->toBe(($format === 'host' ? '|host_description| - ' : '') . $decoded);
	expect($option->hasAttribute('selected'))->toBe($selected);
	expect($option->attributes->length)->toBe($selected ? 2 : 1);
})->with(array('map', 'rows', 'host'))->with(array(
	'ordinary' => '42',
	'leading zeroes' => '0042',
	'empty' => '',
	'Unicode' => 'réseau 日本語',
	'attribute boundary' => '\'" autofocus onfocus="alert(1)',
	'element boundary' => '</option></select><img src=x onerror=alert(1)><script>alert(1)</script>',
	'pre-escaped entities' => '&#39;&quot;&amp;&apos;&#9;',
	'ampersands' => 'one&two',
	'backtick' => chr(96) . 'value',
))->with(array(true, false));

test('option selection keeps loose numeric matching and input order', function ($format) {
	$data = $format === 'map'
		? array('0042' => 'first', 7 => 'second')
		: array(array('key' => '0042', 'label' => 'first'), array('key' => 7, 'label' => 'second'));
	$options = render_options($data, $format, 42);
	expect($options->length)->toBe(2);
	expect($options->item(0)->getAttribute('value'))->toBe('0042');
	expect($options->item(0)->hasAttribute('selected'))->toBeTrue();
	expect($options->item(1)->getAttribute('value'))->toBe('7');
	expect($options->item(1)->hasAttribute('selected'))->toBeFalse();
})->with(array('map', 'rows'));

test('null labels and null host IDs retain placeholder cleanup', function () {
	$options = render_options(array(
		array('key' => 'a', 'label' => null),
		array('key' => 'b', 'label' => '|host_description| - text', 'host_id' => null),
	), 'rows', 'a');
	expect($options->item(0)->textContent)->toBe('');
	expect($options->item(1)->textContent)->toBe('text');
});

test('empty dropdown data renders no options', function ($format) {
	expect(render_options(array(), $format, '')->length)->toBe(0);
})->with(array('map', 'rows'));

test('invalid UTF-8 is replaced safely in values and labels', function () {
	$options = render_options(array(array('key' => "bad\xFF", 'label' => "bad\xFF")), 'rows', "bad\xFF");
	expect($options->item(0)->getAttribute('value'))->toBe("bad\u{FFFD}");
	expect($options->item(0)->textContent)->toBe("bad\u{FFFD}");
	expect($options->item(0)->hasAttribute('selected'))->toBeTrue();
});
