<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

function installer_automation_mode(array $scenario): array {
	$root = dirname(__DIR__, 3);
	$stderr = tempnam(sys_get_temp_dir(), 'installer-mode-');
	if ($stderr === false) {
		throw new RuntimeException('Cannot create installer fixture stderr');
	}
	$process = null;
	try {
		$process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $root . '/tests/fixtures/installer-automation-mode.php', $root,
			json_encode($scenario, JSON_THROW_ON_ERROR)], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
			1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start installer fixture');
		}
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$status = proc_close($process);
		$process = null;
		$errors = file_get_contents($stderr);
		expect($status)->toBe(0, $errors . $output)->and($errors)->toBe('');
		return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	} finally {
		if (is_resource($process)) {
			proc_terminate($process, 9);
			proc_close($process);
		}
		unlink($stderr);
	}
}

test('installer automation values survive settings reload and the network update', function (mixed $value, bool $enabled): void {
	$result = installer_automation_mode(['stored_mode' => 'on', 'network_enabled' => 'on', 'parameters' => ['AutomationMode' => $value]]);
	expect($result['after_payload'])->toBe($enabled)
		->and($result['after_reload'])->toBe($enabled)
		->and($result['stored_after_payload'])->toBe((string) $value)
		->and($result['network']['enabled'])->toBe($enabled ? 'on' : '')
		->and($result['network']['subnet_range'])->toBe('192.0.2.0/24')
		->and($result['errors'])->toBe([]);
})->with(['false' => [false, false], 'zero' => [0, false], 'empty' => ['', false], 'string zero' => ['0', false],
	'string false' => ['false', false], 'true' => [true, true], 'one' => [1, true], 'string one' => ['1', true],
	'string true' => ['true', true], 'on' => ['on', true]]);

test('persisted disabled automation stays disabled even when the network was enabled', function (string $stored, bool $web): void {
	$result = installer_automation_mode(['stored_mode' => $stored, 'network_enabled' => 'on', 'web' => $web, 'parameters' => []]);
	expect($result['after_payload'])->toBeFalse()
		->and($result['after_reload'])->toBeFalse()
		->and($result['network']['enabled'])->toBe('')
		->and($result['errors'])->toBe([]);
})->with(['', '0', 'false'])->with([false, true]);

test('omitted and null automation parameters retain the selected mode', function (array $parameters, bool $enabled): void {
	$result = installer_automation_mode(['stored_mode' => $enabled ? 'on' : '0', 'network_enabled' => 'on', 'parameters' => $parameters]);
	expect($result['after_payload'])->toBe($enabled)
		->and($result['after_reload'])->toBe($enabled)
		->and($result['network']['enabled'])->toBe($enabled ? 'on' : '')
		->and($result['errors'])->toBe([]);
})->with(['omitted' => [[]], 'null' => [['AutomationMode' => null]]])->with([false, true]);

test('missing automation settings retain the existing network default', function (bool $enabled): void {
	$result = installer_automation_mode(['network_enabled' => $enabled ? 'on' : '', 'parameters' => []]);
	expect($result['after_payload'])->toBe($enabled)
		->and($result['after_reload'])->toBe($enabled)
		->and($result['network']['enabled'])->toBe($enabled ? 'on' : '');
})->with([false, true]);
