<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

$root = dirname(__DIR__, 4);

(function () use ($root) {
	$config = array(
		'php_snmp_support' => false,
		'include_path'     => $root . '/include'
	);

	require_once $root . '/lib/snmp.php';
})();

test('issue 7342 rejects an snmpEngineTime value that is the Unix clock', function () {
	$now = 1784363931;

	expect(cacti_snmp_select_uptime(3015, $now, $now))->toBe(3015);
});

test('normal engine time still covers a wrapped sysUpTime value', function () {
	$now = 1784363931;

	expect(cacti_snmp_select_uptime(250000, 50000000, $now))->toBe(5000000000)
		->and(cacti_snmp_select_uptime(4000000, 600, $now))->toBe(4000000)
		->and(cacti_snmp_select_uptime(false, 600, $now))->toBe(60000)
		->and(cacti_snmp_select_uptime('U', 'U', $now))->toBeFalse();
});

test('system uptime display call paths retain the shared selection rule', function () use ($root) {
	$call_counts = array(
		'cmd.php'                => 1,
		'lib/api_device.php'     => 1,
		'lib/api_automation.php' => 1
	);

	foreach ($call_counts as $path => $count) {
		expect(substr_count(file_get_contents($root . '/' . $path), 'cacti_snmp_select_uptime('))->toBeGreaterThanOrEqual($count);
	}
});

test('reindex counters prefer the engine counter while display retains its heuristic', function () {
	$now = 1784363931;

	expect(cacti_snmp_select_reindex_uptime(4000000, 600))->toBe(60000)
		->and(cacti_snmp_select_reindex_uptime(3015, $now))->toBe($now * 100)
		->and(cacti_snmp_select_uptime(3015, $now, $now))->toBe(3015)
		->and(cacti_snmp_select_reindex_uptime(4000000, '0'))->toBe(0)
		->and(cacti_snmp_select_reindex_uptime('0', 'U'))->toBe(0)
		->and(cacti_snmp_select_reindex_uptime(250000, 50000000))->toBe(5000000000)
		->and(cacti_snmp_select_reindex_uptime('4294967295', '2147483647'))->toBe(214748364700);
});

test('reindex counters fall back on unavailable or malformed engine responses', function () {
	foreach (array('U', '', false, null, -1, '-1', '1.5', '1e3', array(), true, 1.5, '2147483648') as $engine) {
		expect(cacti_snmp_select_reindex_uptime('4200', $engine))->toBe(4200);
	}
	foreach (array('U', '', false, null, -1, '1.5', '1e3', array(), true, '4294967296') as $system) {
		expect(cacti_snmp_select_reindex_uptime($system, 'U'))->toBeFalse();
	}
});

test('both reindex cache and PHP assertion use the counter selection contract', function () use ($root) {
	expect(file_get_contents($root . '/cmd.php'))->toContain('$output        = cacti_snmp_select_reindex_uptime(')
		->and(file_get_contents($root . '/lib/poller.php'))->toContain('$assert_value  = cacti_snmp_select_reindex_uptime(');
});

test('device display reuses the uptime reads and shows an unknown placeholder', function () use ($root) {
	$source = file_get_contents($root . '/lib/api_device.php');
	$start  = strpos($source, 'function api_device_ping_device(');
	$body   = substr($source, $start, strpos($source, 'function api_duplicate_device_template', $start) - $start);

	expect(substr_count($body, '.1.3.6.1.6.3.10.2.1.3.0'))->toBe(1)
		->and($body)->toContain('if ($snmp_uptime === false)')
		->and($body)->toContain('"</strong> $snmp_uptime<br>"')
		->and($body)->toContain("print '</span>';");
});
