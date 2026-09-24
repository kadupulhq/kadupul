<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace ChangeDeviceDisableFlagTest;

$root = dirname(__DIR__, 4);

/**
 * Run the real --disable branch of cli/change_device.php against one value,
 * without the CLI bootstrap or a database. Taking the fragment from the file
 * means a regression in that branch fails here rather than in a copy of it.
 *
 * @param string $value The raw option value, as argv would supply it.
 *
 * @return array{status: int, out: string, disabled: string|null}
 */
function change_device_disable($value) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/change_device.php');
	expect($source)->not->toBeFalse();

	$start = strpos($source, "case '--disable':");
	$end   = strpos($source, "case '--external-id':", $start);
	expect($start)->not->toBeFalse();
	expect($end)->not->toBeFalse();

	$code = '$overrides = array(); $value = ' . var_export($value, true) . ';'
		. 'switch ("--disable") {' . substr($source, $start, $end - $start) . '}'
		. 'echo json_encode($overrides);';

	$pipes   = array();
	$process = proc_open(array(PHP_BINARY, '-r', $code),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect($process)->not->toBeFalse();

	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);

	expect($err)->toBe('');
	$overrides = json_decode($out, true);

	return array(
		'status'   => $status,
		'out'      => $out,
		'disabled' => is_array($overrides) && array_key_exists('disabled', $overrides) ? $overrides['disabled'] : null,
	);
}

/* 'on' in host.disabled means polling is off, so 1 must store 'on'. */
test('numeric --disable follows the documented contract', function () {
	$disable = change_device_disable('1');
	$enable  = change_device_disable('0');

	expect($disable['status'])->toBe(0)
		->and($disable['disabled'])->toBe('on')
		->and($enable['status'])->toBe(0)
		->and($enable['disabled'])->toBe('');
});

test('string --disable keeps its existing meaning', function () {
	expect(change_device_disable('on')['disabled'])->toBe('on')
		->and(change_device_disable('off')['disabled'])->toBe('');
});

test('a numeric --disable outside 0 and 1 is refused rather than read as enable', function () {
	foreach (array('2', '-1', '0.5', '99') as $value) {
		$result = change_device_disable($value);

		expect($result['status'])->toBe(1, $value);
		expect($result['out'])->toContain('ERROR: Invalid disable flag');
	}
});

test('change_device and add_device agree on what a numeric flag means', function () use ($root) {
	$add = file_get_contents($root . '/cli/add_device.php');

	expect($add)->not->toBeFalse()
		// add_device is the contract this branch was corrected against.
		->and($add)->toContain('ERROR: Invalid disable flag')
		->and(change_device_disable('1')['disabled'])->toBe('on')
		->and(change_device_disable('0')['disabled'])->toBe('');
});

test('the --disable help states which value disables', function () use ($root) {
	$source = file_get_contents($root . '/cli/change_device.php');
	$help   = preg_grep('/--disable\s/', explode("\n", $source));
	$line   = '';

	foreach ($help as $candidate) {
		if (strpos($candidate, 'print') !== false && strpos($candidate, 'usage:') === false) {
			$line = $candidate;
		}
	}

	expect($line)->toContain('1 to disable')
		->and($line)->toContain('0 to enable');
});
