<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace DataQueryOutputContextTest;

function get_request_var($name) {
	return $GLOBALS['data_query_output_payload'];
}

function render_expression($expression, $payload) {
	$GLOBALS['data_query_output_payload'] = $payload;
	$suggested_value = array('id' => $payload, 'field_name' => $payload);
	$data_template = array('id' => $payload);
	try {
		return eval('namespace DataQueryOutputContextTest; return ' . $expression . ';');
	} finally {
		unset($GLOBALS['data_query_output_payload']);
	}
}

test('data query action parameters round trip without escaping the HTML attribute', function ($payload) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/data_queries.php');
	preg_match_all('/data-url=\'<\?php\s+print (.*?);\?>\'/s', $source, $matches);
	expect(count($matches[1]))->toBe(6);
	$actions = array();
	foreach ($matches[1] as $expression) {
		$value = render_expression($expression, $payload);
		expect($value)->toContain('&amp;snmp_query_graph_id=');
		expect($value)->not->toContain('&snmp_query_graph_id=');
		expect(strpbrk($value, "<>\"'`"))->toBeFalse();
		$document = new \DOMDocument();
		$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><a data-url=\'' . $value . '\'>action</a></body></html>');
		$links = $document->getElementsByTagName('a');
		expect($links->length)->toBe(1);
		expect($links->item(0)->attributes->length)->toBe(1);
		expect($document->getElementsByTagName('script')->length)->toBe(0);
		expect($document->getElementsByTagName('img')->length)->toBe(0);
		$url = $links->item(0)->getAttribute('data-url');
		expect(parse_url($url, PHP_URL_PATH))->toBe('data_queries.php');
		expect(parse_url($url, PHP_URL_FRAGMENT))->toBeNull();
		parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
		$action = $parameters['action'];
		$actions[] = $action;
		$keys = array('action', 'snmp_query_graph_id', 'id', 'snmp_query_id');
		if (substr($action, -5) === '_dssv') {
			$keys[] = 'data_template_id';
		}
		if (strpos($action, 'item_remove_') !== 0) {
			$keys[] = 'field_name';
		}
		expect(array_keys($parameters))->toBe($keys);
		unset($parameters['action']);
		foreach ($parameters as $parameter) {
			expect($parameter)->toBe($payload);
		}
	}
	expect($actions)->toBe(array('item_movedown_gsv', 'item_moveup_gsv', 'item_remove_gsv',
		'item_movedown_dssv', 'item_moveup_dssv', 'item_remove_dssv'));
})->with(array('42', '0042', '', 'réseau 日本語', '\' autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'7&action=other#fragment%20+ space', '&#39;&quot;&amp;', '` value'));

test('data query removal hidden ID preserves its literal attribute value', function ($payload) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/data_queries.php');
	expect(preg_match('/<input type=\'hidden\' id=\'snmp_query_graph_id\' value=\'<\?php\s+print (.*?);\?>\'>/s', $source, $match))->toBe(1);
	$value = render_expression($match[1], $payload);
	expect(strpbrk($value, "<>\"'`"))->toBeFalse();
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><input value=\'' . $value . '\'></body></html>');
	$inputs = $document->getElementsByTagName('input');
	expect($inputs->length)->toBe(1);
	expect($inputs->item(0)->attributes->length)->toBe(1);
	expect($inputs->item(0)->getAttribute('value'))->toBe($payload);
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
})->with(array('42', '0042', '', 'réseau 日本語', '\' autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)><script>alert(1)</script>', '&#39;&quot;&amp;', '` value'));
