<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * @param list<string> $arguments
 * @return array<string,mixed>
 */
function native_session_fixture(string $fixture, array $arguments = array()): array {
	$allowed = array('session-data-native.php', 'session-store-native.php', 'session-store-fault-native.php',
		'session-store-budget-native.php', 'session-store-metadata-lock-native.php', 'session-engine-native.php', 'session-probe-outcome-native.php');
	if (!in_array($fixture, $allowed, true) || count($arguments) > 2) throw new InvalidArgumentException('Invalid native session fixture.');
	foreach ($arguments as $argument) {
		if (!is_string($argument) || strlen($argument) > 4096 || str_contains($argument, "\0")) throw new InvalidArgumentException('Invalid native fixture argument.');
	}
	$file = dirname(__DIR__) . '/fixtures/' . $fixture;
	$command = array_merge(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'zend.exception_ignore_args=1', $file), $arguments);
	$pipes = array();
	$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($process)) throw new RuntimeException('Unable to start native session fixture.');
	$open = array(); $stdout = $stderr = ''; $observedExit = null; $started = hrtime(true);
	try {
		fclose($pipes[0]);
		foreach (array(1, 2) as $index) {
			if (!stream_set_blocking($pipes[$index], false)) throw new RuntimeException('Unable to configure native fixture pipe.');
			$open[$index] = $pipes[$index];
		}
		while ($open !== array()) {
			$read = array_values($open); $write = $except = null;
			if (stream_select($read, $write, $except, 0, 20000) === false) throw new RuntimeException('Unable to read native session fixture.');
			foreach ($read as $stream) {
				$index = array_search($stream, $open, true); $chunk = fread($stream, 65536);
				if ($chunk === false) throw new RuntimeException('Native fixture output was incomplete.');
				if ($index === 1) $stdout .= $chunk; else $stderr .= $chunk;
				if (strlen($stdout) > 1048576 || strlen($stderr) > 1048576) throw new RuntimeException('Native fixture output exceeded its bound.');
				if (feof($stream)) { fclose($stream); unset($open[$index]); }
			}
			$status = proc_get_status($process);
			if (!$status['running'] && $status['exitcode'] >= 0) $observedExit = $status['exitcode'];
			if (hrtime(true) - $started > 60000000000) throw new RuntimeException('Native session fixture timed out.');
		}
	} catch (Throwable $failure) {
		proc_terminate($process); throw $failure;
	} finally {
		foreach ($open as $stream) fclose($stream);
		$closedExit = proc_close($process);
	}
	if (($observedExit ?? $closedExit) !== 0 || $stderr !== '') throw new RuntimeException('Native session fixture failed or emitted diagnostics.');
	$decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
	if (!is_array($decoded) || $decoded === array()) throw new RuntimeException('Native session fixture returned no result.');
	return $decoded;
}
