<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$root = dirname(__DIR__, 2);
[$script, $scenario, $directory, $flag] = $argv;
$flag = (int) $flag;
if (isset($argv[4])) {
	define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
	require __DIR__ . '/rrd-process-coverage.php';
}
pcntl_alarm(6);
require $root . '/include/global_constants.php';
define('CACTI_LOCALE', 'en-US');
$config = ['cacti_server_os' => 'unix', 'is_web' => false, 'rra_path' => $directory, 'library_path' => $root . '/lib', 'rrd_command_timeout' => 1];
$logs = [];
function cacti_log($message, ...$arguments) { $GLOBALS['logs'][] = $message; }
function cacti_session_close() {}
function cacti_escapeshellarg($value) { return escapeshellarg($value); }
function read_config_option($name, $force = false) {
	return $GLOBALS['options'][$name] ?? ($name === 'path_rrdtool' ? $GLOBALS['directory'] . '/rrd-server' : '');
}
function get_data_source_path(...$arguments) { return $GLOBALS['directory'] . '/sample.rrd'; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return cacti_sizeof($value); }
require $root . '/tests/Helpers/PhpSource.php';
$source = file_get_contents($root . '/lib/functions.php');
if ($source === false) { throw new RuntimeException('Cannot read production path helpers'); }
foreach (['cacti_has_control_chars', 'cacti_rrdtool_valid_path', 'cacti_rrdtool_valid_path_token'] as $function) {
	eval(test_php_function_source($source, $function));
}
require $root . '/lib/rrd.php';
$encryption = false;
$result = [];
$started = hrtime(true);

if (str_starts_with($scenario, 'fetch-')) {
	$reply = $scenario === 'fetch-zero' ? "value\n1700000300: 0\n" : ($scenario === 'fetch-value' ? "ERROR\n1700000300: 2.5\n" : "\r\n \tERROR: opening missing.rrd: No such file\n");
	$server = '#!' . PHP_BINARY . "\n<?php \$input = stream_get_contents(STDIN); print str_starts_with(\$input, 'info ') ? "
		. var_export("rra[0].cf = \"AVERAGE\"\nrra[1].cf = \"MAX\"\nOK u:0 s:0 r:0\n", true)
		. ' : ' . var_export($reply . "OK u:0 s:0 r:0\n", true) . ';';
	file_put_contents($directory . '/rrd-server', $server);
	chmod($directory . '/rrd-server', 0700);
	$result = rrdtool_function_fetch(11, 1700000000, 1700000600, 300, (bool) $flag);
	if ($scenario === 'fetch-stats') {
		require $root . '/lib/graph_variables.php';
		$result = ['fetch' => $result, 'stats' => rrdtool_function_stats([11 => ['value']], 1700000000, 1700000600, 95, 300), 'bandwidth' => bandwidth_summation(11, 1700000000, 1700000600, 1, 300)];
	}
} elseif (str_starts_with($scenario, 'oneshot-')) {
	$mode = $scenario === 'oneshot-hostile-path' ? 'large' : substr($scenario, 8);
	$server = '#!' . PHP_BINARY . "\n<?php\n" . '$mode = ' . var_export($mode, true) . ';' . <<<'SERVER'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
file_put_contents(__DIR__ . '/pid', (string) getmypid());
if ($mode === 'large') {
	$first = fread(STDIN, 6);
	print str_repeat("binary\0payload\n", 32768);
	fflush(STDOUT);
	$input = $first . stream_get_contents(STDIN);
	file_put_contents(__DIR__ . '/input-hash', hash('sha256', $input));
	print hash('sha256', $input) . "\nOK u:0 s:0 r:0\n";
	exit(0);
}
if ($mode === 'diagnostic') {
	stream_get_contents(STDIN);
	print "ERROR: declined\n";
	fwrite(STDERR, "detail\n");
	exit(7);
}
if ($mode === 'partial') { print 'partial'; fflush(STDOUT); }
if ($mode === 'closed-output') { fclose(STDOUT); }
while (true) { usleep(10000); }
SERVER;
	$binary = $directory . ($scenario === 'oneshot-hostile-path' ? '/rrd server;echo injected' : '/rrd-server');
	$options['path_rrdtool'] = $binary;
	file_put_contents($binary, $server);
	chmod($binary, 0700);
	$command = in_array($mode, ['large', 'not-reading'], true) ? 'first ' . str_repeat('remaining-input ', 65536) : 'info fixture';
	ob_start();
	$returned = __rrd_execute($command, false, $flag);
	$printed = ob_get_clean();
	$pid = is_file($directory . '/pid') ? (int) file_get_contents($directory . '/pid') : 0;
	$result = ['returned' => is_string($returned) ? base64_encode($returned) : $returned, 'printed' => base64_encode($printed), 'alive' => $pid > 0 && posix_kill($pid, 0), 'hash' => is_file($directory . '/input-hash') ? file_get_contents($directory . '/input-hash') : null];
} elseif ($scenario === 'connect-failure') {
	// Release a reserved local port so both connection attempts are refused.
	$listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
	if (!$listener || !socket_bind($listener, '127.0.0.1', 0) || !socket_getsockname($listener, $address, $port)) { exit(2); }
	socket_close($listener);
	$options = ['rrdp_server' => '127.0.0.1', 'rrdp_port' => $port, 'rrdp_server_backup' => '127.0.0.1', 'rrdp_port_backup' => $port];
	for ($i = 0; $i < 12; $i++) {
		if (__rrd_proxy_init() !== false) { exit(3); }
	}
	// Native socket lifecycle is checked separately by deterministic wrapper tests.
	$result = ['attempts' => count($logs)];
} else {
	if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) { exit(2); }
	$pipe = [$sockets[0], 'fixture-key'];
	if ($scenario === 'proxy-write-stall') {
		socket_set_option($sockets[0], SOL_SOCKET, SO_SNDBUF, 1024);
		$result = rrdtool_proxy_write($sockets[0], str_repeat('x', 1048576), hrtime(true) + 150000000);
		rrdtool_proxy_abort($sockets[0]);
	} elseif (in_array($scenario, ['proxy-silent', 'proxy-drip', 'proxy-budget'], true)) {
		$cap =& rrd_command_deadline();
		$cap = hrtime(true) + 150000000;
		$pid = pcntl_fork();
		if ($pid === -1) { exit(2); }
		if ($pid === 0) {
			socket_close($sockets[0]);
			if ($scenario === 'proxy-drip') {
				for ($i = 0; $i < 30; $i++) { @socket_write($sockets[1], 'x'); usleep(10000); }
			} else { usleep($scenario === 'proxy-budget' ? 1000000 : 300000); }
			socket_close($sockets[1]);
			posix_kill(getmypid(), SIGKILL);
		}
		socket_close($sockets[1]);
		$returned = __rrd_proxy_execute('info fixture', false, $flag, $pipe);
		$elapsed = (hrtime(true) - $started) / 1e9;
		$second = null;
		if ($scenario !== 'proxy-budget') {
			$second = __rrd_proxy_execute('info fixture', false, RRDTOOL_OUTPUT_BOOLEAN, $pipe);
			__rrd_proxy_close($pipe);
		}
		$closed = false;
		try { socket_get_option($sockets[0], SOL_SOCKET, SO_TYPE); } catch (Error $e) { $closed = true; }
		pcntl_waitpid($pid, $status);
		$result = ['returned' => $returned, 'second' => $second, 'closed' => $closed, 'elapsed' => $elapsed];
	} else {
		$replies = ['proxy-error' => "ERROR: failed\n", 'proxy-error-ok' => "ERROR: failed\nOK u:0 s:0 r:0\n", 'proxy-missing-ok' => 'payload', 'proxy-success' => "payload\nOK u:0 s:0 r:0\n", 'proxy-bare-ok' => "payload\nOK\n"];
		$reply = $replies[$scenario];
		socket_write($sockets[1], $reply . "_EOP_\r\n_EOT_\r\n");
		$result = __rrd_proxy_execute('info fixture', false, $flag, $pipe);
		socket_close($sockets[0]);
	}
	if (is_object($sockets[1])) {
		try { socket_close($sockets[1]); } catch (Error $e) {}
	}
}
print json_encode(['result' => $result, 'elapsed' => (hrtime(true) - $started) / 1e9, 'logs' => $logs], JSON_THROW_ON_ERROR);
