<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SearchFieldAttributeTest;

function get_request_var($name) {
	expect(in_array($name, array('filter', 'rfilter', 'sfilter', 'hfilter', 'gfilter'), true))->toBeTrue();
	return $GLOBALS['search_field_payload'];
}

test('search fields preserve literal values and existing attributes', function ($file, $ids, ?string $payload) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	$count = preg_match_all("/<input\b[^\n]*value='<\\?php\\s+\\\$search_html = .*?\\?>'>/s", $source, $matches);
	expect($count)->toBe(count($ids));
	foreach ($matches[0] as $index => $markup) {
		$GLOBALS['search_field_payload'] = $payload;
		ob_start();
		try {
			eval('namespace SearchFieldAttributeTest; ?>' . $markup);
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
			unset($GLOBALS['search_field_payload']);
		}
		$document = new \DOMDocument();
		$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'
			. $output . '</body></html>');
		$inputs = $document->getElementsByTagName('input');
		expect($inputs->length)->toBe(1);
		$input = $inputs->item(0);
		expect($input->getAttribute('value'))->toBe($payload ?? '');
		expect($input->getAttribute('id'))->toBe($ids[$index]);
		expect($source)->toContain("<label for='" . $ids[$index] . "'>");
		expect($input->getAttribute('type'))->toBe('text');
		expect($input->getAttribute('class'))->toBe('ui-state-default ui-corner-all');
		expect($input->getAttribute('size'))->toBe($ids[$index] === 'rfilter' ? '45' : '25');
		$hasName = $file === 'tree.php' && $ids[$index] !== 'filter';
		expect($input->attributes->length)->toBe($hasName ? 6 : 5);
		if ($hasName) {
			expect($input->getAttribute('name'))->toBe($ids[$index]);
		}
		expect($document->getElementsByTagName('script')->length)->toBe(0);
		expect($document->getElementsByTagName('img')->length)->toBe(0);
		expect($output)->not->toContain(chr(96));
	}
})->with(array(
	'aggregate graphs' => array('aggregate_graphs.php', array('rfilter', 'filter')),
	'device templates' => array('host_templates.php', array('filter')),
	'notification managers' => array('managers.php', array('filter', 'filter', 'filter')),
	'trees' => array('tree.php', array('sfilter', 'hfilter', 'gfilter', 'filter')),
))->with(array(
	'ordinary search' => 'router 42',
	'empty search' => '',
	'missing search' => array(null),
	'Unicode' => 'réseau 日本語',
	'attribute boundary' => '\' autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick' => chr(96) . ' value',
));
