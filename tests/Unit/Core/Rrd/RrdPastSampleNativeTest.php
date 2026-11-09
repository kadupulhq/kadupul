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
 * rrdtool_function_update() must not report a sample as stored when RRDtool
 * refused it as older than the last update.  This runs the production writer
 * against a real RRDtool binary.
 */

test('real RRDtool rejects old and duplicate samples without claiming they were stored', function (bool $persistent) {
	$binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
	if (!is_executable($binary)) {
		$this->markTestSkipped('Real RRDtool is required.');
	}

	$root = dirname(__DIR__, 4);
	$dir  = sys_get_temp_dir() . '/rrd-past-update-' . bin2hex(random_bytes(8));
	mkdir($dir, 0700);

	$program = '<?php $root=' . var_export($root, true) . ';$binary=' . var_export($binary, true) . ';$persistent=' . var_export($persistent, true) . ';';
	$program .= <<<'PHP'
$config = array('cacti_server_os' => 'unix', 'rra_path' => __DIR__, 'is_web' => false, 'rrd_command_timeout' => 10);
require $root . '/include/global_constants.php';
define('CACTI_LOCALE', 'en-US');
function read_config_option($key) { return $key === 'path_rrdtool' ? $GLOBALS['binary'] : ''; }
function cacti_log(...$args) {}
function cacti_session_close() {}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_escapeshellarg($value) { return escapeshellarg($value); }
function get_rrdtool_version() { return '1.7'; }
function cacti_version_compare(...$args) { return version_compare(...$args); }
require $root . '/tests/Helpers/PhpSource.php';
$functions = file_get_contents($root . '/lib/functions.php');
foreach (array('cacti_rrdtool_valid_path', 'cacti_rrdtool_valid_ds_name', 'cacti_rrdtool_valid_ds_template', 'cacti_has_control_chars') as $name) {
	eval(test_php_function_source($functions, $name));
}
require $root . '/lib/rrd.php';
$path = __DIR__ . '/sample.rrd';
$pipe = $persistent ? rrd_init(false, false, true) : false;
if ($persistent && !is_resource($pipe)) { throw new RuntimeException('Writer unavailable'); }
if (rrdtool_execute(array('create', $path, '--start', '1699999900', '--step', '60', 'DS:value:GAUGE:600:U:U', 'RRA:AVERAGE:0.5:1:20'), false, RRDTOOL_OUTPUT_BOOLEAN, $pipe) !== true
	|| rrdtool_execute(array('update', $path, '1700000300:5'), false, RRDTOOL_OUTPUT_BOOLEAN, $pipe) !== true) {
	throw new RuntimeException('Unable to seed real RRD');
}
$before  = hash_file('sha256', $path);
$updates = array($path => array('local_data_id' => 1, 'data_template_id' => 0, 'times' => array(1700000000 => array('value' => '99'), 1700000300 => array('value' => '88'))));
$stale   = rrdtool_function_update($updates, $pipe, $completed);
$oldCompleted = $completed[$path] ?? array();
$unchanged    = hash_file('sha256', $path) === $before;
$updates[$path]['times'] = array(1700000360 => array('value' => '7'));
$fresh          = rrdtool_function_update($updates, $pipe, $completed);
$freshCompleted = $completed[$path] ?? array();
$stored         = rrdtool_execute(array('lastupdate', $path), false, RRDTOOL_OUTPUT_STDOUT, $pipe);
$replay         = rrdtool_function_update($updates, $pipe, $completed);
$replayCompleted = $completed[$path] ?? array();
if ($persistent) { rrd_close($pipe); }
echo json_encode(array($stale, $oldCompleted, $unchanged, $fresh, $freshCompleted, $stored, $replay, $replayCompleted));
PHP;
	file_put_contents($dir . '/probe.php', $program);

	try {
		$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=24575', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		expect(is_resource($process))->toBeTrue();
		$output = stream_get_contents($pipes[1]);
		$error  = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		expect(proc_close($process))->toBe(0, $error . $output);
		expect($error)->toBe('');

		$result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		// Old and duplicate samples are consumed as rejected (false), never stored (true).
		expect($result[0])->toBeFalse();
		expect($result[1])->toBe(array(1700000000 => false, 1700000300 => false));
		expect($result[2])->toBeTrue();
		// A newer sample is still stored.
		expect($result[3])->toBe(1);
		expect($result[4])->toBe(array(1700000360 => true));
		expect($result[5])->toContain('1700000360: 7');
		// Replaying the stored sample is a duplicate and is not counted as stored.
		expect($result[6])->toBeFalse();
		expect($result[7])->toBe(array(1700000360 => false));
	} finally {
		foreach (glob($dir . '/*') as $file) {
			unlink($file);
		}
		@rmdir($dir);
	}
})->with(array('one-shot' => false, 'persistent' => true));
