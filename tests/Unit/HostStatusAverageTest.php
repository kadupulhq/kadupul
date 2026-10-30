<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('host status persists the current sample when successful poll counters cancel', function (int|string $total, int|string $failed, string $method, float $expected): void {
	$writes = host_status_average_probe($total, $failed, 9.5, 4.25, 'HOST_UP', $method);
	expect($writes[0]['params'][8])->toBe($expected);
	expect($writes[0]['params'][7])->toBe($expected);
	expect($writes[0]['params'][9])->toBe((int) $total + 1);
	expect($writes[0]['params'][10])->toBe($failed);
})->with(array(
	'ping' => array(4, 5, 'AVAIL_PING', 4.25),
	'string counters from database' => array('4', '5', 'AVAIL_PING', 4.25),
	'zero prior polls' => array(0, 1, 'AVAIL_PING', 4.25),
	'snmp' => array(4, 5, 'AVAIL_SNMP', 4.25),
	'combined' => array(4, 5, 'AVAIL_SNMP_AND_PING', 4.25),
	'no availability probe' => array(4, 5, 'AVAIL_NONE', 0.0),
));

test('host status preserves the existing weighted average for nonzero divisors', function (int $total, int $failed, float $average, float $sample): void {
	$writes = host_status_average_probe($total, $failed, $average, $sample);
	$expected = (($total - $failed) * $average + $sample) / ($total + 1 - $failed);
	expect((float) $writes[0]['params'][8])->toBe((float) $expected);
})->with(array(
	'first poll' => array(0, 0, 0.0, 4.25),
	'ordinary weighted mean' => array(10, 2, 9.5, 4.25),
	'first success after failures' => array(10, 10, 9.5, 4.25),
	'zero sample' => array(10, 2, 9.5, 0.0),
	'fractional times' => array(100, 2, 0.125, 0.0625),
	'negative nonzero divisor retains arithmetic' => array(4, 6, 9.5, 4.25),
));

test('a failed poll retains the previous average and updates the failure counters', function (): void {
	$writes = host_status_average_probe(4, 4, 9.5, 4.25, 'HOST_DOWN');
	expect($writes[0]['params'][8])->toBe(9.5);
	expect($writes[0]['params'][9])->toBe(5);
	expect($writes[0]['params'][10])->toBe(5);
});

function host_status_average_probe(int|string $total, int|string $failed, float $average, float $sample, string $status = 'HOST_UP', string $method = 'AVAIL_PING'): array {
	$process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/host-status-average.php'),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($process)) throw new RuntimeException('Cannot start host status fixture');
	fwrite($pipes[0], json_encode(array('host' => array('total_polls' => $total, 'failed_polls' => $failed, 'avg_time' => $average),
		'sample' => $sample, 'status' => $status, 'method' => $method), JSON_THROW_ON_ERROR));
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	if (proc_close($process) !== 0 || $error !== '') throw new RuntimeException($error . $output);
	$writes = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	expect($writes)->toHaveCount(1);
	expect($writes[0]['sql'])->toContain('WHERE id = ?');
	expect($writes[0]['params'][12])->toBe(17);
	return $writes;
}
