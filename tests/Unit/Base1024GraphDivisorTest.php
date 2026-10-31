<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/** @param list<string> $command @return array{out: string, err: string, status: int} */
function binary_graph_divisor_run(array $command): array {
	$error = tmpfile();
	if ($error === false) {
		throw new RuntimeException('Cannot capture fixture diagnostics');
	}
	$pipes = array();
	try {
		$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => $error), $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start graph fixture');
		}
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$status = proc_close($process);
		rewind($error);
		return array('out' => $output, 'err' => stream_get_contents($error), 'status' => $status);
	} finally {
		foreach ($pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		fclose($error);
	}
}

$binaryGraphCases = array();
foreach (array('current', 'total', 'all_max_current', 'aggregate', 'max', 'total_peak', 'all_max_peak', 'aggregate_sum_peak', 'aggregate_current', 'aggregate_current_peak') as $type) {
	foreach (array('binary Mi' => array(1024, 6, 1048576, 1), 'decimal M' => array(1000, 6, 1000000, 1)) as $label => $input) {
		$binaryGraphCases["$type $label"] = array(array('mode' => 'percentile', 'type' => $type,
			'base' => $input[0], 'power' => $input[1], 'value' => $input[2], 'max' => $type === 'max' || str_contains($type, 'peak')), $input[3]);
	}
}
foreach (array('Ki' => array(3, 1024, 1), 'Gi' => array(9, 1073741824, 1), 'unscaled' => array(0, 1048576, 1048576), 'zero' => array(6, 0, 0)) as $label => $input) {
	$binaryGraphCases[$label] = array(array('mode' => 'percentile', 'type' => 'current', 'base' => 1024,
		'power' => $input[0], 'value' => $input[1]), $input[2]);
}
$binaryGraphCases['bits conversion'] = array(array('mode' => 'percentile', 'type' => 'current', 'base' => 1024,
	'power' => 6, 'value' => 131072, 'unit' => 'bits'), 1);
foreach (array('current', 'total', 'atomic') as $type) {
	foreach (array('binary explicit' => array(1024, 6, 262144, '1.00'),
		'binary auto' => array(1024, 'auto', 262144, '1.00 MiB'),
		'decimal explicit' => array(1000, 6, 250000, '1.00'),
		'decimal auto' => array(1000, 'auto', 250000, '1.00 MB')) as $label => $input) {
		$binaryGraphCases["summation $type $label"] = array(array('mode' => 'sum', 'type' => $type,
			'base' => $input[0], 'power' => $input[1], 'value' => $input[2]), $input[3]);
	}
}
$binaryGraphCases['summation sample duration'] = array(array('mode' => 'sum', 'type' => 'current', 'base' => 1024,
	'power' => 6, 'value' => 262144, 'step' => 60), '60.00');
foreach (array(1 => array(1024, 101.59), 2 => array(1024, 10.08), 4 => array(1048576, 101.59),
	5 => array(1048576, 10.08), 7 => array(1073741824, 101.59), 8 => array(1073741824, 10.08)) as $power => $input) {
	$binaryGraphCases["fractional binary group $power"] = array(array('mode' => 'percentile', 'type' => 'current',
		'base' => 1024, 'power' => $power, 'value' => $input[0]), $input[1]);
	$binaryGraphCases["summation fractional binary group $power"] = array(array('mode' => 'sum', 'type' => 'current',
		'base' => 1024, 'power' => $power, 'value' => $input[0] / 4), number_format($input[1], 2, '.', ''));
}
foreach (array('unscaled' => array(0, 256, '1024.00'), 'Ki' => array(3, 256, '1.00'),
	'Gi' => array(9, 268435456, '1.00')) as $label => $input) {
	$binaryGraphCases["summation $label"] = array(array('mode' => 'sum', 'type' => 'current', 'base' => 1024,
		'power' => $input[0], 'value' => $input[1]), $input[2]);
}

test('graph legend variables preserve binary and decimal units through production statistics', function (array $case, mixed $expected): void {
	$fixture = dirname(__DIR__) . '/fixtures/binary-graph-divisor.php';
	$result = binary_graph_divisor_run(array(PHP_BINARY, $fixture, json_encode($case, JSON_THROW_ON_ERROR)));
	expect($result['status'])->toBe(0, $result['err'])->and($result['err'])->toBe('');
	$output = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
	$resolution = $case['mode'] === 'sum' ? ($case['type'] === 'atomic' ? 1 : ($case['step'] ?? 1)) : 0;
	$fetches = array(array(1, 0, 240, $resolution, 'AVERAGE'));
	if (!empty($case['max'])) {
		array_unshift($fetches, array(1, 0, 240, 0, 'MAX'));
	}
	expect($output['fetches'])->toBe($fetches);
	if (is_string($expected)) {
		expect(trim($output['result']))->toBe($expected);
	} else {
		expect((float) $output['result'])->toBe((float) $expected);
	}
})->with($binaryGraphCases);
