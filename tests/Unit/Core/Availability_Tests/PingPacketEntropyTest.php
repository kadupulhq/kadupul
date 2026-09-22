<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace PingPacketEntropyTest;

function random_int($min, $max) {
	if ($min !== 0 || $max !== 255) throw new \LogicException('Unexpected packet byte range');
	$value = array_shift($GLOBALS['icmp_entropy_bytes']);
	if ($value === null) throw new \RuntimeException('Entropy unavailable');
	return $value;
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/ping.php');
eval('namespace ' . __NAMESPACE__ . ';' . preg_replace('/^<\?php\s*/', '', $source));

test('ICMP packet bytes retain the LTS layout and valid checksum', function ($low, $high) {
	$GLOBALS['icmp_entropy_bytes'] = [$low, $high];
	$ping = new Net_Ping();
	expect($ping->build_icmp_packet())->toBeNull();
	$pair = chr($high) . chr($low);
	expect(substr($ping->request, 0, 2))->toBe("\x08\x00");
	expect(substr($ping->request, 4, 4))->toBe($pair . $pair);
	expect(substr($ping->request, 8))->toBe('cacti-monitoring-system');
	expect($ping->sqn)->toBe($pair);
	expect($ping->request_len)->toBe(strlen($ping->request));
	// Independent one's-complement checksum validation across the complete packet.
	$padded = $ping->request . (strlen($ping->request) % 2 ? "\x00" : '');
	$sum = array_sum(unpack('n*', $padded));
	while ($sum > 65535) $sum = ($sum & 65535) + ($sum >> 16);
	expect($sum)->toBe(65535);
	expect($GLOBALS['icmp_entropy_bytes'])->toBe([]);
})->with([[0, 0], [255, 255], [0, 255], [18, 52]]);

test('entropy failure leaves neither partial nor stale ICMP packets', function ($bytes, $existing) {
	$ping = new Net_Ping();
	if ($existing) {
		$GLOBALS['icmp_entropy_bytes'] = [18, 52];
		$ping->build_icmp_packet();
		expect($ping->request_len)->toBeGreaterThan(0);
	}
	$GLOBALS['icmp_entropy_bytes'] = $bytes;
	expect(fn () => $ping->build_icmp_packet())->toThrow(\RuntimeException::class, 'Entropy unavailable');
	expect($ping->request)->toBe('');
	expect($ping->request_len)->toBe(0);
	expect($ping->sqn)->toBe('');
})->with([[[]], [[18]]])->with([false, true]);
