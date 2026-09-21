<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SortableHeaderBatchTest;

class Request {
	public static $values = array('action' => 'edit', 'tab' => 'items', 'sort_column' => 'name', 'sort_direction' => 'ASC');
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html.php');
foreach (array('html_header_sort', 'html_header_sort_checkbox', 'html_section_header') as $helper) {
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace SortableHeaderBatchTest; ' . $match[0]);
}

function isset_request_var($name) { return isset(Request::$values[$name]); }
function get_request_var($name) { return Request::$values[$name] ?? ''; }
function get_nfilter_request_var($name) { return get_request_var($name); }
function get_current_page($unused = true) { return 'sites.php'; }
function cacti_count($items) { return count($items); }
function __esc($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false); }
function decoded($value) { return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

function document($output) {
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

function render_sort($helper, $headers, $sort, $direction, $url, $return, $prefix, $include, $order = null) {
	$hadSession = isset($_SESSION);
	$session = $_SESSION ?? null;
	$hadScript = isset($_SERVER['SCRIPT_NAME']);
	$script = $_SERVER['SCRIPT_NAME'] ?? null;
	$_SERVER['SCRIPT_NAME'] = '/sites.php';
	$_SESSION = array();
	$reflection = new \ReflectionFunction(__NAMESPACE__ . '\\' . $helper);
	$page = $reflection->getStaticVariables()['page_count'] . '_sites_edit_items';
	if ($order !== null) {
		$_SESSION['sort_data'][$page] = $order;
	}
	ob_start();
	try {
		if ($helper === 'html_header_sort') {
			html_header_sort($headers, $sort, $direction, $prefix, $url, $return);
		} else {
			html_header_sort_checkbox($headers, $sort, $direction, $include, $url, $return, $prefix);
		}
		$output = ob_get_contents();
		$registered = $_SESSION['valid_sort_columns'][$page];
	} finally {
		ob_end_clean();
		if ($hadSession) { $_SESSION = $session; } else { unset($_SESSION); }
		if ($hadScript) { $_SERVER['SCRIPT_NAME'] = $script; } else { unset($_SERVER['SCRIPT_NAME']); }
	}
	return array(document($output), $registered);
}

dataset('sort helpers', array('html_header_sort', 'html_header_sort_checkbox'));
dataset('sort payloads', array('0042', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', chr(96), 'a&b=2#fragment'));

test('sortable headers preserve raw column registration and contain attribute payloads', function ($helper, $payload, $include) {
	$headers = array(
		$payload => array('display' => '<strong>Label</strong>', 'sort' => $payload, 'align' => $payload, 'tip' => $payload),
		'nosort_tail' => array('display' => 'Tail', 'align' => $payload, 'tip' => $payload),
	);
	list($doc, $registered) = render_sort($helper, $headers, 'not-selected', 'ASC', $payload, $payload, $payload, $include);
	expect($registered)->toBe(array($payload));
	$xpath = new \DOMXPath($doc);
	$info = $xpath->query("//div[@class='sortinfo']")->item(0);
	expect($info->attributes->length)->toBe(5);
	foreach (array('sort-return', 'sort-page', 'sort-column', 'sort-direction') as $attribute) {
		expect($info->getAttribute($attribute))->toBe(decoded($payload));
	}
	$cells = $doc->getElementsByTagName('th');
	expect($cells->item(0)->getAttribute('title'))->toBe(decoded($payload));
	expect($cells->item(0)->getAttribute('class'))->toBe('sortable ' . decoded($payload) . '  ');
	expect($cells->item(1)->getAttribute('title'))->toBe(decoded($payload));
	expect($doc->getElementsByTagName('strong')->item(0)->textContent)->toBe('Label');
	if ($helper === 'html_header_sort_checkbox') {
		expect($doc->getElementsByTagName('input')->item(0)->getAttribute('data-prefix'))->toBe(decoded($payload));
		$forms = $doc->getElementsByTagName('form');
		expect($forms->length)->toBe($include ? 1 : 0);
		if ($include) {
			foreach (array('id', 'name', 'action') as $attribute) {
				expect($forms->item(0)->getAttribute($attribute))->toBe(decoded($payload));
			}
			expect($forms->item(0)->getAttribute('method'))->toBe('post');
		}
	}
})->with('sort helpers')->with('sort payloads')->with(array(false, true));

test('sorting keeps legacy tuple and named-field state transitions', function ($helper, $named, $direction) {
	$headers = $named ? array(
		'name' => array('display' => 'Name'), 'other' => array('display' => 'Other'),
		'fresh' => array('display' => 'Fresh', 'sort' => 'DESC'),
	) : array('name' => array('Name', 'ASC'), 'other' => array('Other', 'ASC'), 'fresh' => array('Fresh', 'DESC'));
	list($doc, $registered) = render_sort($helper, $headers, 'name', $direction, '', '', 'chk', true,
		array('name' => $direction, 'other' => 'DESC'));
	expect($registered)->toBe(array('name', 'other', 'fresh'));
	$xpath = new \DOMXPath($doc);
	$infos = $xpath->query("//div[@class='sortinfo']");
	expect($infos->length)->toBe(3);
	expect($infos->item(0)->getAttribute('sort-direction'))->toBe($direction === 'ASC' ? 'DESC' : 'ASC');
	expect($infos->item(1)->getAttribute('sort-direction'))->toBe('ASC');
	expect($infos->item(2)->getAttribute('sort-direction'))->toBe('DESC');
	expect($infos->item(0)->getAttribute('sort-return'))->toBe('main');
	expect($infos->item(0)->getAttribute('sort-page'))->toBe('sites.php');
	$cells = $doc->getElementsByTagName('th');
	expect($cells->item(0)->getAttribute('class'))->toContain('primarySort');
	expect($cells->item(1)->getAttribute('class'))->toContain('secondarySort');
	$icons = $doc->getElementsByTagName('i');
	expect($icons->item(0)->getAttribute('class'))->toBe($direction === 'ASC' ? 'fa fa-sort-up' : 'fa fa-sort-down');
	expect($icons->item(2)->getAttribute('class'))->toBe('fa fa-sort');
})->with('sort helpers')->with(array(false, true))->with(array('ASC', 'DESC'));

test('non-sortable headers retain legacy span placement and column exclusion', function ($payload) {
	$headers = array('nosort_first' => array('First', 'ASC'), '' => array('Last', 'ASC'));
	list($doc, $registered) = render_sort('html_header_sort', $headers, 'name', 'ASC', '', '', $payload, false);
	expect($registered)->toBe(array());
	$cells = $doc->getElementsByTagName('th');
	expect($cells->item(0)->getAttribute('colspan'))->toBe(decoded($payload));
	expect($cells->item(1)->hasAttribute('colspan'))->toBeFalse();
	expect($doc->getElementsByTagName('i')->length)->toBe(0);
})->with('sort payloads');

test('invalid UTF-8 sort metadata is replaced without changing registered IDs', function ($helper) {
	$payload = "bad\xFF";
	$headers = array($payload => array('display' => 'Label', 'align' => $payload, 'tip' => $payload, 'sort' => $payload));
	list($doc, $registered) = render_sort($helper, $headers, 'name', 'ASC', $payload, $payload, $payload, true);
	expect($registered)->toBe(array($payload));
	$xpath = new \DOMXPath($doc);
	$info = $xpath->query("//div[@class='sortinfo']")->item(0);
	foreach (array('sort-return', 'sort-page', 'sort-column', 'sort-direction') as $attribute) {
		expect($info->getAttribute($attribute))->toBe("bad\u{FFFD}");
	}
})->with('sort helpers');

test('section headers keep trusted content and encode attribute boundaries', function ($payload, $structured) {
	$item = $structured ? array('display' => '<strong>Section</strong>', 'align' => $payload) : '<strong>Section</strong>';
	ob_start();
	try {
		html_section_header($item, $payload);
		$output = ob_get_contents();
	} finally { ob_end_clean(); }
	$doc = document($output);
	$cell = $doc->getElementsByTagName('th')->item(0);
	expect($cell->getAttribute('colspan'))->toBe(decoded($payload));
	expect($cell->attributes->length)->toBe($structured ? 2 : 1);
	if ($structured) {
		expect($cell->getAttribute('style'))->toBe('text-align:' . decoded($payload) . ';');
	}
	expect($doc->getElementsByTagName('strong')->item(0)->textContent)->toBe('Section');
})->with('sort payloads')->with(array(false, true));
