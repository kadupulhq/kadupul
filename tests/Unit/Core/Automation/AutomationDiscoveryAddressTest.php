<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace AutomationDiscoveryAddressTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 4) . '/lib/automation_runtime.php';
require_once dirname(__DIR__, 4) . '/include/global_constants.php';

$source = file_get_contents(dirname(__DIR__, 4) . '/poller_automation.php');
if (!is_string($source)) {
	throw new \RuntimeException('Discovery source is unavailable.');
}
eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, 'discoverDevices'));

function automation_default_connection() { return new \stdClass(); }
function automation_claim_address($connection, $network, $run, $pid, $thread) {
	return array_shift($GLOBALS['discovery_test_queue']) ?? array();
}
function db_fetch_row_prepared($sql, $parameters) {
	return str_contains($sql, 'FROM automation_networks') ? array(
		'dns_servers' => '', 'name' => 'fixture', 'enable_netbios' => '',
		'snmp_id' => 1, 'poller_id' => 1, 'site_id' => 0,
		'ping_method' => PING_TCP, 'ping_retries' => 1, 'ping_port' => 80, 'ping_timeout' => 10
	) : array();
}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log(...$args) {}
function automation_debug($message) {}
function automation_get_pid() { return '[fixture]'; }
function gethostbyaddr($address) { return $address; }
function markIPRunning($address, $network) { $GLOBALS['discovery_test_running'][] = $address; }
function markIPDone($address, $network) { $GLOBALS['discovery_test_done'][] = $address; }
function updateDownDevice($network, $address) {}

class Net_Ping {
	public array $host = array();
	public int $retries;
	public int $port;
	public function ping(...$arguments): bool {
		$GLOBALS['discovery_test_ping'][] = $this->host['hostname'];
		return false;
	}
}

test('every queued IPv4 endpoint reaches the discovery ping consumer', function ($range, $offset, $address) {
	$GLOBALS['run_id'] = str_repeat('d', 32);
	$decoded = \automation_address_range($range);
	expect(\automation_address_at($decoded, $offset))->toBe($address);
	$GLOBALS['discovery_test_queue'] = array(array('ip_address' => $address));
	$GLOBALS['discovery_test_running'] = $GLOBALS['discovery_test_done'] = $GLOBALS['discovery_test_ping'] = array();
	expect(discoverDevices(1, 1))->toBeTrue();
	expect($GLOBALS['discovery_test_ping'])->toBe(array($address));
	expect($GLOBALS['discovery_test_running'])->toBe(array($address));
	expect($GLOBALS['discovery_test_done'])->toBe(array($address));
})->with(array(
	array('192.0.2.0/23', 254, '192.0.2.255'),
	array('192.0.2.254/31', 1, '192.0.2.255'),
	array('192.0.2.255/32', 0, '192.0.2.255'),
	array('192.0.2.0/23', 255, '192.0.3.0'),
	array('192.0.2.0/30', 0, '192.0.2.1'),
));
