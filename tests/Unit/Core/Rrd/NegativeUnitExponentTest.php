<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once __DIR__ . '/../../../Helpers/RrdGraphHarness.php';

test('graph rendering emits one option for signed integer unit exponents only', function () {
	$source = cacti_test_rrd_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrd_function_process_graph_options');
	$program = 'define("RRD_NL", ' . var_export(" \\\n", true) . '); define("CHECKED", "on");'
		. 'function get_rrdtool_version() { return "1.8.0"; }'
		. 'function read_config_option($name) { return ""; }'
		. 'function rrdtool_quote_argument($value) { return (string) $value; }'
		. 'function rrd_substitute_host_query_data($value, $graph, $data) { return $value; }'
		. 'function html_escape($value) { return $value; }'
		. 'function rrdtool_function_format_graph_date($data) { return ""; }'
		. 'function rrdtool_function_theme_font_options($data) { return ""; }'
		. 'function cacti_version_compare($version, $compare, $operator) { return version_compare($version, $compare, $operator); }'
		. 'function db_fetch_cell_prepared($sql, $params) { return ""; }'
		. '$config = array("include_path" => $argv[1]);'
		. $source
		. '$graph = array_fill_keys(array("auto_scale", "auto_scale_opts", "upper_limit", "lower_limit", "auto_scale_log", "scale_log_units", "auto_scale_rigid", "unit_value", "unit_exponent_value", "height", "width", "graph_nolegend", "image_format_id", "title_cache", "alt_y_grid", "base_value", "vertical_label", "slope_mode", "right_axis", "right_axis_label", "right_axis_format", "no_gridfit", "unit_length", "tab_width", "dynamic_labels", "force_rules_legend", "legend_position", "legend_direction", "left_axis_formatter", "right_axis_formatter"), "");'
		. '$graph["image_format_id"] = 1; $graph["unit_exponent_value"] = $argv[2]; $options = array();'
		. 'echo rrd_function_process_graph_options(1700000000, 1700003600, $graph, $options);';
	$directory = sys_get_temp_dir() . '/rrd-negative-exponent-' . bin2hex(random_bytes(8));
	mkdir($directory, 0700);
	file_put_contents($directory . '/global_arrays.php', '<?php $image_types = array(1 => "PNG");');

	try {
		foreach (array('-6', '0', '3') as $exponent) {
			$output = runUnitExponentChild($program, $directory, $exponent);
			expect(substr_count($output, '--units-exponent=' . $exponent))->toBe(1);
		}

		foreach (array('', '3x', "3\n") as $exponent) {
			$output = runUnitExponentChild($program, $directory, $exponent);
			expect($output)->not->toContain('--units-exponent=');
		}
	} finally {
		unlink($directory . '/global_arrays.php');
		rmdir($directory);
	}
});

function runUnitExponentChild(string $program, string $directory, string $exponent): string
{
	$process = proc_open(array(PHP_BINARY, '-r', $program, $directory, $exponent), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes
	);
	expect(is_resource($process))->toBeTrue();
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	if ($status !== 0) {
		throw new RuntimeException($error . $output);
	}
	expect($error)->toBe('');

	return $output;
}
