<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace PaginationBatchTest;

function __($format, ...$args) { return sprintf($format, ...$args); }
function decoded($value) { return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
foreach (array('html_nav_bar' => 'html.php', 'get_page_list' => 'html_utility.php') as $helper => $file) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/' . $file);
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace PaginationBatchTest; ' . $match[0]);
}

function document($html) {
	$doc = new \DOMDocument();
	// The existing count-free branch emits a stray closing anchor in its page label.
	$previous = libxml_use_internal_errors(true);
	try {
		$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');
	} finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
	expect($doc->getElementsByTagName('script')->length)->toBe(0);
	expect($doc->getElementsByTagName('img')->length)->toBe(0);
	foreach ($doc->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attr) { expect(strncmp($attr->name, 'on', 2))->not->toBe(0); }
	}
	expect($html)->not->toContain(chr(96));
	return new \DOMXPath($doc);
}

dataset('pagination payloads', array('items.php', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', '&amp;#39;', chr(96), 'a.b:c[d]'));

$baseline = json_decode(file_get_contents(__DIR__ . '/pagination-alert-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$affectedFiles = array_values(array_unique(array_column($baseline['issues'], 'file')));
dataset('pagination affected subsystems', $affectedFiles);

test('recorded scanner paths are unique and point to pagination callers', function () use ($baseline, $affectedFiles) {
	expect(count($baseline['issues']))->toBe(96);
	expect(count(array_unique(array_column($baseline['issues'], 'key'))))->toBe(96);
	expect(count($affectedFiles))->toBe(29);
	foreach ($affectedFiles as $file) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
		expect($source)->toContain('html_nav_bar(');
	}
});

test('affected subsystems retain safe pagination with hostile query values in both modes', function ($file, $counted, $payload) {
	// Representative URLs exercise the shared renderer, not a full controller/DB integration.
	$base = $file . '?action=edit&id=' . $payload;
	$xpath = document(html_nav_bar($base, 3, 2, 10, 100, 30, 'Rows', 'page', 'main', $counted));
	$previous = $xpath->query('//div[@class="navBarNavigationPrevious"]/a')->item(0);
	$next = $xpath->query('//div[@class="navBarNavigationNext"]/a')->item(0);
	expect($previous->getAttribute('data-url'))->toBe(decoded($base . '&page=1'));
	expect($next->getAttribute('data-url'))->toBe(decoded($base . '&page=3'));
	foreach ($xpath->query('//a') as $link) {
		expect($link->getAttribute('data-return'))->toBe('main');
		expect($link->attributes->length)->toBe($link->hasAttribute('class') ? 4 : 3);
	}
})->with('pagination affected subsystems')->with(array(false, true))->with('pagination payloads');

test('navigation attributes are encoded in counted and count-free modes', function ($counted, $payload) {
	$xpath = document(html_nav_bar($payload, 3, 5, 10, 200, 30, 'Rows', $payload, $payload, $counted));
	$links = $xpath->query('//a');
	expect($links->length)->toBeGreaterThanOrEqual(2);
	$base = trim($payload) . (strpos($payload, '?') === false ? '?' : '&');
	foreach ($links as $link) {
		expect($link->getAttribute('data-return'))->toBe(decoded($payload));
		expect($link->getAttribute('href'))->toBe('#');
		expect($link->attributes->length)->toBe($link->hasAttribute('class') ? 4 : 3);
		if (strpos($link->textContent, 'Previous') !== false) {
			expect($link->getAttribute('data-url'))->toBe(decoded($base . $payload . '=4'));
		} elseif (strpos($link->textContent, 'Next') !== false) {
			expect($link->getAttribute('data-url'))->toBe(decoded($base . $payload . '=6'));
		} else {
			// Preserve the page-list helper existing additional query separator.
			expect($link->getAttribute('data-url'))->toBe(decoded($base . '&' . $payload . '=' . $link->textContent));
		}
	}
})->with(array(false, true))->with('pagination payloads');

test('page ranges and ellipses retain their boundary behavior', function ($current, $expected, $ellipses) {
	$xpath = document(get_page_list($current, 3, 10, 200, 'items.php', 'p', 'contents'));
	$actual = array();
	foreach ($xpath->query('//a') as $link) {
		$actual[] = (int)$link->textContent;
		expect($link->getAttribute('data-url'))->toBe('items.php?p=' . $link->textContent);
	}
	expect($actual)->toBe($expected);
	expect($xpath->query('//li/span')->length)->toBe($ellipses);
	expect($xpath->query('//a[@class="active"]')->item(0)->textContent)->toBe((string)$current);
})->with(array(array(1, array(1, 2, 3, 4, 20), 1), array(10, array(1, 9, 10, 11, 20), 2),
	array(20, array(1, 17, 18, 19, 20), 1)));

test('first last and short-result navigation controls remain unchanged', function ($current, $total, $counted, $previous, $next) {
	$xpath = document(html_nav_bar('items.php?filter=on', 3, $current, 10, $total, 30, 'Rows', 'page', '', $counted));
	expect($xpath->query('//div[@class="navBarNavigationPrevious"]/a')->length)->toBe($previous);
	expect($xpath->query('//div[@class="navBarNavigationNext"]/a')->length)->toBe($next);
})->with(array(array(1, 30, true, 0, 1), array(3, 30, true, 1, 0), array(1, 10, false, 0, 1),
	array(2, 9, false, 1, 0), array(1, 9, false, 0, 0), array(1, 0, true, 0, 0)));

test('empty and single-page lists and caller-owned object markup remain compatible', function () {
	expect(document(get_page_list(1, 3, 0, 10, 'items.php'))->query('//a')->length)->toBe(0);
	expect(document(get_page_list(1, 3, 10, 0, 'items.php'))->query('//a')->length)->toBe(0);
	$single = document(get_page_list(1, 3, 10, 5, 'items.php'));
	expect($single->query('//a')->length)->toBe(1);
	expect($single->query('//a[@class="active"]')->item(0)->textContent)->toBe('1');
	$empty = document(html_nav_bar('items.php', 3, 1, 10, 0));
	expect($empty->query('//div[@class="navBarNavigationNone"]')->item(0)->textContent)->toContain('No Rows Found');
	$all = document(html_nav_bar('items.php', 3, 1, 10, 5, 30, '<strong>Graphs</strong>'));
	expect($all->query('//strong')->item(0)->textContent)->toBe('Graphs');
});

test('malformed UTF-8 is replaced without dropping URL or return-target text', function ($counted, $bytes, $replacement) {
	$base = 'items.php?id=' . $bytes;
	$return = 'panel-' . $bytes;
	$xpath = document(html_nav_bar($base, 3, 2, 10, 100, 30, 'Rows', 'page', $return, $counted));
	$previous = $xpath->query('//div[@class="navBarNavigationPrevious"]/a')->item(0);
	$next = $xpath->query('//div[@class="navBarNavigationNext"]/a')->item(0);
	expect($previous->getAttribute('data-url'))->toBe('items.php?id=' . $replacement . '&page=1');
	expect($next->getAttribute('data-url'))->toBe('items.php?id=' . $replacement . '&page=3');
	foreach ($xpath->query('//a') as $link) {
		expect($link->getAttribute('data-return'))->toBe('panel-' . $replacement);
		if ($link->parentNode->tagName === 'li') {
			expect($link->getAttribute('data-url'))->toBe('items.php?id=' . $replacement . '&&page=' . $link->textContent);
		}
	}
})->with(array(false, true))->with(array(
	array("before\xFFafter", "before\u{FFFD}after"),
	array("before\xC3(after", "before\u{FFFD}(after"),
	array("before\xE2\x82", "before\u{FFFD}"),
));
