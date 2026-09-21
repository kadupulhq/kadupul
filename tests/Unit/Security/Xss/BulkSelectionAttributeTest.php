<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace BulkSelectionAttributeTest;

function get_request_var($name) {
	return $GLOBALS['bulk_action_payload'];
}

function get_nfilter_request_var($name) {
	return get_request_var($name);
}

function render_confirmation($source, $items, $action) {
	expect(preg_match('/\$selected_items_html = \(isset\(\$(\w+)\).*?<\/tr>[^;]*;/s', $source, $match))->toBe(1);
	if ($items !== null) {
		${$match[1]} = $items;
	}
	$save_html = '';
	$GLOBALS['bulk_action_payload'] = $action;
	ob_start();
	try {
		eval('namespace BulkSelectionAttributeTest; ' . $match[0]);
		return ob_get_contents();
	} finally {
		ob_end_clean();
		unset($GLOBALS['bulk_action_payload']);
	}
}

test('bulk confirmation fields preserve serialized selections and action values', function ($file, ?string $payload) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	$items = $payload === null ? null : array($payload, '0042', 3);
	$action = $payload === null ? '1' : $payload;
	$output = render_confirmation($source, $items, $action);
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body><table>'
		. $output . '</table></body></html>');
	$inputs = $document->getElementsByTagName('input');
	expect($inputs->length)->toBe(3);
	$fields = array();
	foreach ($inputs as $input) {
		expect($input->attributes->length)->toBe(3);
		expect($input->getAttribute('type'))->toBe('hidden');
		$fields[$input->getAttribute('name')] = $input->getAttribute('value');
	}
	expect($fields['action'])->toBe('actions');
	expect($fields['drp_action'])->toBe($action);
	expect($fields['selected_items'])->toBe($items === null ? '' : serialize($items));
	if ($items !== null) {
		expect(unserialize($fields['selected_items'], array('allowed_classes' => false)))->toBe($items);
	}
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	expect($output)->not->toContain('`');
	if ($payload === '&#39;&quot;&amp;') {
		expect($output)->toContain('&amp;#39;&amp;quot;&amp;amp;');
	}
})->with(array(
	'data queries' => 'data_queries.php',
	'data templates' => 'data_templates.php',
	'graph templates' => 'graph_templates.php',
	'host templates' => 'host_templates.php',
	'data inputs' => 'data_input.php',
	'GPRINT presets' => 'gprint_presets.php',
))->with(array(
	'ordinary ID' => '42',
	'leading zeros' => '0042',
	'empty value' => '',
	'missing selection' => array(null),
	'Unicode' => 'réseau 日本語',
	'attribute boundary' => '\' autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick' => '` value',
));
