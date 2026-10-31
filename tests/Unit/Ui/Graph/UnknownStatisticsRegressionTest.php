<?php
declare(strict_types=1);
/*
 * Copyright (C) 2004-2026 The Cacti Group
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$unknownRoot = dirname(__DIR__, 4);
$unknownStats = file_get_contents($unknownRoot . '/lib/graph_variables.php');
$unknownFunctions = file_get_contents($unknownRoot . '/lib/functions.php');
$unknownRrd = file_get_contents($unknownRoot . '/lib/rrd.php');
if ($unknownStats === false || $unknownFunctions === false || $unknownRrd === false) throw new RuntimeException('Cannot read production statistics');
$unknownExtracted = test_php_function_source($unknownFunctions, 'cacti_sizeof');
foreach (array('cacti_percentile_index', 'cacti_stats_calc', 'nth_percentile_fetch_statistics') as $unknownFunction) {
	$unknownExtracted .= test_php_function_source($unknownStats, $unknownFunction);
}
eval('namespace UnknownStatisticsRegression; ' . $unknownExtracted . test_php_function_source($unknownRrd, 'rrdtool_parse_fetch_output'));

test('aggregation excludes unknown and non-finite samples while retaining zero', function (mixed $unknown): void {
	$ids = array(11 => array('value'), 12 => array('value'));
	$fetch = array(
		11 => array('data_source_names' => array('value'), 'values' => array(array(300 => $unknown, 600 => '0', 900 => '10'))),
		12 => array('data_source_names' => array('value'), 'values' => array(array(300 => $unknown, 600 => '0', 900 => '20'))),
	);
	$stats = UnknownStatisticsRegression\nth_percentile_fetch_statistics(95, $ids, $fetch, 'AVERAGE');
	foreach (array('value', 'nth_percentile_maximum', 'nth_percentile_sum') as $name) {
		expect($stats['stats_' . $name]['elements'])->toBe(2)
			->and((float) $stats['stats_' . $name]['sum'])->toBe(30.0)
			->and((float) $stats['stats_' . $name]['average'])->toBe(15.0)
			->and((float) $stats[$name])->toBe(30.0);
	}
})->with(array('U' => array('U'), 'NaN text' => array('NaN'), 'negative NaN text' => array('-nan'), 'NaN float' => array(NAN), 'infinity' => array(INF), 'negative infinity' => array(-INF)));

test('valid contributions survive another source being unknown at the same timestamp', function (): void {
	$ids = array(11 => array('value'), 12 => array('value'));
	$fetch = array(
		11 => array('data_source_names' => array('value'), 'values' => array(array(300 => 'U', 600 => '0', 900 => '10'))),
		12 => array('data_source_names' => array('value'), 'values' => array(array(300 => '5', 600 => '0', 900 => '20'))),
	);
	$stats = UnknownStatisticsRegression\nth_percentile_fetch_statistics(95, $ids, $fetch, 'AVERAGE');
	expect($stats['stats_value']['elements'])->toBe(3)
		->and((float) $stats['stats_value']['sum'])->toBe(35.0)
		->and((float) $stats['stats_value']['average'])->toBe(35 / 3)
		->and((float) $stats['value'])->toBe(30.0);
});

test('already filtered numeric input retains existing statistics', function (): void {
	$ids = array(11 => array('value'));
	$fetch = array(11 => array('data_source_names' => array('value'), 'values' => array(array(300 => '0', 600 => '10', 900 => '20'))));
	$stats = UnknownStatisticsRegression\nth_percentile_fetch_statistics(95, $ids, $fetch, 'MAX');
	expect($stats['stats_value']['elements'])->toBe(3)
		->and((float) $stats['stats_value']['sum'])->toBe(30.0)
		->and((float) $stats['stats_value']['average'])->toBe(10.0)
		->and((float) $stats['value'])->toBe(20.0);
});

function unknown_statistics_native(array $arguments, string $directory): string {
	$binary = getenv('RRDTOOL_TEST_BINARY') ?: '/usr/bin/rrdtool';
	$error = tmpfile();
	if ($error === false) throw new RuntimeException('Cannot capture native diagnostics');
	try {
		$process = proc_open(array_merge(array($binary), $arguments), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => $error), $pipes, $directory,
			array('PATH' => getenv('PATH'), 'LANG' => 'C', 'LC_ALL' => 'C', 'TZ' => 'UTC', 'RRDCACHED_ADDRESS' => '', 'XDG_CACHE_HOME' => $directory));
		if (!is_resource($process)) throw new RuntimeException('Cannot start native RRDtool');
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$status = proc_close($process);
		rewind($error);
		$stderr = stream_get_contents($error);
		if ($status !== 0 || $stderr !== '' || $output === false) throw new RuntimeException('Native RRDtool failed: ' . $stderr);
		return $output;
	} finally {
		fclose($error);
	}
}

test('actual native fetch parser hands unknown-preserving data safely to statistics', function (bool $allUnknown): void {
	$binary = getenv('RRDTOOL_TEST_BINARY') ?: '/usr/bin/rrdtool';
	if (!is_executable($binary)) $this->markTestSkipped('Native RRDtool is required');
	$directory = sys_get_temp_dir() . '/rrd-unknown-statistics-' . bin2hex(random_bytes(8));
	if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create private RRD directory');
	$path = $directory . '/samples.rrd';
	$start = 1699999800;
	try {
		unknown_statistics_native(array('create', $path, '--start', (string) ($start - 600), '--step', '300', 'DS:value:GAUGE:300:U:U', 'RRA:AVERAGE:0.5:1:100'), $directory);
		$values = $allUnknown ? array('U', 'U', 'U', 'U') : array('0', 'U', '10', '20');
		$updates = array($start . ':0');
		foreach ($values as $index => $value) $updates[] = ($start + ($index + 1) * 300) . ':' . $value;
		unknown_statistics_native(array_merge(array('update', $path), $updates), $directory);
		$output = unknown_statistics_native(array('fetch', $path, 'AVERAGE', '--start', (string) $start, '--end', (string) ($start + 1200), '--resolution', '300'), $directory);
		$preserved = UnknownStatisticsRegression\rrdtool_parse_fetch_output($output, $start + 1200, true);
		$filtered = UnknownStatisticsRegression\rrdtool_parse_fetch_output($output, $start + 1200, false);
		expect(in_array('U', $preserved['values'][0], true))->toBeTrue();
		$ids = array(11 => array('value'));
		$fetch = array(11 => $preserved);
		$stats = UnknownStatisticsRegression\nth_percentile_fetch_statistics(95, $ids, $fetch, 'AVERAGE');
		if ($allUnknown) {
			expect($filtered)->not->toHaveKey('values');
			expect($stats)->not->toHaveKey('stats_value');
			expect($stats['stats_nth_percentile_sum']['elements'])->toBe(0)
				->and($stats['nth_percentile_sum'])->toBe(0);
		} else {
			expect(array_map('floatval', array_values($filtered['values'][0])))->toBe(array(0.0, 10.0, 20.0));
			expect($stats['stats_value']['elements'])->toBe(3)
				->and((float) $stats['stats_value']['sum'])->toBe(30.0)
				->and((float) $stats['stats_value']['average'])->toBe(10.0);
		}
	} finally {
		if (is_file($path)) unlink($path);
		rmdir($directory);
	}
})->with(array('mixed measured and unknown' => array(false), 'unknown-only window' => array(true)));
