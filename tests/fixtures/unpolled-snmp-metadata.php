<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = $argv[1];
require $root . '/include/global_constants.php';
require $root . '/lib/ping.php';
require $root . '/tests/Helpers/PhpStatementSource.php';
if (!function_exists('socket_create')) throw new RuntimeException('The availability test requires sockets support');
$respond = $argv[3] === 'up';
$sample = array(
	'id' => 17, 'hostname' => 'snmp-fixture.invalid', 'ping_port' => 0,
	'ping_method' => PING_ICMP, 'ping_retries' => 2, 'ping_timeout' => 500,
	'availability_method' => constant($argv[4]), 'poller_id' => 1, 'disabled' => '',
	'snmp_community' => (int) $argv[2] === 3 ? '' : 'test-only-community',
	'snmp_version' => (int) $argv[2], 'snmp_username' => 'test-only-user',
	'snmp_password' => 'test-only-auth', 'snmp_auth_protocol' => 'SHA',
	'snmp_priv_passphrase' => 'test-only-privacy', 'snmp_priv_protocol' => 'AES',
	'snmp_context' => 'test-context', 'snmp_engine_id' => '8000000001020304',
	'snmp_port' => 1161, 'snmp_timeout' => 777,
);

// SQLite exercises this portable SELECT; the column identities come from the shipped host schema.
$schema = file_get_contents($root . '/cacti.sql');
if ($schema === false || !preg_match('/CREATE TABLE `?host`?\s*\((.*?)\)\s*ENGINE=/si', $schema, $table)) {
	throw new RuntimeException('Cannot read the shipped host schema');
}
preg_match_all('/^\s*`?([a-zA-Z0-9_]+)`?\s+(?:varchar|int|bigint|smallint|mediumint|tinyint|char|text|mediumtext|longtext|double|float|decimal|timestamp|datetime|enum)\b/mi', $table[1], $columns);
if (array_diff(array_keys($sample), $columns[1]) !== array()) throw new RuntimeException('Fixture columns are absent from the host schema');
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$definitions = array();
foreach ($sample as $column => $value) $definitions[] = '"' . $column . '" ' . (is_int($value) ? 'INTEGER' : 'TEXT');
$db->exec('CREATE TABLE host (' . implode(',', $definitions) . ')');
$db->exec('CREATE TABLE poller_item (host_id INTEGER)');
$insert = $db->prepare('INSERT INTO host VALUES (' . implode(',', array_fill(0, count($sample), '?')) . ')');
foreach (array($sample, array_replace($sample, array('id' => 18, 'disabled' => 'on')),
	array_replace($sample, array('id' => 19, 'poller_id' => 2)), array_replace($sample, array('id' => 20))) as $row) {
	$insert->execute(array_values($row));
}
$db->exec('INSERT INTO poller_item VALUES (20)');
$queries = $session_calls = $session_closes = 0;
$expected_session = array($sample['hostname'], $sample['snmp_community'], $sample['snmp_version'],
	$sample['snmp_username'], $sample['snmp_password'], $sample['snmp_auth_protocol'],
	$sample['snmp_priv_passphrase'], $sample['snmp_priv_protocol'], $sample['snmp_context'],
	$sample['snmp_engine_id'], $sample['snmp_port'], $sample['snmp_timeout'], 2, 1);

function db_fetch_assoc_prepared($sql, $params = array(), $log = true, $db_conn = false) {
	global $db, $queries;
	$queries++;
	$statement = $db->prepare($sql);
	$statement->execute($params);
	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function __() { return func_get_arg(0); }
function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {}
function read_config_option($name, $force = false) { return 1; }

class UnpolledSnmpSession {
	public function close() { global $session_closes; $session_closes++; }
}

function cacti_snmp_session($hostname, $community, $version, $auth_user = '', $auth_pass = '',
	$auth_proto = '', $priv_pass = '', $priv_proto = '', $context = '', $engineid = '',
	$port = 161, $timeout_ms = 500, $retries = 0, $max_oids = 10, $bulk_walk_size = 10) {
	global $expected_session, $session_calls;
	$session_calls++;
	if (func_get_args() !== $expected_session) throw new RuntimeException('Incorrect SNMP configuration handoff');
	return new UnpolledSnmpSession();
}

function cacti_snmp_session_get($session, $oid, $strip_alpha = false) {
	global $respond;
	return $respond ? '12345' : false;
}

function cacti_snmp_session_getnext($session, $oid) {
	global $respond;
	return $respond ? '12345' : false;
}

class UnpolledNoNetworkPing extends Net_Ping {
	public function ping_icmp() {
		$this->ping_status = 'down';
		$this->ping_response = 'Fixture ping failed';
		return false;
	}
}

// Execute the actual top-level producer assignment without bootstrapping a poller.
$source = file_get_contents($root . '/cmd.php');
if ($source === false) throw new RuntimeException('Cannot read the poller');
$sql_where = 'AND h.poller_id = ?';
$params = array(1);
eval(test_php_assignment_source($source, '$hosts', 'db_fetch_assoc_prepared'));
if (array_column($hosts, 'id') !== array(17)) throw new RuntimeException('Status-only host selection changed');
$host = $hosts[0];
$ping = new UnpolledNoNetworkPing();
$ping->host = $host;
$ping->port = $host['ping_port'];
$available = $ping->ping($host['availability_method'], $host['ping_method'], $host['ping_timeout'], $host['ping_retries']);
print json_encode(array('queries' => $queries, 'session_calls' => $session_calls,
	'session_closes' => $session_closes, 'available' => $available), JSON_THROW_ON_ERROR);
