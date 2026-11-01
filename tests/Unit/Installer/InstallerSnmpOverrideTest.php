<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('native installer distinguishes explicit SNMP override values from omission', function ($value, string $stored, bool $field): void {
	$process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', dirname(__DIR__, 2) . '/fixtures/installer-snmp-override.php', json_encode($value, JSON_THROW_ON_ERROR)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start native installer fixture.');
	}
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0, $error)
		->and($error)->toBe('')
		->and(json_decode($output, true, 8, JSON_THROW_ON_ERROR))->toBe(array('stored' => $stored, 'field' => $field));
})->with(array(
	'integer zero' => array(0, '0', false),
	'boolean false' => array(false, '', false),
	'empty string' => array('', '', false),
	'string zero' => array('0', '0', false),
	'false string' => array('false', 'false', false),
	'enabled' => array(1, '1', true),
	'omitted' => array(null, '1', true),
));
