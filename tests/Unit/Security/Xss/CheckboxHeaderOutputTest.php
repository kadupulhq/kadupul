<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace CheckboxHeaderOutputTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html.php');
if (!preg_match('/function html_header_checkbox\\(.*?^\\}/ms', $source, $match)) {
	throw new \RuntimeException('Missing checkbox header helper');
}
eval('namespace CheckboxHeaderOutputTest; ' . $match[0]);

function get_current_page() {
	return 'sites.php';
}

function __esc($text) {
	return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}

function render_header($items, $include, $action, $resizable, $prefix) {
	ob_start();
	try {
		html_header_checkbox($items, $include, $action, $resizable, $prefix);
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

test('checkbox headers retain paired metadata and POST controls without attribute injection', function ($payload, $include, $resizable) {
	$items = array($payload, array('display' => $payload, 'align' => $payload, 'tip' => $payload, 'nohide' => false));
	$document = render_header($items, $include, $payload, $resizable, $payload);
	$decoded = html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$cells = $document->getElementsByTagName('th');
	expect($cells->length)->toBe($include ? 4 : 3);
	expect($cells->item(0)->textContent)->toBe($decoded);
	expect($cells->item(0)->getAttribute('class'))->toBe('left');
	expect($cells->item(1)->textContent)->toBe($decoded);
	expect($cells->item(1)->getAttribute('class'))->toBe($decoded . ' nohide');
	expect($cells->item(1)->getAttribute('title'))->toBe($decoded);
	expect($cells->item(1)->attributes->length)->toBe($payload === '' ? 1 : 2);
	$checkbox = $document->getElementsByTagName('input')->item(0);
	expect($checkbox->getAttribute('type'))->toBe('checkbox');
	expect($checkbox->getAttribute('id'))->toBe('selectall');
	expect($checkbox->getAttribute('data-prefix'))->toBe($decoded);
	expect($checkbox->attributes->length)->toBe(5);
	expect($document->getElementsByTagName('label')->item(0)->getAttribute('for'))->toBe('selectall');
	$forms = $document->getElementsByTagName('form');
	expect($forms->length)->toBe($include ? 1 : 0);
	if ($include) {
		$form = $forms->item(0);
		expect($form->attributes->length)->toBe(4);
		expect($form->getAttribute('id'))->toBe($decoded);
		expect($form->getAttribute('name'))->toBe($decoded);
		expect($form->getAttribute('method'))->toBe('post');
		expect($form->getAttribute('action'))->toBe($payload === '' ? 'sites.php' : $decoded);
	}
	expect($document->getElementsByTagName('tr')->item(0)->getAttribute('class'))
		->toBe('tableHeader ' . ($resizable ? '' : 'tableFixed'));
})->with(array('chk', 'sites.php?a=1&b=2', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', chr(96), ''))
	->with(array(false, true))->with(array(false, true));

test('default metadata and empty lists preserve the select-all control', function () {
	$document = render_header(array(array('display' => 'Name')), true, '', true, 'chk');
	$cell = $document->getElementsByTagName('th')->item(0);
	expect($cell->getAttribute('class'))->toBe('left ');
	expect($cell->hasAttribute('title'))->toBeFalse();
	$empty = render_header(array(), false, '', true, 'chk');
	expect($empty->getElementsByTagName('th')->length)->toBe(1);
	expect($empty->getElementsByTagName('input')->length)->toBe(1);
});

test('invalid UTF-8 metadata is safely substituted', function () {
	$document = render_header(array("bad\xFF"), true, "bad\xFF", true, "bad\xFF");
	expect($document->getElementsByTagName('th')->item(0)->textContent)->toBe("bad\u{FFFD}");
	expect($document->getElementsByTagName('input')->item(0)->getAttribute('data-prefix'))->toBe("bad\u{FFFD}");
	expect($document->getElementsByTagName('form')->item(0)->getAttribute('action'))->toBe("bad\u{FFFD}");
});
