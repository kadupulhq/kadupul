<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace PingPublicApiTest;

require_once dirname(__DIR__, 4) . '/include/global_constants.php';

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/ping.php');
eval('namespace PingPublicApiTest; ' . preg_replace('/^<\?php\s*/', '', $source));
$methods = array('__construct', '__destruct', 'close_socket', 'start_time', 'get_time', 'build_udp_packet',
	'ping_error_handler', 'set_ping_error_handler', 'restore_cacti_error_handler', 'build_icmp_packet',
	'get_checksum', 'ping_icmp', 'seteuid', 'setuid', 'ping_snmp', 'get_snmp_result', 'ping_udp', 'ping_tcp',
	'ping', 'is_ipaddress', 'strip_ip_address');

test('ping API visibility stays public and is explicitly declared', function ($method) use ($source) {
	$reflection = new \ReflectionMethod(Net_Ping::class, $method);
	expect($reflection->isPublic())->toBeTrue();
	expect($reflection->isStatic())->toBeFalse();
	expect(preg_match('/public function ' . preg_quote($method, '/') . '\\(/', $source))->toBe(1);
})->with($methods);

test('public ping construction and address validation retain their behavior without network I/O', function ($address, $valid) {
	$ping = new Net_Ping();
	expect($ping->port)->toBe(33439);
	expect($ping->is_ipaddress($address))->toBe($valid);
})->with(array(array('127.0.0.1', true), array('::1', true), array('fe80::1%eth0', true), array('not-an-address', false)));

test('public checksum helper retains its wire-format result', function () {
	$ping = new Net_Ping();
	expect(bin2hex($ping->get_checksum("\x00\x01")))->toBe('fffe');
});
