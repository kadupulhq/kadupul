<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The template import preview printed names, data query paths, differences and
 * orphans taken from the uploaded XML as markup, so previewing a shared template
 * file could run script in the importing administrator's session. Text without
 * markup must still print byte for byte as it did in 1.2.31.
 */

namespace TemplateImportPreviewEscapeTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/templates_import.php');

if ($source !== false && preg_match('/^function import_escape_markup\(.*?^}\R/ms', $source, $matches) === 1) {
	eval('namespace TemplateImportPreviewEscapeTest;' . $matches[0]);
}

if ($source !== false && preg_match('/^function display_template_data\(.*?^}\R/ms', $source, $matches) === 1) {
	/* array_map() resolves a string callback in the global namespace */
	eval('namespace TemplateImportPreviewEscapeTest;' . str_replace("array_map('import_escape_markup'", "array_map(__NAMESPACE__ . '\\import_escape_markup'", $matches[0]));
}

$GLOBALS['preview_cells'] = array();

function __() {
	$args = func_get_args();
	$text = array_shift($args);

	return (count($args) && strpos($text, '%') !== false) ? vsprintf($text, $args) : $text;
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function html_start_box() {}
function html_header() {}
function html_header_checkbox() {}
function html_end_box() {}
function form_alternate_row() {}
function form_end_row() {}
function form_checkbox_cell() {}

function form_selectable_cell($contents) {
	$GLOBALS['preview_cells'][] = $contents;
}

test('the preview has a markup escape for text from the uploaded file', function () {
	expect(function_exists(__NAMESPACE__ . '\import_escape_markup'))->toBeTrue();
});

test('names, data query paths, differences and orphans go through it', function () {
	$src = file_get_contents(dirname(__DIR__, 4) . '/templates_import.php');

	expect($src)->toContain('form_selectable_cell(import_escape_markup($path), $id);')
		->and($src)->toContain("form_selectable_cell(import_escape_markup(\$detail['name']), \$id);")
		->and($src)->toContain('$diff_array[$item] = import_escape_markup($item);')
		->and($src)->toContain("implode('<br>', array_map('import_escape_markup', \$orphan_array))");
});

test('markup in a name from the XML prints as text', function () {
	expect(function_exists(__NAMESPACE__ . '\import_escape_markup'))->toBeTrue();

	expect(import_escape_markup('New Graph Template: <img src=x onerror=alert(1)>'))
		->toBe('New Graph Template: &lt;img src=x onerror=alert(1)>');
});

test('text without markup is returned byte for byte', function () {
	expect(function_exists(__NAMESPACE__ . '\import_escape_markup'))->toBeTrue();

	foreach (array('Cisco - CPU Usage', 'R&D Load', "Bob's CPU", 'Say "hi"', 'Zürich – Temp', '東京', 'A > B',
		'Table: cdef, Column: name, New Value: Bob&apos;s &amp; Co, Old Value: y') as $text) {
		expect(import_escape_markup($text))->toBe($text);
	}
});

test('the color swatch lib/import.php builds survives unchanged', function () {
	expect(function_exists(__NAMESPACE__ . '\import_escape_markup'))->toBeTrue();

	$diff = 'Table: graph_templates_item, Column: color_id, New Value: <span style="background-color:#FF0000">FF0000</span>, Old Value: <span style="background-color:#00ff00">00ff00</span>';

	expect(import_escape_markup($diff))->toBe($diff);
});

test('the empty swatch lib/import.php builds for a missing color survives unchanged', function () {
	expect(function_exists(__NAMESPACE__ . '\\import_escape_markup'))->toBeTrue();

	/* color_id 0, or a color id with no hex, gives an empty swatch */
	$diff = 'Table: graph_templates_item, Column: color_id, New Value: <span style="background-color:#FF0000">FF0000</span>, Old Value: <span style="background-color:#"></span>';

	expect(import_escape_markup($diff))->toBe($diff);
});

test('a swatch that is not a plain hex color is escaped', function () {
	expect(function_exists(__NAMESPACE__ . '\import_escape_markup'))->toBeTrue();

	$out = import_escape_markup('New Value: <span style="background-color:#&quot; onmouseover=&quot;alert(1)">x</span>');

	expect($out)->not->toContain('<span')
		->and($out)->toStartWith('New Value: &lt;span');
});

test('differences that differ only by an entity stay two lines, as in 1.2.31', function () {
	expect(function_exists(__NAMESPACE__ . '\\display_template_data'))->toBeTrue();

	$GLOBALS['preview_cells'] = array();

	$templates = array(
		'hash' => array(
			'type'      => 'graph_template',
			'type_name' => 'Graph Template',
			'name'      => 'T',
			'status'    => 'updated',
			'vals'      => array(
				'differences' => array('A<B', 'A&lt;B'),
				'orphans'     => array('A<B', 'A&lt;B'),
			),
		),
	);

	display_template_data($templates);

	/* 1.2.31 keyed both lists on the raw text, so these were two lines each */
	expect($GLOBALS['preview_cells'])->toContain('Differences<br>A&lt;B<br>A&lt;B<br>Orphans<br>A&lt;B<br>A&lt;B');
});
