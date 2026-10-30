<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace RrdProxyWriteRegression;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');
if ($source === false) { throw new \RuntimeException('Cannot read proxy implementation'); }
foreach (['rrdtool_proxy_write', '__rrd_proxy_init', '__rrd_proxy_execute', 'rrd_command_deadline', 'rrdtool_last_rejection'] as $function) {
	// Keep the caller regressions runnable against the old inline writer.
	if ($function === 'rrdtool_proxy_write' && !str_contains($source, 'function rrdtool_proxy_write(')) { continue; }
	eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
}
function socket_select(&$read, &$write, &$except, $seconds, $microseconds) { return 1; }
function socket_set_option($socket, $level, $option, $value) { return true; }
function socket_write($socket, $data) {
	if ($GLOBALS['proxy_write_fail'] && strlen($GLOBALS['proxy_written']) >= 3) { return false; }
	$written = substr($data, 0, 3);
	$GLOBALS['proxy_written'] .= $written;
	return strlen($written);
}
function socket_read($socket, $length, $mode) {
	return $GLOBALS['proxy_written'] === "info fixture_EOT_\r\n" ? "payload\nOK u:0 s:0 r:0\n_EOP_\r\n_EOT_\r\n" : false;
}
function encrypt($value, $key) { return $value; }
function decrypt($value) { return $value; }
function socket_create($domain, $type, $protocol) {
	$socket = \socket_create($domain, $type, $protocol);
	$GLOBALS['proxy_created'][] = $socket;
	return $socket;
}
function socket_connect($socket, $address, $port) { return false; }
function socket_close($socket) { $GLOBALS['proxy_closed'][] = $socket; \socket_close($socket); }
function cacti_log(...$arguments) {}
function read_config_option($name) { return ['rrdp_server' => '127.0.0.1', 'rrdp_server_backup' => '127.0.0.1', 'rrdp_port' => 1, 'rrdp_port_backup' => 2][$name] ?? ''; }

test('proxy writes retry positive short writes and stop on failure', function ($fail) {
	$GLOBALS['proxy_written'] = '';
	$GLOBALS['proxy_write_fail'] = $fail;
	expect(rrdtool_proxy_write(true, 'command_EOT_'))->toBe(!$fail)
		->and($GLOBALS['proxy_written'])->toBe($fail ? 'com' : 'command_EOT_');
})->with([false, true]);

test('both failed proxy connection attempts close their own socket', function () {
	if (!defined('POLLER_VERBOSITY_LOW')) { define('POLLER_VERBOSITY_LOW', 2); }
	$GLOBALS['proxy_created'] = $GLOBALS['proxy_closed'] = [];
	expect(__rrd_proxy_init())->toBeFalse()
		->and($GLOBALS['proxy_created'])->toHaveCount(2)
		->and($GLOBALS['proxy_closed'])->toBe($GLOBALS['proxy_created']);
	expect($GLOBALS['proxy_created'][0])->not->toBe($GLOBALS['proxy_created'][1]);
});

test('proxy execution cannot treat a positive short write as a complete frame', function () {
	foreach (['POLLER_VERBOSITY_LOW' => 2, 'POLLER_VERBOSITY_DEBUG' => 5, 'RRDTOOL_OUTPUT_NULL' => 0, 'RRDTOOL_OUTPUT_STDOUT' => 1, 'RRDTOOL_OUTPUT_GRAPH_DATA' => 3] as $name => $value) {
		if (!defined($name)) { define($name, $value); }
	}
	$GLOBALS['config'] = ['rra_path' => '/fixture'];
	$GLOBALS['proxy_written'] = '';
	$GLOBALS['proxy_write_fail'] = false;
	expect(__rrd_proxy_execute('info fixture', false, RRDTOOL_OUTPUT_STDOUT, [true, 'fixture-key']))->toBe('payload')
		->and($GLOBALS['proxy_written'])->toBe("info fixture_EOT_\r\n");
});
