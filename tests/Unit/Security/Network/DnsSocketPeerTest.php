<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace DnsSocketPeerTest;

require_once dirname(__DIR__, 4) . '/lib/dns.php';

test('connected UDP accepts only the configured resolver peer', function ($foreign_packet) {
	if (!function_exists('pcntl_fork')) $this->markTestSkipped('Requires pcntl for a local UDP responder');
	$server = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
	expect($server)->toBeResource();
	$address = stream_socket_get_name($server, false);
	$pid = pcntl_fork();
	if ($pid === -1) {
		fclose($server);
		throw new \RuntimeException('Unable to fork local UDP responder');
	}
	if ($pid === 0) {
		stream_set_timeout($server, 3);
		$request = stream_socket_recvfrom($server, 512, 0, $peer);
		if (!is_string($request) || strlen($request) < 12) exit(1);
		$prefix = substr($request, 0, 2) . pack('nnnnn', 0x8180, 1, 1, 0, 0) . substr($request, 12);
		$record = "\xc0\x0c" . pack('nnNn', 12, 1, 60, 14);
		if ($foreign_packet) {
			$foreign = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
			if ($foreign === false) exit(2);
			stream_socket_sendto($foreign, $prefix . $record . "\4evil\7example\0", 0, $peer);
			fclose($foreign);
			usleep(20000);
		}
		$reply = $prefix . $record . "\4host\7example\0";
		$sent = stream_socket_sendto($server, $reply, 0, $peer);
		fclose($server);
		exit($sent === strlen($reply) ? 0 : 3);
	}
	try {
		expect(\cacti_dns_reverse_lookup('8.8.8.8', $address, 1500))->toBe('HOST.EXAMPLE');
	} finally {
		fclose($server);
		pcntl_waitpid($pid, $status);
	}
	expect(pcntl_wifexited($status))->toBeTrue();
	expect(pcntl_wexitstatus($status))->toBe(0);
})->with([false, true]);
