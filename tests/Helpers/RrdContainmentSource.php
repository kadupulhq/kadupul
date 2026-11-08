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
 * Loads the shipped rrd_check_containment() as a global function for tests
 * that evaluate RRD functions apart from the rest of Cacti. The copy reads
 * its setting from $GLOBALS['test_rrd_containment_settings'] instead of the
 * settings table, so a test turns the restriction on by setting
 * 'rrd_path_containment' there. The path helpers it relies on are the
 * shipped functions.
 */

require_once __DIR__ . '/PhpSource.php';

function test_rrd_containment_read_config_option($name) {
	return isset($GLOBALS['test_rrd_containment_settings'][$name]) ? $GLOBALS['test_rrd_containment_settings'][$name] : '';
}

function test_load_rrd_check_containment(string $root): void {
	if (function_exists('rrd_check_containment')) {
		return;
	}

	$rrd       = file_get_contents($root . '/lib/rrd.php');
	$functions = file_get_contents($root . '/lib/functions.php');

	if ($rrd === false || $functions === false) {
		throw new RuntimeException('Cannot read the RRD path helpers');
	}

	if (!function_exists('rrd_check_path')) {
		eval(test_php_function_source($rrd, 'rrd_check_path'));
	}

	foreach (array('cacti_path_is_within', 'cacti_normalize_windows_path') as $name) {
		if (!function_exists($name)) {
			eval(test_php_function_source($functions, $name));
		}
	}

	eval(preg_replace('/\bread_config_option\(/', 'test_rrd_containment_read_config_option(', test_php_function_source($rrd, 'rrd_check_containment')));
}
