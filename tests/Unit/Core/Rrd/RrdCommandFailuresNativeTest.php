<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Run the production transport with real pipes/sockets and isolated files. */
function rrd_command_failure_run($test, string $scenario, int $flag = 1) : array {
	$root = dirname(__DIR__, 4);
	$directory = sys_get_temp_dir() . '/rrd-command-failures-' . bin2hex(random_bytes(8));
	if (!mkdir($directory, 0700)) { throw new RuntimeException('Cannot create fixture directory'); }
	$coverage = $test->getTestResultObject()->getCodeCoverage();
	$command = [PHP_BINARY, '-d', 'display_errors=stderr', $root . '/tests/fixtures/rrd-command-failures.php', $scenario, $directory, (string) $flag];
	if ($coverage !== null) { $command[] = 'coverage'; }
	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($process)) { throw new RuntimeException('Cannot start native fixture'); }
	try {
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		$test->assertSame(0, $status, $stderr . $stdout);
		$test->assertSame('', $stderr);
		if ($coverage !== null) {
			$reports = glob($directory . '/*.coverage');
			$test->assertCount(1, $reports);
			$coverage->merge(unserialize(file_get_contents($reports[0])));
		}
		return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
	} finally {
		if (is_file($directory . '/pid')) {
			$pid = (int) file_get_contents($directory . '/pid');
			// A timed-out old implementation can leave only this owned child.
			$probe = proc_open(['ps', '-p', (string) $pid, '-o', 'command='], [1 => ['pipe', 'w']], $probePipes);
			if (is_resource($probe)) {
				$commandLine = stream_get_contents($probePipes[1]);
				fclose($probePipes[1]); proc_close($probe);
				if (strpos($commandLine, $directory . '/rrd') !== false) { posix_kill($pid, SIGKILL); }
			}
		}
		foreach (glob($directory . '/*') as $file) { unlink($file); }
		rmdir($directory);
	}
}

test('one-shot exchange preserves the observed exit status on PHP 8.1', function ($status) {
	require_once dirname(__DIR__, 4) . '/lib/rrd_process.php';
	$process = proc_open([PHP_BINARY, '-r', 'stream_get_contents(STDIN); print "payload"; exit(' . $status . ');'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
	expect(is_resource($process))->toBeTrue();
	$result = rrdtool_oneshot_exchange($process, $pipes, "input\n", (float) hrtime(true) + 2000000000, true);
	expect($result)->toBe(['completed' => true, 'status' => $status, 'output' => 'payload'])
		->and(is_resource($process))->toBeFalse()->and(is_resource($pipes[0]))->toBeFalse()->and(is_resource($pipes[1]))->toBeFalse();
})->with([0, 7]);

test('one-shot exchanges send all input while draining binary output', function ($flag) {
	$result = rrd_command_failure_run($this, 'oneshot-large', $flag)['result'];
	$hash = hash('sha256', 'first ' . str_repeat('remaining-input ', 65536) . "\r\nquit\r\n");
	$payload = str_repeat("binary\0payload\n", 32768) . $hash . "\n";
	expect($result['hash'])->toBe($hash)->and($result['alive'])->toBeFalse();
	if ($flag === 0 || $flag === 2) {
		expect($result['returned'])->toBeNull()->and(base64_decode($result['printed']))->toBe($flag === 2 ? $payload : '');
	} else {
		expect(base64_decode($result['returned']))->toBe($payload)->and($result['printed'])->toBe('');
	}
})->with([0, 1, 2, 3, 5]);

test('one-shot executable paths with spaces and shell metacharacters remain one argument', function () {
	$result = rrd_command_failure_run($this, 'oneshot-hostile-path')['result'];
	$hash = hash('sha256', 'first ' . str_repeat('remaining-input ', 65536) . "\r\nquit\r\n");
	expect($result['hash'])->toBe($hash)->and($result['alive'])->toBeFalse()
		->and(base64_decode($result['returned']))->toBe(str_repeat("binary\0payload\n", 32768) . $hash . "\n");
});

test('one-shot timeouts reject partial results and reap children ignoring SIGTERM', function ($scenario, $flag) {
	$result = rrd_command_failure_run($this, $scenario, $flag);
	expect($result['result']['returned'])->toBeFalse()
		->and($result['result']['printed'])->toBe('')
		->and($result['result']['alive'])->toBeFalse()
		->and($result['elapsed'])->toBeLessThan(2.5);
})->with([['oneshot-silent', 1], ['oneshot-not-reading', 1], ['oneshot-partial', 3], ['oneshot-closed-output', 0]]);

test('one-shot diagnostic mode retains merged errors on nonzero exit', function () {
	$result = rrd_command_failure_run($this, 'oneshot-diagnostic', 5)['result'];
	expect(base64_decode($result['returned']))->toBe("ERROR: declined\ndetail\n")->and($result['alive'])->toBeFalse();
});

test('proxy errors and missing acknowledgements do not become empty data', function ($scenario, $flag) {
	$result = rrd_command_failure_run($this, $scenario, $flag);
	expect($result['result'])->toBeFalse()->and($result['elapsed'])->toBeLessThan(0.8);
})->with(['proxy-error', 'proxy-error-ok', 'proxy-missing-ok'])->with([1, 3]);

test('acknowledged proxy results remain reusable data', function ($scenario) {
	$result = rrd_command_failure_run($this, $scenario);
	expect($result['result'])->toBe('payload');
})->with(['proxy-success', 'proxy-bare-ok']);

test('proxy deadlines discard silent and dripping connections and reject stale reuse', function ($scenario, $flag) {
	$result = rrd_command_failure_run($this, $scenario, $flag)['result'];
	expect($result['returned'])->toBeNull()->and($result['second'])->toBeNull()
		->and($result['closed'])->toBeTrue()->and($result['elapsed'])->toBeLessThan(0.7);
})->with(['proxy-silent', 'proxy-drip'])->with([0, 1, 2, 3, 4, 5]);

test('a non-reading proxy cannot block a large write indefinitely', function () {
	$result = rrd_command_failure_run($this, 'proxy-write-stall');
	expect($result['result'])->toBeFalse()->and($result['elapsed'])->toBeLessThan(0.8);
});

test('a proxy read obeys the command budget before the peer closes', function () {
	$result = rrd_command_failure_run($this, 'proxy-budget')['result'];
	expect($result['elapsed'])->toBeLessThan(0.7)->and($result['closed'])->toBeTrue()->and($result['returned'])->toBeNull();
});

test('failed primary and backup proxy connections return normally', function () {
	$result = rrd_command_failure_run($this, 'connect-failure');
	expect($result['result']['attempts'])->toBe(24);
});

test('failed local fetches never manufacture data source headers', function ($unknown) {
	$result = rrd_command_failure_run($this, 'fetch-error', $unknown);
	expect($result['result'])->toBe([]);
})->with([0, 1]);

test('local fetches preserve measured zero and a source named ERROR', function ($scenario, $name, $value) {
	$result = rrd_command_failure_run($this, $scenario)['result'];
	expect($result['data_source_names'])->toBe([$name])->and($result['values'][0][1700000300])->toBe($value);
})->with([['fetch-zero', 'value', '0'], ['fetch-value', 'ERROR', '2.5']]);

test('failed local fetches stay missing through statistics and bandwidth consumers', function () {
	$result = rrd_command_failure_run($this, 'fetch-stats')['result'];
	expect($result)->toBe(['fetch' => [], 'stats' => '[]', 'bandwidth' => []]);
});
