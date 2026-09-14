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
 * call_remote_data_collector() must refuse loopback, link-local and other
 * reserved targets before it opens a connection. The function is extracted
 * from lib/functions.php and run with its database and logging helpers
 * stubbed, so no request leaves the test.
 *
 * The stubs and the extracted function live in this namespace. Other unit
 * tests require lib/functions.php, which defines the same global names, and
 * a shared process would fatal on redeclaration. Unqualified calls inside the
 * namespace resolve to these stubs first and fall back to PHP built-ins.
 */

namespace RemoteDataCollectorReservedAddressTest;

if (!function_exists(__NAMESPACE__ . '\call_remote_data_collector')) {
	$source = \file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
	preg_match('/^function call_remote_data_collector\(.*?^}\n/ms', $source, $match);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return $GLOBALS['rdc_hostname'];
}

function cacti_log($message, $stdout = false, $facility = 'CACTI', $level = '') {
	$GLOBALS['rdc_log'][] = $message;
}

function is_ipaddress($address) {
	return filter_var($address, FILTER_VALIDATE_IP) !== false;
}

function gethostbyname($hostname) {
	return $GLOBALS['rdc_dns'][$hostname] ?? $hostname;
}

function debounce_run_notification($id) {
	return false;
}

function get_default_contextoption($timeout = false) {
	return $GLOBALS['rdc_base_context'] ?? array();
}

function stream_context_create($options = array()) {
	$GLOBALS['rdc_context'] = $options;

	return null;
}

function file_get_contents($url, $use_include_path = false, $context = null) {
	$GLOBALS['rdc_url'] = $url;

	return 'reply';
}

function get_url_type() {
	return 'https';
}

function remote_collector_call(string $hostname, array $dns = array()) : array {
	$GLOBALS['rdc_hostname'] = $hostname;
	$GLOBALS['rdc_dns']      = $dns;
	$GLOBALS['rdc_log']      = [];
	$GLOBALS['rdc_url']      = null;
	$GLOBALS['rdc_context']  = null;

	$result = call_remote_data_collector(2, '/remote_agent.php?action=ping');

	return array($result === 'reply' ? 'connected' : $result, $GLOBALS['rdc_log'], $GLOBALS['rdc_url']);
}

dataset('refused addresses', array(
	'metadata' => array('169.254.169.254'),
	'link-local IPv4' => array('169.254.0.1'),
	'this network' => array('0.0.0.0'),
	'this network range' => array('0.1.2.3'),
	'unspecified IPv6' => array('::'),
	'link-local IPv6' => array('fe80::1'),
	'link-local IPv6 range end' => array('febf::1'),
	'mapped metadata' => array('::ffff:169.254.169.254'),
	'mapped metadata hex' => array('::ffff:a9fe:a9fe'),
	'mapped this network' => array('::ffff:0.0.0.0'),
	'reserved IPv4' => array('240.0.0.1'),
	'broadcast' => array('255.255.255.255'),
));

test('refuses link-local, this-network and reserved collector addresses before connecting', function (string $address) {
	[$result, $log, $url] = remote_collector_call($address);

	expect($result)->toBe('')
		->and($url)->toBeNull()
		->and(implode("\n", $log))->toContain('reserved address');
})->with('refused addresses');

test('refuses bracketed link-local and metadata literals too', function (string $address) {
	[$result, $log, $url] = remote_collector_call($address);

	expect($result)->toBe('')
		->and($url)->toBeNull();
})->with(array('[fe80::1]', '[::ffff:169.254.169.254]', '[::]'));

test('refuses a collector name that resolves to the metadata address', function () {
	[$result, $log] = remote_collector_call('metadata.internal', array('metadata.internal' => '169.254.169.254'));

	expect($result)->toBe('')
		->and(implode("\n", $log))->toContain('reserved address 169.254.169.254');
});

dataset('allowed addresses', array(
	'same host IPv4 loopback' => array('127.0.0.1', array()),
	'loopback range' => array('127.10.20.30', array()),
	'same host IPv6 loopback' => array('::1', array()),
	'mapped loopback' => array('::ffff:127.0.0.1', array()),
	'localhost name' => array('localhost', array('localhost' => '127.0.0.1')),
	'hosts-file loopback name' => array('cacti-main.local', array('cacti-main.local' => '127.0.1.1')),
	'container host network' => array('host.docker.internal', array('host.docker.internal' => '172.17.0.1')),
	'private collector' => array('10.20.30.40', array()),
	'mapped private collector' => array('::ffff:10.20.30.40', array()),
));

test('lets loopback and private collector addresses through to the connection step', function (string $hostname, array $dns) {
	[$result, $log] = remote_collector_call($hostname, $dns);

	expect($result)->toBe('connected')
		->and($log)->toBe(array());
})->with('allowed addresses');

test('builds a reachable URL host for IPv4, names and IPv6 literals', function (string $hostname, array $dns, string $url) {
	[$result, $log, $requested] = remote_collector_call($hostname, $dns);

	expect($result)->toBe('connected')
		->and($requested)->toBe($url);
})->with(array(
	'IPv4'                  => array('127.0.0.1', array(), 'https://127.0.0.1/remote_agent.php?action=ping'),
	'private IPv4'          => array('10.20.30.40', array(), 'https://10.20.30.40/remote_agent.php?action=ping'),
	'host name, pinned'     => array('collector.example.net', array('collector.example.net' => '10.1.2.3'), 'https://10.1.2.3/remote_agent.php?action=ping'),
	'IPv6 loopback'         => array('::1', array(), 'https://[::1]/remote_agent.php?action=ping'),
	'bracketed IPv6'        => array('[::1]', array(), 'https://[::1]/remote_agent.php?action=ping'),
	'mapped loopback'       => array('::ffff:127.0.0.1', array(), 'https://[::ffff:127.0.0.1]/remote_agent.php?action=ping'),
));

test('a collector request never follows a redirect', function (string $hostname, array $dns) {
	[$result, $log, $url] = remote_collector_call($hostname, $dns);

	expect($result)->toBe('connected')
		->and($GLOBALS['rdc_context']['http']['follow_location'])->toBe(0)
		->and($GLOBALS['rdc_context']['http']['max_redirects'])->toBe(0);
})->with(array(
	'IPv4'       => array('10.20.30.40', array()),
	'IPv6'       => array('::1', array()),
	'host name'  => array('collector.example.net', array('collector.example.net' => '10.1.2.3')),
));

test('a collector name is connected to at the address that was checked, with its own Host and certificate name', function () {
	$GLOBALS['rdc_base_context'] = array(
		'ssl'  => array('verify_peer' => true, 'verify_peer_name' => true),
		'http' => array('header' => "X-Plugin: kept\r\n"),
	);

	try {
		[$result, $log, $url] = remote_collector_call('collector.example.net', array('collector.example.net' => '10.1.2.3'));
	} finally {
		unset($GLOBALS['rdc_base_context']);
	}

	expect($url)->toBe('https://10.1.2.3/remote_agent.php?action=ping')
		->and($GLOBALS['rdc_context']['http']['header'])->toBe("X-Plugin: kept\r\nHost: collector.example.net")
		->and($GLOBALS['rdc_context']['ssl'])->toBe(array('verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'collector.example.net'));
});

test('an address literal keeps the 1.2.31 URL and adds no Host header', function () {
	[$result, $log, $url] = remote_collector_call('10.20.30.40');

	expect($url)->toBe('https://10.20.30.40/remote_agent.php?action=ping')
		->and($GLOBALS['rdc_context']['http'])->not->toHaveKey('header')
		->and($GLOBALS['rdc_context'])->not->toHaveKey('ssl');
});

test('a name that resolves to the metadata address is refused before any request', function () {
	[$result, $log, $url] = remote_collector_call('collector.example.net', array('collector.example.net' => '169.254.169.254'));

	expect($result)->toBe('')
		->and($url)->toBeNull()
		->and($GLOBALS['rdc_context'])->toBeNull();
});
