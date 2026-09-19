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
 * Every console caller of remote_agent.php (device ping, data query,
 * realtime polling, network discovery) addresses the collector that owns
 * the device or network. The actions used to accept any host or network
 * id, so an authorized peer could make this collector poll, query or scan
 * resources that belong to another one. They now act only on resources
 * assigned to $config['poller_id'].
 *
 * The action handlers are extracted into this namespace with the database,
 * request and device helpers stubbed. Collector 2 owns host 10 and network
 * 3; collector 1 owns host 20 and network 4.
 */

namespace RemoteAgentLocalResourceTest;

if (!function_exists(__NAMESPACE__ . '\ping_device')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/remote_agent.php');
	$code   = '';

	foreach (array('remote_agent_host_is_local', 'get_snmp_data', 'get_snmp_data_walk', 'ping_device', 'poll_for_data', 'run_remote_data_query', 'run_remote_discovery') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\n/ms', $source, $match)) {
			$code .= $match[0];
		}
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $code);
}

function calls($name) {
	return isset($GLOBALS['remote_agent_local_calls'][$name]) ? $GLOBALS['remote_agent_local_calls'][$name] : array();
}

function record($name, $args) {
	$GLOBALS['remote_agent_local_calls'][$name][] = $args;
}

function owner_of($sql, $id) {
	$owners = strpos($sql, 'automation_networks') !== false ? array(3 => 2, 4 => 1) : array(10 => 2, 20 => 1);

	return isset($owners[$id]) ? $owners[$id] : null;
}

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
	return get_nfilter_request_var($name);
}

function get_nfilter_request_var($name) {
	return isset($GLOBALS['remote_agent_local_request'][$name]) ? $GLOBALS['remote_agent_local_request'][$name] : '';
}

function isset_request_var($name) {
	return isset($GLOBALS['remote_agent_local_request'][$name]);
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return owner_of($sql, $params[0]) === $params[1] ? 1 : 0;
}

function db_fetch_row_prepared($sql, $params = array()) {
	$owner = owner_of($sql, $params[0]);

	if ($owner === null || (strpos($sql, 'poller_id = ?') !== false && $owner !== $params[1])) {
		return array();
	}

	return array_fill_keys(array('hostname', 'snmp_community', 'snmp_version', 'snmp_username', 'snmp_password', 'snmp_auth_protocol', 'snmp_priv_passphrase', 'snmp_priv_protocol', 'snmp_context', 'snmp_engine_id', 'snmp_port', 'snmp_timeout', 'ping_retries', 'max_oids'), 'x');
}

function db_fetch_assoc_prepared($sql, $params = array()) {
	record('poller_item', $params);

	return array();
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function input_validate_input_number($value) {
}

function cacti_snmp_session() {
	record('snmp_session', func_get_args());

	return false;
}

function api_device_ping_device($host_id, $from_remote) {
	record('ping', array($host_id));
}

function run_data_query($host_id, $data_query_id) {
	record('data_query', array($host_id, $data_query_id));
}

function exec_background($php, $args) {
	record('discover', array($args));
}

function read_config_option($name) {
	return '/usr/share/kadupul';
}

function cacti_escapeshellarg($value) {
	return "'" . $value . "'";
}

function cacti_escapeshellcmd($value) {
	return $value;
}

function sleep($seconds) {
}

function run_action($action, array $request) {
	$saved = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;

	$GLOBALS['config']['poller_id']        = 2;
	$GLOBALS['config']['base_path']        = '/usr/share/kadupul';
	$GLOBALS['remote_agent_local_request'] = $request;
	$GLOBALS['remote_agent_local_calls']   = array();

	ob_start();

	try {
		$action();
	} finally {
		$output            = ob_get_clean();
		$GLOBALS['config'] = $saved;
	}

	return $output;
}

test('ping acts only on a device of this collector', function () {
	run_action(__NAMESPACE__ . '\ping_device', array('host_id' => 10));
	expect(calls('ping'))->toBe(array(array(10)));

	$output = run_action(__NAMESPACE__ . '\ping_device', array('host_id' => 20));
	expect(calls('ping'))->toBe(array())
		->and($output)->toContain('is not assigned to this Data Collector');
});

test('the ping refusal prints the device id only as an integer', function () {
	$output = run_action(__NAMESPACE__ . '\ping_device', array('host_id' => '20<script>'));

	expect($output)->toBe('ERROR: Device[20] is not assigned to this Data Collector');
});

test('data queries run only for a device of this collector', function () {
	run_action(__NAMESPACE__ . '\run_remote_data_query', array('host_id' => 10, 'data_query_id' => 5));
	expect(calls('data_query'))->toBe(array(array(10, 5)));

	run_action(__NAMESPACE__ . '\run_remote_data_query', array('host_id' => 20, 'data_query_id' => 5));
	expect(calls('data_query'))->toBe(array());
});

dataset('snmp actions', array('get_snmp_data', 'get_snmp_data_walk'));

test('snmp requests open a session only for a device of this collector', function ($action) {
	run_action(__NAMESPACE__ . '\\' . $action, array('host_id' => 10, 'oid' => '.1.3.6.1.2.1.1.1.0'));
	expect(calls('snmp_session'))->toHaveCount(1);

	$output = run_action(__NAMESPACE__ . '\\' . $action, array('host_id' => 20, 'oid' => '.1.3.6.1.2.1.1.1.0'));
	expect(calls('snmp_session'))->toBe(array())
		->and($output)->toBe('U');
})->with('snmp actions');

test('realtime polling reads items only for a device of this collector', function () {
	run_action(__NAMESPACE__ . '\poll_for_data', array('host_id' => 10, 'local_data_ids' => array(7), 'poller_id' => 'rt1'));
	expect(calls('poller_item'))->not->toBe(array());

	$output = run_action(__NAMESPACE__ . '\poll_for_data', array('host_id' => 20, 'local_data_ids' => array(7), 'poller_id' => 'rt1'));
	expect(calls('poller_item'))->toBe(array())
		->and($output)->toBe('[]');
});

test('discovery starts only for a network of this collector', function () {
	run_action(__NAMESPACE__ . '\run_remote_discovery', array('network' => 3));
	expect(calls('discover'))->toHaveCount(1)
		->and(calls('discover')[0][0])->toContain("--network='3'");

	run_action(__NAMESPACE__ . '\run_remote_discovery', array('network' => 4));
	expect(calls('discover'))->toBe(array());
});
