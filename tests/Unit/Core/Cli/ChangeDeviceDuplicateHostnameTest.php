<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace ChangeDeviceDuplicateHostnameTest;

/**
 * Execute the real duplicate-hostname guard against a controlled query result.
 *
 * @param int|false $result The count returned by the database adapter.
 *
 * @return array{status: int, output: string}
 */
function run_duplicate_hostname_guard($result) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/change_device.php');
	expect($source)->not->toBeFalse();

	$start = strpos($source, "if (!\$proxy) {");
	$end   = strpos($source, "\n}\n", $start);
	expect($start)->not->toBeFalse();
	expect($end)->not->toBeFalse();

	$guard = substr($source, $start, $end - $start);
	$code  = 'function db_fetch_cell_prepared($sql, $params) { return $GLOBALS["query_result"]; }'
		. '$GLOBALS["query_result"] = ' . var_export($result, true) . ';'
		. '$host = array("hostname" => "192.0.2.1"); $device_id = 12; $proxy = false;'
		. $guard
		. "\n}"
		. 'echo "guard-passed";';

	$pipes = array();
	$process = proc_open(array(PHP_BINARY, '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect($process)->not->toBeFalse();
	$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return array('status' => proc_close($process), 'output' => $output);
}

test('zero duplicate rows are accepted while duplicates and query failures fail closed', function () {
	$unique = run_duplicate_hostname_guard(0);
	$duplicate = run_duplicate_hostname_guard(1);
	$queryFailure = run_duplicate_hostname_guard(false);

	expect($unique['status'])->toBe(0)
		->and($unique['output'])->toContain('guard-passed')
		->and($duplicate['status'])->toBe(1)
		->and($duplicate['output'])->toContain('already assigned to another device')
		->and($queryFailure['status'])->toBe(1)
		->and($queryFailure['output'])->toContain('Unable to verify');
});
