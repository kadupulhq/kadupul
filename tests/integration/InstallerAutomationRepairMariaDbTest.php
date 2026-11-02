<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('native automation repair preserves selected-template boundaries and database failures', function ($scenario) {
	$probe = dirname(__DIR__) . '/fixtures/installer-automation-repair-native.php';
	$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', $probe, $scenario), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($process)) {
		throw new RuntimeException('The native automation probe could not start.');
	}
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	expect($error)->toBe('');
	expect($status)->toBe(0, $output);
	$report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	expect($report['passed'])->toBeTrue();
	expect($report['checks'])->toBeGreaterThan(0);
})->with(array('preserve-and-retry', 'empty', 'rule-read-error', 'query-read-error', 'graph-read-error', 'write-error', 'handled-prior-error'));
