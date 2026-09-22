<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace TableCellBatchTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_utility.php');
foreach (array('form_alternate_row_color', 'form_alternate_row', 'form_alternate_row_class',
	'form_selectable_ecell', 'form_selectable_cell', 'form_checkbox_cell') as $helper) {
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace TableCellBatchTest; ' . $match[0]);
}

function decoded($text) { return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

test('table helpers preserve configured charset bytes and escape markup', function ($charset, $hex) {
	$original = ini_get('default_charset');
	ini_set('default_charset', $charset);
	$payload = hex2bin($hex) . '\'"<>&`';
	$encoding = $charset ?: 'UTF-8';
	$encoded = str_replace('`', '&#96;', htmlspecialchars($payload, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, $encoding, false));
	try {
		foreach (array(
			function () use ($payload) { form_alternate_row_color('red', 'blue', 1, $payload); },
			function () use ($payload) { form_alternate_row($payload); },
			function () use ($payload) { form_alternate_row_class($payload, $payload); },
			function () use ($payload) { form_selectable_ecell($payload, 1, $payload, $payload, $payload); },
			function () use ($payload) { form_selectable_cell('Trusted', 1, $payload, $payload, $payload); },
			function () use ($payload) { form_checkbox_cell($payload, $payload); }
		) as $index => $render) {
			ob_start();
			try { $render(); $output = ob_get_contents(); }
			finally { ob_end_clean(); }
			expect($output)->toContain($encoded);
			expect(substr_count($output, $encoded))->toBe(array(1, 1, 2, 4, 3, 4)[$index]);
			expect($output)->not->toContain(hex2bin('efbfbd'));
			expect($output)->not->toContain('<>&`');
		}
	} finally {
		ini_set('default_charset', $original);
	}
})->with(array(array('ISO-8859-1', '636166e9'), array('Windows-1252', '707269636580'),
	array('UTF-8', '636166c3a9'), array('', '636166c3a9')));

function capture($callback, $row = false) {
	ob_start();
	try {
		$result = $callback();
		$output = ob_get_contents();
	} finally { ob_end_clean(); }
	$doc = new \DOMDocument();
	$markup = $row ? $output . '<td>Cell</td></tr>' : '<tr>' . $output . '</tr>';
	$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table>' . $markup . '</table></body></html>');
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
	expect($doc->getElementsByTagName('img')->length)->toBe(0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attr) { expect(strncmp($attr->name, 'on', 2))->not->toBe(0); }
	}
	expect($output)->not->toContain(chr(96));
	return array($doc, $result);
}

dataset('table cell payloads', array('field', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', '&amp;#39;', chr(96), 'a.b:c[d]'));

test('all row helpers encode IDs and custom classes', function ($helper, $payload) {
	list($doc) = capture(function () use ($helper, $payload) {
		if ($helper === 'color') { form_alternate_row_color('red', 'blue', 1, $payload); }
		elseif ($helper === 'class') { form_alternate_row_class($payload, $payload); }
		else { form_alternate_row($payload); }
	}, true);
	$row = $doc->getElementsByTagName('tr')->item(0);
	expect($row->getAttribute('id'))->toBe(decoded($payload));
	expect($row->attributes->length)->toBe(2);
	if ($helper === 'class') { expect($row->getAttribute('class'))->toBe(decoded($payload) . ' selectable'); }
	else { expect($row->getAttribute('class'))->toContain('selectable tableRow'); }
})->with(array('color', 'class', 'alternate'))->with('table cell payloads');

test('selectable cells encode metadata and preserve their content trust contract', function ($escaped, $style, $payload) {
	list($doc) = capture(function () use ($escaped, $style, $payload) {
		$helper = __NAMESPACE__ . ($escaped ? '\\form_selectable_ecell' : '\\form_selectable_cell');
		$helper($escaped ? $payload : '<strong>Trusted</strong>', 'unused', $payload,
			$style ? 'color:' . $payload : $payload, $payload);
	});
	$cell = $doc->getElementsByTagName('td')->item(0);
	$styleValue = $style ? 'color:' . $payload : $payload;
	$isStyle = strpos($styleValue, ':') !== false;
	expect($cell->getAttribute('class'))->toBe($isStyle ? 'nowrap' : 'nowrap ' . decoded($payload));
	expect($cell->getAttribute('style'))->toBe(($isStyle ? decoded($styleValue) . ';' : '') . 'width:' . decoded($payload) . ';');
	expect($cell->attributes->length)->toBe(2);
	$tooltip = $doc->getElementsByTagName('span')->item(0);
	expect($tooltip->getAttribute('title'))->toBe(decoded($payload));
	expect($tooltip->attributes->length)->toBe(3);
	if ($escaped) { expect($cell->textContent)->toBe(decoded($payload)); }
	else { expect($doc->getElementsByTagName('strong')->item(0)->textContent)->toBe('Trusted'); }
})->with(array(false, true))->with(array(false, true))->with('table cell payloads');

test('checkbox metadata is safe and label association and disabled behavior remain intact', function ($disabled, $payload) {
	list($doc) = capture(function () use ($disabled, $payload) { form_checkbox_cell($payload, $payload, $disabled); });
	$input = $doc->getElementsByTagName('input')->item(0);
	foreach (array('id', 'name') as $attr) { expect($input->getAttribute($attr))->toBe('chk_' . decoded($payload)); }
	expect($input->getAttribute('title'))->toBe(decoded($payload));
	expect($input->getAttribute('class'))->toBe($disabled ? 'checkbox disabled' : 'checkbox');
	expect($input->hasAttribute('disabled'))->toBe($disabled);
	expect($input->attributes->length)->toBe($disabled ? 6 : 5);
	expect($doc->getElementsByTagName('label')->item(0)->getAttribute('for'))->toBe($input->getAttribute('id'));
})->with(array(false, true))->with('table cell payloads');

test('row selection uses raw prefixes and retains disabled behavior', function ($id, $disabled, $selectable) {
	foreach (array('form_alternate_row', 'form_alternate_row_class') as $helper) {
		list($doc) = capture(function () use ($helper, $id, $disabled) {
			if ($helper === 'form_alternate_row') { form_alternate_row($id, true, $disabled); }
			else { form_alternate_row_class($id, 'custom', $disabled); }
		}, true);
		$row = $doc->getElementsByTagName('tr')->item(0);
		expect(strpos($row->getAttribute('class'), 'selectable') !== false)->toBe($selectable);
		expect($row->hasAttribute('id'))->toBe($id !== '');
	}
})->with(array(array('', false, false), array('row_7', false, false), array('row_7', true, false),
	array('line7', true, false), array('line7', false, true), array('&#114;ow_7', false, true)));

test('color row parity and legacy return value remain unchanged', function ($parity, $second, $class) {
	list($doc, $value) = capture(function () use ($parity, $second) {
		return form_alternate_row_color('first', $second, $parity);
	}, true);
	expect($value)->toBe('first');
	expect($doc->getElementsByTagName('tr')->item(0)->getAttribute('class'))->toBe($class . ' tableRow');
})->with(array(array(1, 'blue', 'odd'), array(2, '', 'even'), array(2, 'E5E5E5', 'even'), array(2, 'blue', 'even-alternate')));

test('empty cell metadata does not add optional attributes or wrappers', function () {
	list($doc) = capture(function () { form_selectable_cell('<strong>Trusted</strong>', 'unused'); });
	$cell = $doc->getElementsByTagName('td')->item(0);
	expect($cell->attributes->length)->toBe(1);
	expect($cell->getAttribute('class'))->toBe('nowrap');
	expect($doc->getElementsByTagName('span')->length)->toBe(0);
});
