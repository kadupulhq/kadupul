<?php
declare(strict_types=1);
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

function xport_window_native(array $command, string $directory): void {
	$error = tmpfile();
	if ($error === false) throw new RuntimeException('Cannot capture native diagnostics');
	try {
		$process = proc_open($command, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => $error), $pipes, $directory,
			array('PATH' => getenv('PATH'), 'LANG' => 'C', 'LC_ALL' => 'C', 'TZ' => 'UTC', 'RRDCACHED_ADDRESS' => '', 'XDG_CACHE_HOME' => $directory . '/cache'));
		if (!is_resource($process)) throw new RuntimeException('Cannot start native RRDtool');
		$status = proc_close($process);
		rewind($error);
		$stderr = stream_get_contents($error);
		if ($status !== 0 || $stderr !== '') throw new RuntimeException('Native RRDtool failed: ' . $stderr);
	} finally {
		fclose($error);
	}
}

$xport_window_cases = array();
foreach (array(0, 1, 20, 11000) as $count) {
	foreach (array(0, 120) as $offset) $xport_window_cases['N=' . $count . ' offset=' . $offset] = array($count, $offset, false);
}
$xport_window_cases['relative window'] = array(20, 120, true);

test('native production export postprocessing retains complete selected rows', function (int $count, int $offset, bool $relative) {
	$binary = getenv('RRDTOOL_TEST_BINARY') ?: cacti_test_rrdtool_binary();
	if (!$binary || !is_executable($binary)) $this->markTestSkipped('Native RRDtool is required');
	$directory = sys_get_temp_dir() . '/rrd-xport-window-' . bin2hex(random_bytes(8));
	if (!mkdir($directory, 0700) || !mkdir($directory . '/cache', 0700)) throw new RuntimeException('Cannot create private RRD directory');
	$path = $directory . '/window.rrd';
	$sample_start = $relative ? intdiv(time(), 300) * 300 - ($count + 2) * 300 : 1699999800;
	$sample_end = $sample_start + $count * 300;
	$start = $sample_start + $offset;
	$end = $sample_end + $offset + ($count === 0 ? 60 : 0);
	try {
		xport_window_native(array($binary, 'create', $path, '--start', (string) ($sample_start - 600), '--step', '300', 'DS:value:GAUGE:600:U:U', 'RRA:AVERAGE:0.5:1:' . ($count + 10)), $directory);
		$updates = array(($sample_start - 300) . ':-1', $sample_start . ':0');
		for ($index = 1; $index <= $count; $index++) $updates[] = ($sample_start + $index * 300) . ':' . $index;
		$updates[] = ($sample_end + 300) . ':100000';
		xport_window_native(array_merge(array($binary, 'update', $path), $updates), $directory);
		$relative_now = time();
		$result = cacti_test_rrd_harness_run(array('action' => 'xport_window', 'path' => $path, 'start' => $relative ? $start - $relative_now : $start, 'end' => $relative ? $end - $relative_now : $end,
			'config' => array('path_rrdtool' => $binary, 'storage_location' => '', 'rrdcached_enable' => '')));
		expect($result)->not->toHaveKey('error');
		expect($result['warnings'])->toBe(array());
		expect($result['options'])->toContain("--start='" . $result['window'][0] . "'")
			->and($result['options'])->toContain("--end='" . $result['window'][1] . "'");
		$xport = $result['xport'];
		expect((int) $xport['meta']['rows'])->toBe($count)
			->and((int) $xport['meta']['step'])->toBe(300)
			->and((int) $xport['meta']['start'])->toBe($count === 0 ? $start : $sample_start + 300)
			->and((int) $xport['meta']['end'])->toBe($count === 0 ? $end : $sample_end);
		expect(array_map('floatval', array_column($xport['data'], 'col1')))->toBe($count === 0 ? array() : array_map('floatval', range(1, $count)));
	} finally {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		rmdir($directory);
	}
})->with($xport_window_cases);
