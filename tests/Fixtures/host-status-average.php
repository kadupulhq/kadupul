<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$host = array_merge(array(
	'id' => 17, 'status' => HOST_UP, 'status_event_count' => 0,
	'status_fail_date' => '', 'status_rec_date' => '', 'status_last_error' => '',
	'snmp_community' => 'public', 'snmp_version' => 2,
	'min_time' => 100, 'max_time' => 0, 'cur_time' => 0,
), $input['host']);
$writes = array();

function read_config_option($config_name, $force = false) {
	return 3;
}

function db_fetch_row_prepared($sql, $params = array(), $log = true, $db_conn = false) {
	global $host;
	if ($sql !== 'SELECT * FROM host WHERE id = ?' || $params !== array(17)) {
		throw new RuntimeException('Unexpected host lookup');
	}
	return $host;
}

function db_execute_prepared($sql, $params = array(), $log = true, $db_conn = false, $execute_name = 'Exec', $default_value = true, $return_func = 'no_return_function', $return_params = array()) {
	global $writes;
	$writes[] = array('sql' => $sql, 'params' => $params);
	return true;
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
}

$source = file_get_contents($root . '/lib/functions.php');
if ($source === false) throw new RuntimeException('Cannot read host status implementation');
eval(test_php_function_source($source, 'update_host_status'));
$ping = (object) array('ping_status' => $input['sample'], 'snmp_status' => $input['sample'],
	'ping_response' => 'fixture ping', 'snmp_response' => 'fixture snmp');
update_host_status(constant($input['status']), 17, $ping, constant($input['method']), false);
print json_encode($writes, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
