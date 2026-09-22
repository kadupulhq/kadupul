<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/** Run the production seed boundary in isolation, without an RRD server or database. */
function maintenance_seed_child($file, $value) {
	$root = dirname(__DIR__, 4);
	$source = file_get_contents($root . '/' . $file);
	$stub = 'namespace MaintenanceSeedHarness; function random_int($min, $max) {'
		. 'if ($min !== 0 || $max !== mt_getrandmax()) throw new \\LogicException("Wrong range");'
		. ($value === 'failure' ? 'throw new \\Exception("entropy failure");' : 'return ' . $value . ';') . '}';
	if ($file === 'cli/splice_rrd.php') {
		$start = strpos($source, '/* determine the temporary file name */');
		$end = strpos($source, 'if (substr_count(PHP_OS', $start);
		expect($start)->not->toBeFalse();
		expect($end)->not->toBeFalse();
		$code = $stub . substr($source, $start, $end - $start)
			. 'echo json_encode(["seed" => $seed, "next" => $seed + 1]);';
	} else {
		$method = test_php_function_source($source, 'remove_spikes_locked');
		// The token-based extractor starts at T_FUNCTION, excluding the original private modifier.
		expect(strpos($method, 'function remove_spikes_locked('))->toBe(0);
		$end = strpos($method, "\n\t\tif (\$config['cacti_server_os']");
		expect($end)->not->toBeFalse();
		// Execute the actual method through the seed boundary; only subsequent RRD work is omitted.
		$code = $stub . 'function __($message) { return $message; } class Probe {'
			. 'public $seed = null; public $strout = ""; public $error = "";'
			. 'function initialize_spikekill() {} function is_error_set() { return false; }'
			. 'function set_error($error) { $this->error = $error; }'
			. 'public ' . substr($method, 0, $end) . 'return true; }}'
			. '$probe = new Probe(); $result = $probe->remove_spikes_locked();'
			. 'echo json_encode(["result" => $result, "seed" => $probe->seed, "error" => $probe->error]);';
	}
	$process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	expect($process)->toBeResource();
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	expect($error)->toBe('');
	return [$status, $output];
}

test('maintenance seeds retain the LTS integer range and stop on entropy failure', function ($file, $value) {
	[$status, $output] = maintenance_seed_child($file, $value);
	if ($value === 'failure' && $file === 'cli/splice_rrd.php') {
		expect($status)->toBe(1);
		expect($output)->toContain('FATAL: Secure randomness is unavailable');
		return;
	}
	expect($status)->toBe(0);
	$data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	if ($value === 'failure') {
		expect($data['result'])->toBeFalse();
		expect($data['seed'])->toBeNull();
		expect($data['error'])->toContain('FATAL: Secure randomness is unavailable');
	} else {
		expect($data['seed'])->toBe($value);
		if ($file === 'cli/splice_rrd.php') expect($data['next'])->toBe($value + 1);
		else expect($data['result'])->toBeTrue();
	}
})->with(['cli/splice_rrd.php', 'lib/spikekill.php'])->with([0, 12345, mt_getrandmax(), 'failure']);
