<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * rrdtool_info2html() rendered every cell with form_selectable_cell(), which
 * escapes $width, $style_or_class and $title but prints $contents raw. The
 * escaping wrapper, form_selectable_ecell(), sits directly above it in
 * lib/html_utility.php.
 *
 * The values are parsed out of `rrdtool info` output, and the capture group is
 * (\S+): whitespace-free, but <, >, " and ' all pass. The filename cell is
 * data_template_data.data_source_path, stored from the request under
 * '^[^\r\n]*$' (data_sources.php:245) and not confined to the RRA directory,
 * so a name such as <svg/onload=...>.rrd survives the filter, is created, and
 * is echoed back into the Data Source Info panel. DS names and RRA fields are
 * the same sink for an RRD the poller did not create.
 */

namespace RrdInfoHtmlEscapeTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function info2html_source() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');

	expect($source)->not->toBeFalse();

	return \test_php_function_source($source, 'rrdtool_info2html');
}

it('renders no cell through the unescaped helper', function () {
	$body = info2html_source();

	// A lookbehind, because 'form_selectable_ecell(' contains
	// 'form_selectable_cell(' only if the boundary is ignored; assert on the
	// exact call name instead.
	expect(preg_match_all('/(?<![a-z_])form_selectable_cell\(/', $body))->toBe(0);
	expect(preg_match_all('/form_selectable_ecell\(/', $body))->toBeGreaterThan(20);
});

it('confirms the two helpers differ in escaping contents', function () {
	$utility = file_get_contents(dirname(__DIR__, 4) . '/lib/html_utility.php');

	expect($utility)->not->toBeFalse();

	$cell  = \test_php_function_source($utility, 'form_selectable_cell');
	$ecell = \test_php_function_source($utility, 'form_selectable_ecell');

	// The plain helper escapes the attributes but not the contents; the wrapper
	// escapes the contents and delegates. That asymmetry is the defect.
	expect($cell)->toContain('$width_html');
	expect($cell)->not->toContain('$contents_html');
	expect($ecell)->toContain('$contents_html');
	expect($ecell)->toContain('form_selectable_cell(');
});

it('escapes the markup an rrdtool info value can carry', function () {
	$utility = file_get_contents(dirname(__DIR__, 4) . '/lib/html_utility.php');

	expect($utility)->not->toBeFalse();

	// Run the real wrapper over a filename the path filter permits.
	$payload = '<svg/onload=alert(1)>.rrd';

	$code = 'function form_selectable_cell($c, $i, $w = "", $s = "", $t = "") { echo $c; }'
		. ' ' . \test_php_function_source($utility, 'form_selectable_ecell')
		. ' form_selectable_ecell(' . var_export($payload, true) . ', "value");';

	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);

	$rendered = implode('', $out);

	expect($rendered)->not->toContain('<svg');
	expect($rendered)->toContain('&lt;svg');
});

it('keeps a plain value readable', function () {
	$utility = file_get_contents(dirname(__DIR__, 4) . '/lib/html_utility.php');

	$code = 'function form_selectable_cell($c, $i, $w = "", $s = "", $t = "") { echo $c; }'
		. ' ' . \test_php_function_source($utility, 'form_selectable_ecell')
		. ' form_selectable_ecell("/var/lib/cacti/rra/host_traffic_in_1.rrd", "value");';

	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);

	expect(implode('', $out))->toBe('/var/lib/cacti/rra/host_traffic_in_1.rrd');
});
