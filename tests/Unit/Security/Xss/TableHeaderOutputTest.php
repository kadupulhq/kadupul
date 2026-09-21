<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace TableHeaderOutputTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html.php');
if (!preg_match('/function html_header\\(.*?^\\}/ms', $source, $match)) {
	throw new \RuntimeException('Missing table header helper');
}
eval('namespace TableHeaderOutputTest; ' . $match[0]);

function cacti_count($items) {
	return count($items);
}

function render_header($items, $span) {
	ob_start();
	try {
		html_header($items, $span);
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table>'
		. $output . '</table></body></html>');
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	foreach ($document->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attribute) {
			expect(strncmp($attribute->name, 'on', 2))->not->toBe(0);
		}
	}
	expect($output)->not->toContain(chr(96));
	return $document;
}

test('table header fields preserve decoded values inside output boundaries', function ($structured, $payload) {
	$item = $structured ? array('display' => $payload, 'tip' => $payload, 'align' => $payload, 'nohide' => false) : $payload;
	$document = render_header(array($item, $item), $payload);
	$cells = $document->getElementsByTagName('th');
	expect($cells->length)->toBe(2);
	$decoded = html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	foreach ($cells as $index => $cell) {
		expect($cell->textContent)->toBe($decoded);
		expect($cell->hasAttribute('colspan'))->toBe($index === 1);
		if ($index === 1) {
			expect($cell->getAttribute('colspan'))->toBe($decoded);
		}
		if ($structured) {
			expect($cell->getAttribute('class'))->toBe('nohide ' . $decoded);
			expect($cell->getAttribute('title'))->toBe($decoded);
			expect($cell->attributes->length)->toBe(($payload === '' ? 1 : 2) + $index);
		} else {
			expect($cell->attributes->length)->toBe($index);
		}
	}
})->with(array(false, true))->with(array(
	'ordinary' => '2',
	'Unicode' => 'réseau 日本語',
	'attribute boundary' => '\'" autofocus onfocus="alert(1)',
	'element boundary' => '</th><img src=x onerror=alert(1)><script>alert(1)</script>',
	'pre-escaped entities' => '&#39;&quot;&amp;',
	'backtick' => chr(96),
	'empty' => '',
));

test('default alignment and nohide presence semantics remain unchanged', function () {
	$document = render_header(array(
		array('display' => 'Default'),
		array('display' => 'Hidden', 'align' => 'right', 'nohide' => null),
		array('display' => 'Visible', 'nohide' => false),
	), 3);
	$cells = $document->getElementsByTagName('th');
	expect($cells->item(0)->getAttribute('class'))->toBe(' left');
	expect($cells->item(0)->hasAttribute('title'))->toBeFalse();
	expect($cells->item(1)->getAttribute('class'))->toBe(' right');
	expect($cells->item(2)->getAttribute('class'))->toBe('nohide left');
	expect($cells->item(2)->getAttribute('colspan'))->toBe('3');
});

test('row layout retains the existing colspan condition', function ($span) {
	$document = render_header(array('Header'), $span);
	expect($document->getElementsByTagName('tr')->item(0)->getAttribute('class'))
		->toBe('tableHeader ' . (!$span > 1 ? 'tableFixed' : ''));
})->with(array(0, 1, 2, 3));

test('empty header lists remain empty', function () {
	expect(render_header(array(), 1)->getElementsByTagName('th')->length)->toBe(0);
});

test('invalid UTF-8 is safely substituted', function () {
	$document = render_header(array(array('display' => "bad\xFF", 'tip' => "bad\xFF", 'align' => "bad\xFF")), "bad\xFF");
	$cell = $document->getElementsByTagName('th')->item(0);
	expect($cell->textContent)->toBe("bad\u{FFFD}");
	expect($cell->getAttribute('title'))->toBe("bad\u{FFFD}");
	expect($cell->getAttribute('class'))->toBe(" bad\u{FFFD}");
	expect($cell->getAttribute('colspan'))->toBe("bad\u{FFFD}");
});
