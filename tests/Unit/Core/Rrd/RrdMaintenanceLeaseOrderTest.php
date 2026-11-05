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
 * Two maintenance paths and the storage lease.
 *
 * rrdtool_function_tune() rewrites an RRD, so it asks for the exclusive lease
 * on every platform. Windows has none, so tuning there is refused, as
 * rrdtool_execute() already refuses tune and resize.
 *
 * The RRD Cleaner rescan empties the queued delete and archive actions before
 * reading the RRA directory. It now takes the storage lease first and keeps
 * the queue when the directory cannot be validated.
 *
 * The rescan also quotes each file name and date it lists with db_qstr(), and
 * the RRD Cleaner page bar carries the search filter URL-encoded.
 */

namespace RrdMaintenanceLeaseOrderTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';

$rrdcleaner = file_get_contents(dirname(__DIR__, 4) . '/rrdcleaner.php');

foreach (array('rrdclean_fill_table', 'rrdclean_truncate_tables', 'get_files', 'list_rrd') as $function) {
	$code = \test_php_function_source($rrdcleaner, $function);

	/* the rescan's directory read is replaced by a probe in the lease tests */
	if ($function === 'rrdclean_fill_table') {
		$code = str_replace('get_files();', 'rrdclean_scan_probe();', $code);
	}

	// nosemgrep: php.lang.security.eval-use.eval-use -- repository source under test
	eval('namespace ' . __NAMESPACE__ . '; use RecursiveDirectoryIterator, RecursiveIteratorIterator;' . $code);
}

if (!function_exists('rrdclean_error_handler')) {
	// set_error_handler() resolves the handler name globally.
	// nosemgrep: php.lang.security.eval-use.eval-use -- fixed test stub
	eval('function rrdclean_error_handler(...$args) { return true; }');
}

const MESSAGE_LEVEL_ERROR = 2;

function read_config_option($name) { return ''; }
function db_execute($sql) { $GLOBALS['rrdclean_queries'][] = $sql; return true; }

function cacti_log($message, ...$args) { $GLOBALS['rrdclean_logs'][] = $message; }
function raise_message($id, $message, $level) { $GLOBALS['rrdclean_messages'][] = $id; }
function __($text) { return $text; }

function rrdclean_scan_probe() {
	/* the rescan's shared lease keeps exclusive maintenance out while it reads */
	$exclusive = \rrd_maintenance_acquire(true);
	$GLOBALS['rrdclean_queries'][] = $exclusive === false ? 'scan under lease' : 'scan without lease';
	\rrd_maintenance_release($exclusive);
}

/* MySQL string literal quoting, as db_qstr() produces */
function db_qstr($value) { return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value) . "'"; }

const MAX_DISPLAY_PAGES = 21;

class ListStop extends \RuntimeException {}

function validate_store_request_vars(...$args) {}
function get_request_var($name) { return $GLOBALS['rrdclean_request'][$name] ?? ''; }
function html_start_box(...$args) {}
function html_end_box(...$args) {}
function filter() {}
function db_fetch_cell(...$args) { return 0; }
function db_fetch_assoc(...$args) { return array(); }
function get_order_string(...$args) { return ''; }
function html_nav_bar($url, ...$args) { throw new ListStop($url); }


test('a rescan keeps queued actions when the RRA directory cannot be validated', function () {
	$saved     = $GLOBALS['config'] ?? null;
	$reporting = error_reporting();

	$GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => sys_get_temp_dir() . '/rrdclean-missing-' . bin2hex(random_bytes(8)));
	$GLOBALS['rrdclean_queries']  = array();
	$GLOBALS['rrdclean_messages'] = array();
	$GLOBALS['rrdclean_logs']     = array();

	try {
		rrdclean_fill_table();
	} finally {
		error_reporting($reporting);
		$GLOBALS['config'] = $saved;
	}

	expect($GLOBALS['rrdclean_queries'])->toBe(array())
		->and($GLOBALS['rrdclean_messages'])->toBe(array('rrd_cleanup_storage'))
		->and($GLOBALS['rrdclean_logs'][0])->toContain('Queued actions were kept');
});

test('a rescan of a valid RRA directory still rebuilds the list under the lease', function () {
	if (!function_exists('posix_geteuid')) {
		$this->markTestSkipped('The storage lease needs PHP POSIX.');
	}

	$saved     = $GLOBALS['config'] ?? null;
	$reporting = error_reporting();
	$dir       = sys_get_temp_dir() . '/rrdclean-valid-' . bin2hex(random_bytes(8));
	mkdir($dir, 0700);

	$GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => $dir);
	$GLOBALS['rrdclean_queries']  = array();
	$GLOBALS['rrdclean_messages'] = array();

	try {
		$probe = rrd_maintenance_acquire();
		expect($probe)->not->toBeFalse('the fixture directory must be trusted for this case');
		rrd_maintenance_release($probe);

		rrdclean_fill_table();

		/* the lease was released: an exclusive request succeeds at once */
		$exclusive = rrd_maintenance_acquire(true);
		expect($exclusive)->not->toBeFalse();
		rrd_maintenance_release($exclusive);
	} finally {
		error_reporting($reporting);
		$GLOBALS['config'] = $saved;
		rmdir($dir);
	}

	expect($GLOBALS['rrdclean_queries'])->toBe(array('TRUNCATE TABLE `data_source_purge_temp`', 'TRUNCATE TABLE `data_source_purge_action`', 'scan under lease'))
		->and($GLOBALS['rrdclean_messages'])->toBe(array());
});

test('tuning on Windows is refused instead of running without a lease', function () {
	$root = dirname(__DIR__, 4);
	$dir  = sys_get_temp_dir() . '/rrd-tune-windows-' . bin2hex(random_bytes(8));
	mkdir($dir, 0700);
	file_put_contents($dir . '/source.rrd', 'fixture');
	file_put_contents($dir . '/global_arrays.php', '<?php $data_source_types = array(1 => "GAUGE");');
	// The RRDtool boundary only records that it ran.
	file_put_contents($dir . '/rrdtool', "#!/bin/sh\ntouch " . escapeshellarg($dir . '/tuned') . "\n");
	chmod($dir . '/rrdtool', 0700);
	$config = array('cacti_server_os' => 'win32', 'rra_path' => $dir, 'include_path' => $dir, 'is_web' => true);
	$tune   = array('data_source_id' => 1, 'data-source-type' => 1, 'heartbeat' => 777, 'minimum' => '', 'maximum' => '', 'data-source-rename' => '');
	file_put_contents($dir . '/tune.php', '<?php $config = ' . var_export($config, true) . ';' .
		'define("CACTI_LOCALE", "en-US"); define("POLLER_VERBOSITY_DEBUG", 5);' .
		'function read_config_option($name) { return $name === "path_rrdtool" ? __DIR__ . "/rrdtool" : ""; }' .
		'function cacti_log($message, ...$args) { file_put_contents(__DIR__ . "/log", $message . PHP_EOL, FILE_APPEND); }' .
		'function cacti_escapeshellcmd($value) { return escapeshellcmd($value); } function cacti_escapeshellarg($value) { return escapeshellarg($value); }' .
		'function get_data_source_item_name($id) { return "value"; } function get_data_source_path($id, $expand) { return __DIR__ . "/source.rrd"; }' .
		'require ' . var_export($root . '/lib/rrd.php', true) . ';' .
		'echo json_encode(rrdtool_function_tune(' . var_export($tune, true) . '));');

	try {
		$process = proc_open(array(PHP_BINARY, $dir . '/tune.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		$output  = stream_get_contents($pipes[1]);
		$error   = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status  = proc_close($process);

		expect($status)->toBe(0, $error)
			->and($error)->toBe('')
			->and(json_decode($output, true))->toBeFalse()
			->and(file_exists($dir . '/tuned'))->toBeFalse()
			->and(file_get_contents($dir . '/log'))->toContain('unsupported on Windows');
	} finally {
		foreach (glob($dir . '/*') as $file) {
			unlink($file);
		}

		rmdir($dir);
	}
});

test('the rescan quotes each listed file name as an SQL string', function () {
	$saved     = $GLOBALS['config'] ?? null;
	$reporting = error_reporting();
	$dir       = sys_get_temp_dir() . '/rrdclean-names-' . bin2hex(random_bytes(8));
	mkdir($dir, 0700);
	file_put_contents($dir . "/it's.rrd", 'x');
	touch($dir . "/it's.rrd", 1700000000);
	file_put_contents($dir . '/plain.rrd', 'xy');
	touch($dir . '/plain.rrd', 1700000000);

	$GLOBALS['config']           = array('cacti_server_os' => 'unix', 'rra_path' => $dir);
	$GLOBALS['rra_path']         = $dir . '/';
	$GLOBALS['rrdclean_queries'] = array();

	try {
		get_files();
	} finally {
		error_reporting($reporting);
		$GLOBALS['config'] = $saved;
		unlink($dir . "/it's.rrd");
		unlink($dir . '/plain.rrd');
		rmdir($dir);
	}

	$date   = date('Y-m-d H:i:s', 1700000000);
	$insert = end($GLOBALS['rrdclean_queries']);

	expect($insert)->toContain("('it\\'s.rrd', 1, '$date',0)")
		->and($insert)->toContain("('plain.rrd', 2, '$date',0)");
});

test('the RRD Cleaner page bar carries the search filter URL-encoded', function () {
	$saved     = $GLOBALS['config'] ?? null;
	$reporting = error_reporting();

	$GLOBALS['config']           = array('url_path' => '/cacti/');
	$GLOBALS['rrdclean_request'] = array('rows' => 10, 'page' => 1, 'age' => 0, 'filter' => 'a b&page=9"x');

	try {
		list_rrd();
		$url = null;
	} catch (ListStop $stop) {
		$url = $stop->getMessage();
	} finally {
		error_reporting($reporting);
		$GLOBALS['config'] = $saved;
	}

	expect($url)->toBe('/cacti/rrdcleaner.php?filter=a%20b%26page%3D9%22x');

	parse_str(parse_url($url, PHP_URL_QUERY), $query);

	expect($query)->toBe(array('filter' => 'a b&page=9"x'));
});
