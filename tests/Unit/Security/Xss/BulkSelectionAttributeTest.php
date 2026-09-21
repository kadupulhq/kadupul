<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace BulkSelectionAttributeTest;

function __esc($text) {
	return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_request_var($name) {
	return $GLOBALS['bulk_action_payload'];
}

function get_nfilter_request_var($name) {
	return get_request_var($name);
}

function isset_request_var($name) {
	return $name === 'local_graph_id';
}

function get_filter_request_var($name) {
	expect($name)->toBe('local_graph_id');
	return 17;
}

function render_confirmation($source, $items, $action, $save_html = '') {
	expect(preg_match('/\$selected_items_html = \(isset\(\$(\w+)\).*?<\/tr>[^;]*;/s', $source, $match))->toBe(1);
	if ($items !== null) {
		${$match[1]} = $items;
	}
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
	$hasReturnButton = in_array($file, array('automation_networks.php', 'automation_snmp.php'), true);
	$hasGraphId = $file === 'aggregate_graphs.php';
	expect($inputs->length)->toBe(3 + (int) $hasReturnButton + (int) $hasGraphId);
	$fields = array();
	foreach ($inputs as $input) {
		if ($hasReturnButton && $input->getAttribute('type') === 'button') {
			expect($input->getAttribute('name'))->toBe('cancel');
			expect($input->getAttribute('value'))->toBe('Return');
			continue;
		}
		expect($input->attributes->length)->toBe(3);
		expect($input->getAttribute('type'))->toBe('hidden');
		$fields[$input->getAttribute('name')] = $input->getAttribute('value');
	}
	expect($fields['action'])->toBe('actions');
	if ($hasGraphId) {
		expect($fields['local_graph_id'])->toBe('17');
	}
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
	'aggregate templates' => 'aggregate_templates.php',
	'color templates' => 'color_templates.php',
	'data source profiles' => 'data_source_profiles.php',
	'automation templates' => 'automation_templates.php',
	'automation graph rules' => 'automation_graph_rules.php',
	'automation tree rules' => 'automation_tree_rules.php',
	'discovered devices' => 'automation_devices.php',
	'discovery networks' => 'automation_networks.php',
	'automation SNMP' => 'automation_snmp.php',
	'colors' => 'color.php',
	'links' => 'links.php',
	'sites' => 'sites.php',
	'CDEFs' => 'cdef.php',
	'VDEFs' => 'vdef.php',
	'aggregate graphs' => 'aggregate_graphs.php',
	'graphs' => 'graphs.php',
	'data sources' => 'data_sources.php',
	'pollers' => 'pollers.php',
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

test('discovery confirmation keeps cancel and submit controls when an action is available', function ($file) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	$output = render_confirmation($source, array('0042'), '1', '<button type="submit">Continue</button>');
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><body><table>' . $output . '</table></body></html>');
	$xpath = new \DOMXPath($document);
	expect($xpath->query('//input[@type="hidden"]')->length)->toBe(3);
	$cancel = $xpath->query('//input[@type="button" and @name="cancel"]');
	expect($cancel->length)->toBe(1);
	expect($cancel->item(0)->getAttribute('value'))->toBe('Cancel');
	expect($xpath->query('//button[@type="submit"]')->length)->toBe(1);
	expect($xpath->query('//input[@name="selected_items"]')->item(0)->getAttribute('value'))
		->toBe(serialize(array('0042')));
})->with(array('automation_networks.php', 'automation_snmp.php'));
