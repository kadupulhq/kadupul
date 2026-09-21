<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SpikeMenuAttributeTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html.php');
if (!preg_match('/function html_spikekill_menu_item\\(.*?^\\}/ms', $source, $match)) {
	throw new \RuntimeException('Missing spike menu helper');
}
eval('namespace SpikeMenuAttributeTest; ' . $match[0]);

function parse_menu($markup) {
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><ul>'
		. $markup . '</ul></body></html>');
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	foreach ($document->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attribute) {
			expect(strncmp($attribute->name, 'on', 2))->not->toBe(0);
		}
	}
	expect($markup)->not->toContain(chr(96));
	return $document;
}

test('spike menu metadata stays inside its intended attribute', function ($index, $payload) {
	$args = array('Remove StdDev', 'fa fa-check', 'rstddev', 'method_avg', '0042');
	$args[$index] = $payload;
	$document = parse_menu(html_spikekill_menu_item(...$args));
	$items = $document->getElementsByTagName('li');
	expect($items->length)->toBe(1);
	$item = $items->item(0);
	expect($item->attributes->length)->toBe(3);
	expect($item->getAttribute('id'))->toBe(html_entity_decode($args[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	expect($item->getAttribute('data-graph'))->toBe(html_entity_decode($args[4], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	expect($item->getAttribute('class'))->toBe(' ' . html_entity_decode($args[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	$icons = $document->getElementsByTagName('i');
	expect($icons->length)->toBe(1);
	expect($icons->item(0)->attributes->length)->toBe(1);
	expect($icons->item(0)->getAttribute('class'))->toBe(html_entity_decode($args[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	expect($item->textContent)->toBe('Remove StdDev');
})->with(array(1, 2, 3, 4))->with(array(
	'ordinary' => '0042',
	'Unicode' => 'réseau 日本語',
	'attribute boundary' => '\'" autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'pre-escaped entities' => '&#39;&quot;&amp;',
	'backtick' => chr(96),
));

test('empty metadata retains legacy omission semantics', function ($empty) {
	$document = parse_menu(html_spikekill_menu_item('Settings', $empty, $empty, $empty, $empty));
	$item = $document->getElementsByTagName('li')->item(0);
	expect($item->hasAttribute('id'))->toBeFalse();
	expect($item->hasAttribute('data-graph'))->toBeFalse();
	expect($item->getAttribute('class'))->toBe('');
	expect($document->getElementsByTagName('i')->length)->toBe(0);
})->with(array(array(''), array('0'), array(0), array(null), array(false)));

test('nested generated menus and trusted label markup remain intact', function () {
	$child = html_spikekill_menu_item('Average', 'fa fa-check', 'skmethod', 'method_avg');
	$document = parse_menu(html_spikekill_menu_item('<strong>Settings</strong>', 'fa fa-cog', '', '', '', $child));
	$items = $document->getElementsByTagName('li');
	expect($items->length)->toBe(2);
	expect($items->item(1)->getAttribute('id'))->toBe('method_avg');
	expect($items->item(1)->getAttribute('class'))->toBe(' skmethod');
	expect($items->item(1)->parentNode->nodeName)->toBe('ul');
	expect($items->item(1)->parentNode->parentNode->isSameNode($items->item(0)))->toBeTrue();
	expect($document->getElementsByTagName('strong')->item(0)->textContent)->toBe('Settings');
});

test('invalid UTF-8 metadata receives replacement characters', function () {
	$document = parse_menu(html_spikekill_menu_item('Menu', "bad\xFF", "bad\xFF", "bad\xFF", "bad\xFF"));
	$item = $document->getElementsByTagName('li')->item(0);
	expect($item->getAttribute('id'))->toBe("bad\u{FFFD}");
	expect($item->getAttribute('data-graph'))->toBe("bad\u{FFFD}");
	expect($item->getAttribute('class'))->toBe(" bad\u{FFFD}");
	expect($document->getElementsByTagName('i')->item(0)->getAttribute('class'))->toBe("bad\u{FFFD}");
});
