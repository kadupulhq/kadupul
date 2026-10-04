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
*/

/*
 * A report's Device item expands the device's graphs for the report owner.
 * reports_generate_html() gated it with is_tree_allowed() on the device id,
 * so the item followed whichever tree shared that number. The shipped
 * function runs in a child process with the permission helpers stubbed.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function report_device_item_run(array $trees, array $devices) : array {
	$reports = file_get_contents(dirname(__DIR__, 4) . '/lib/reports.php');
	$empty   = sys_get_temp_dir() . '/kadupul-reports-' . bin2hex(random_bytes(6));

	mkdir($empty . '/lib', 0700, true);
	file_put_contents($empty . '/lib/time.php', "<?php\n");

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

define('POLLER_VERBOSITY_MEDIUM', 3);
define('REPORTS_ITEM_GRAPH', 1);
define('REPORTS_ITEM_TEXT', 2);
define('REPORTS_ITEM_TREE', 3);
define('REPORTS_ITEM_HOST', 5);
define('REPORTS_OUTPUT_STDOUT', 1);
define('REPORTS_OUTPUT_EMAIL', 2);

$config    = array('base_path' => $scenario['base_path']);
$alignment = array(1 => 'left', 2 => 'center', 3 => 'right');
$calls     = array('expanded' => array(), 'tree_checks' => array(), 'device_checks' => array());

function db_fetch_row_prepared($sql, $params = array()) {
	return array('id' => 1, 'user_id' => 7, 'name' => 'Nightly', 'cformat' => '', 'format_file' => '', 'alignment' => 1, 'font_size' => 16);
}

function db_fetch_assoc_prepared($sql, $params = array()) {
	return array(array('id' => 11, 'item_type' => REPORTS_ITEM_HOST, 'host_id' => 5, 'tree_id' => 0, 'local_graph_id' => 0));
}

function read_user_setting($name, $default = false, $force = false, $user = 0) {
	return $default;
}

function reports_log(...$args) {
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function html_escape($string) {
	return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

function is_tree_allowed($tree_id, $user_id = 0) {
	$GLOBALS['calls']['tree_checks'][] = array($tree_id, $user_id);

	return in_array($tree_id, $GLOBALS['scenario']['trees']);
}

function is_device_allowed($device_id, $user_id = 0) {
	$GLOBALS['calls']['device_checks'][] = array($device_id, $user_id);

	return in_array($device_id, $GLOBALS['scenario']['devices']);
}

function reports_expand_device($report, $item, $device_id, $output, $format_ok, $theme) {
	$GLOBALS['calls']['expanded'][] = $device_id;

	return '<!-- device ' . $device_id . ' -->';
}

PHP;

	$source .= cacti_test_function_source($reports, 'reports_generate_html') . "\n\n";
	$source .= "\$theme = '';\n\$html = reports_generate_html(1, REPORTS_OUTPUT_STDOUT, \$theme);\n";
	$source .= "print json_encode(array('calls' => \$calls, 'html' => \$html));\n";

	try {
		return cacti_test_run_php_source($source, array('base_path' => $empty, 'trees' => $trees, 'devices' => $devices));
	} finally {
		unlink($empty . '/lib/time.php');
		rmdir($empty . '/lib');
		rmdir($empty);
	}
}

test('a device item is shown when the owner may view the device', function () {
	/* no tree numbered 5 is visible to the owner */
	$result = report_device_item_run(array(), array(5));

	expect($result['calls']['expanded'])->toBe(array(5))
		->and($result['calls']['device_checks'])->toBe(array(array(5, 7)))
		->and($result['html'])->toContain('<!-- device 5 -->');
});

test('a device item is hidden when the owner may not view the device', function () {
	/* tree 5 is visible, which must not matter for device 5 */
	$result = report_device_item_run(array(5), array());

	expect($result['calls']['expanded'])->toBe(array())
		->and($result['html'])->not->toContain('<!-- device');
});

test('a device item never consults tree permissions', function () {
	$result = report_device_item_run(array(5), array(5));

	expect($result['calls']['tree_checks'])->toBe(array())
		->and($result['calls']['expanded'])->toBe(array(5));
});
