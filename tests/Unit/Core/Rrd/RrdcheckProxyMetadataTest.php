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
 * With RRDproxy as the storage location, RRDcheck asks the proxy whether an
 * RRD exists, and the RRD lives on the proxy host. The local writable probe
 * and filemtime() that follow then test a path this server does not have:
 * the probe creates and removes a placeholder, and filemtime() fails so the
 * RRD is reported stale. The shipped do_rrdcheck() runs here in a child
 * process against stubbed proxy, rrdtool and database calls.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

function rrdcheck_metadata_run(array $scenario) : array {
	$root = dirname(__DIR__, 4);
	$fsrc = file_get_contents($root . '/lib/functions.php');
	$rsrc = file_get_contents($root . '/lib/rrdcheck.php');

	$shipped = "<?php\n";

	foreach (array('cacti_sizeof', 'is_resource_writable') as $name) {
		$shipped .= cacti_test_rrd_function_source($fsrc, $name) . "\n\n";
	}

	foreach (array('rrdcheck_debug', 'do_rrdcheck') as $name) {
		$shipped .= cacti_test_rrd_function_source($rsrc, $name) . "\n\n";
	}

	file_put_contents($scenario['work'] . '/shipped.php', $shipped);
	file_put_contents($scenario['work'] . '/scenario.json', json_encode($scenario));

	file_put_contents($scenario['work'] . '/child.php', <<<'PHP'
<?php
$scenario = json_decode(file_get_contents($argv[1]), true);
$warnings = array();
$messages = array();
$calls    = array();

set_error_handler(function ($errno, $errstr) use (&$warnings) {
	$warnings[] = $errstr;

	return true;
});

define('RRDTOOL_OUTPUT_STDOUT', 1);
define('RRDTOOL_OUTPUT_BOOLEAN', 4);

$config = array('rra_path' => $scenario['work'] . '/rra');
$type   = '';

function read_config_option($name, $force = false) {
	return $GLOBALS['scenario']['config'][$name] ?? '';
}

function set_config_option($name, $value) {
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
}

function db_fetch_assoc($sql, $log = true) {
	return array();
}

function array_rekey($array, $key, $key_value) {
	return array(1 => array('step' => 300, 'steps' => 1, 'rows' => 288));
}

function db_execute_prepared($sql, $params = array(), $log = true) {
	if (strpos($sql, 'INSERT INTO rrdcheck') !== false) {
		$GLOBALS['messages'][] = $params[1];
	}

	return true;
}

function get_rrdfiles($thread_id = 1, $max_threads = 1) {
	return array(array(
		'data_source_profile_id' => 1,
		'local_data_id'          => 42,
		'data_source_path'       => $GLOBALS['scenario']['file'],
		'data_source_names'      => 'a',
		'rrd_step'               => '300',
		'rrd_heartbeat'          => '600',
		'profile_heartbeat'      => '600',
		'profile_step'           => '300',
	));
}

function rrdcheck_test_reply($command) {
	$verb = is_array($command) ? $command[0] : strtok($command, ' ');

	$GLOBALS['calls'][] = $verb;

	if ($verb == 'info') {
		return "step = 300\nlast_update = " . time() . "\nds[a].minimal_heartbeat = 600\nOK u:0.00 s:0.00 r:0.00";
	}

	return " a\n\n" . (time() - 300) . ": 1.0000000000e+00\nOK u:0.00 s:0.00 r:0.00";
}

function rrdtool_quote_argument($string) {
	return "'" . $string . "'";
}

function rrd_init($output_to_term = true) {
	return 'proxy';
}

function rrd_close($rrdtool_pipe) {
}

function rrdcheck_rrdtool_init() {
	return array('process', array());
}

function rrdcheck_rrdtool_close($process) {
}

function rrdtool_execute_path_command($command, $path, $suffix = '', $log_to_stdout = false, $output_flag = RRDTOOL_OUTPUT_STDOUT, $rrdtool_pipe = false, $logopt = 'WEBLOG') {
	$GLOBALS['calls'][] = $command;

	return true;
}

function rrdtool_execute($command_line, $log_to_stdout, $output_flag, $rrdtool_pipe = false, $logopt = 'WEBLOG') {
	return rrdcheck_test_reply($command_line);
}

function rrdcheck_rrdtool_execute($command, $pipes) {
	return rrdcheck_test_reply($command);
}

require $scenario['work'] . '/shipped.php';

do_rrdcheck(1);

print json_encode(array('messages' => $messages, 'calls' => $calls, 'warnings' => $warnings));
PHP);

	$process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $scenario['work'] . '/child.php', $scenario['work'] . '/scenario.json'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	foreach (array('child.php', 'shipped.php', 'scenario.json') as $file) {
		unlink($scenario['work'] . '/' . $file);
	}

	$decoded = json_decode($stdout, true);

	if (!is_array($decoded)) {
		throw new RuntimeException('rrdcheck child failed: ' . $stdout . $stderr);
	}

	return $decoded;
}

function rrdcheck_metadata_workdir() : string {
	$work = sys_get_temp_dir() . '/cacti-rrdcheck-' . bin2hex(random_bytes(6));

	mkdir($work . '/rra', 0700, true);

	register_shutdown_function(function () use ($work) {
		foreach (glob($work . '/rra/*') as $file) {
			chmod($file, 0600);
			unlink($file);
		}

		rmdir($work . '/rra');
		rmdir($work);
	});

	return $work;
}

test('an RRD on RRDproxy is not probed or stat checked on the local filesystem', function () {
	$work = rrdcheck_metadata_workdir();
	$file = $work . '/rra/remote.rrd';
	$old  = time() - 7200;

	/* a placeholder created and removed in rra/ moves the directory modify time */
	touch($work . '/rra', $old, $old);

	$run = rrdcheck_metadata_run(array('work' => $work, 'file' => $file, 'config' => array('storage_location' => '1')));

	clearstatcache();

	expect($run['messages'])->not->toContain('RRDfile is not writable - ' . $file)
		->and($run['messages'])->not->toContain('RRDfile modify time older than hour - ' . $file)
		->and($run['messages'])->not->toContain("RRDfile does not exist - '" . $file . "'")
		->and(filemtime($work . '/rra'))->toBe($old)
		->and(scandir($work . '/rra'))->toBe(array('.', '..'))
		->and(implode("\n", $run['warnings']))->not->toContain('filemtime');

	/* the proxy still answers the existence, info and fetch requests */
	expect($run['calls'])->toBe(array('file_exists', 'info', 'fetch'));
});

test('a local RRD keeps the 1.2.31 writable and modify time checks', function () {
	$work = rrdcheck_metadata_workdir();

	$stale = $work . '/rra/stale.rrd';
	touch($stale, time() - 7200);
	chmod($stale, 0444);

	$run = rrdcheck_metadata_run(array('work' => $work, 'file' => $stale, 'config' => array('storage_location' => '')));

	expect($run['messages'])->toContain('RRDfile is not writable - ' . $stale)
		->and($run['messages'])->toContain('RRDfile modify time older than hour - ' . $stale)
		->and($run['calls'])->toBe(array('info', 'fetch'));

	$fresh = $work . '/rra/fresh.rrd';
	touch($fresh);

	$run = rrdcheck_metadata_run(array('work' => $work, 'file' => $fresh, 'config' => array('storage_location' => '')));

	expect($run['messages'])->not->toContain('RRDfile is not writable - ' . $fresh)
		->and($run['messages'])->not->toContain('RRDfile modify time older than hour - ' . $fresh);
})->skip(function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes to a read-only file');
