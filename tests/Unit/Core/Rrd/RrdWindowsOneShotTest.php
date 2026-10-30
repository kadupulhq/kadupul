<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('Windows one-shot transport preserves production output and enforces deadlines', function () {
	$probe = dirname(__DIR__, 3) . '/integration/rrd_windows_oneshot.php';
	$coverage = $this->getTestResultObject()->getCodeCoverage();
	$directory = sys_get_temp_dir() . '/rrd-windows-coverage-' . bin2hex(random_bytes(12));
	$command = [PHP_BINARY, $probe];
	if ($coverage !== null) {
		if (!mkdir($directory, 0700)) {
			throw new RuntimeException('Cannot create owned coverage directory');
		}
		$command = [PHP_BINARY, $probe, '--coverage', $directory];
	}
	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
	expect(is_resource($process))->toBeTrue();
	$output = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$status = proc_close($process);
	expect($status)->toBe(0);
	expect($output)->toContain('Verified 21 Windows one-shot checks');
	if ($coverage !== null) {
		try {
			$reports = glob($directory . '/*.coverage');
			expect($reports)->toHaveCount(1);
			$coverage->merge(unserialize(file_get_contents($reports[0])));
		} finally {
			foreach (glob($directory . '/*.coverage') as $report) {
				unlink($report);
			}
			rmdir($directory);
		}
	}
});
