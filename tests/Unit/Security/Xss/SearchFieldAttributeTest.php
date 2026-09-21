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
		$classes = $file === 'graph_templates.php' ? 'ui-state-default' : 'ui-state-default ui-corner-all';
		expect($input->getAttribute('class'))->toBe($classes);
		expect($input->getAttribute('size'))->toBe($ids[$index] === 'rfilter' ? '45' : '25');
		$namedFilters = array(
			'data_templates.php', 'data_input.php', 'data_queries.php', 'gprint_presets.php', 'cdef.php',
			'color.php', 'data_source_profiles.php', 'graph_templates.php', 'graphs_new.php'
		);
		$hasName = ($file === 'tree.php' && $ids[$index] !== 'filter') || in_array($file, $namedFilters, true);
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
	'automation templates' => array('automation_templates.php', array('filter')),
	'automation SNMP' => array('automation_snmp.php', array('filter')),
	'automation tree rules' => array('automation_tree_rules.php', array('filter')),
	'discovered devices' => array('automation_devices.php', array('filter')),
	'discovery networks' => array('automation_networks.php', array('filter')),
	'automation graph rules' => array('automation_graph_rules.php', array('filter')),
	'data templates' => array('data_templates.php', array('filter')),
	'data inputs' => array('data_input.php', array('filter')),
	'data queries' => array('data_queries.php', array('filter')),
	'GPRINT presets' => array('gprint_presets.php', array('filter')),
	'CDEFs' => array('cdef.php', array('filter')),
	'VDEFs' => array('vdef.php', array('filter')),
	'colors' => array('color.php', array('filter')),
	'data source profiles' => array('data_source_profiles.php', array('filter')),
	'graph templates' => array('graph_templates.php', array('filter')),
	'new graphs' => array('graphs_new.php', array('filter')),
	'links' => array('links.php', array('filter')),
	'sites' => array('sites.php', array('filter')),
	'devices' => array('host.php', array('filter')),
	'plugins' => array('plugins.php', array('filter')),
	'RRD checks' => array('rrdcheck.php', array('filter')),
	'RRD cleaner' => array('rrdcleaner.php', array('filter')),
	'user domains' => array('user_domains.php', array('filter')),
	'pollers' => array('pollers.php', array('filter')),
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
