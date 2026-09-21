<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace TemplateReportSearchTest;

function __($text) {
	return $text;
}

function get_request_var($name) {
	return $name === 'filter' ? $GLOBALS['template_search_payload'] : '-1';
}

test('template and report search output preserves literal values', function ($file, ?string $payload) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	expect(preg_match('/\$search_html = .*?<option value=.*?;\n/s', $source, $match))->toBe(1);
	$filter_html = '';
	$GLOBALS['template_search_payload'] = $payload;
	ob_start();
	try {
		eval('namespace TemplateReportSearchTest; ' . $match[0]);
		$output = ob_get_contents() . $filter_html;
	} finally {
		ob_end_clean();
		unset($GLOBALS['template_search_payload']);
	}
	// The template prefix ends inside its default option; finish that fixture markup.
	if ($file !== 'lib/html_reports.php') {
		$output .= '></option>';
	}
	$output .= '</select>';
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table>'
		. $output . '</table></body></html>');
	$inputs = $document->getElementsByTagName('input');
	expect($inputs->length)->toBe(1);
	$input = $inputs->item(0);
	expect($input->attributes->length)->toBe(5);
	expect($input->getAttribute('id'))->toBe('filter');
	expect($input->getAttribute('type'))->toBe('text');
	expect($input->getAttribute('class'))->toBe('ui-state-default ui-corner-all');
	expect($input->getAttribute('size'))->toBe('25');
	expect($input->getAttribute('value'))->toBe($payload ?? '');
	$labels = (new \DOMXPath($document))->query('//label[@for="filter"]');
	expect($labels->length)->toBe(1);
	expect($labels->item(0)->textContent)->toBe('Search');
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	expect($output)->not->toContain(chr(96));
})->with(array('aggregate_templates.php', 'color_templates.php', 'lib/html_reports.php'))->with(array(
	'ordinary search' => 'router 42',
	'empty search' => '',
	'missing search' => array(null),
	'Unicode' => 'réseau 日本語',
	'attribute boundary' => '\' autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick' => chr(96) . ' value',
));
