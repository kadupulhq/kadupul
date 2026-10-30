<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Windows anonymous process pipes cannot be multiplexed with stream_select.
 * Owned temporary files let the child consume input and produce binary output
 * independently while the parent watches its deadline. No shell is involved.
 *
 * @param list<string> $command
 * @return array{completed: bool, status: int, output: string}
 */
function rrdtool_windows_oneshot(array $command, string $input, int|float $deadline, bool $captureOutput) : array {
	$failed = ['completed' => false, 'status' => -1, 'output' => ''];
	$stdin = tmpfile();
	if ($stdin === false) {
		return $failed;
	}
	$stdout = false;
	$process = false;
	try {
		$stdout = tmpfile();
		if ($stdout === false) {
			return $failed;
		}
		$offset = 0;
		$length = strlen($input);
		while ($offset < $length) {
			if (hrtime(true) >= $deadline) {
				return $failed;
			}
			$written = fwrite($stdin, substr($input, $offset, 8192));
			if ($written === false || $written === 0) {
				return $failed;
			}
			$offset += $written;
		}
		if (!fflush($stdin) || !rewind($stdin) || hrtime(true) >= $deadline) {
			return $failed;
		}
		$process = proc_open($command, [0 => $stdin, 1 => $stdout], $pipes);
		if (!is_resource($process)) {
			return $failed;
		}
		while (hrtime(true) < $deadline) {
			$state = proc_get_status($process);
			if ($state === false) {
				return $failed;
			}
			if (!$state['running']) {
				$status = proc_close($process);
				$process = false;
				if ($status === -1) {
					$status = $state['exitcode'];
				}
				$output = '';
				if ($captureOutput) {
					if (!rewind($stdout)) {
						return $failed;
					}
					while (!feof($stdout)) {
						if (hrtime(true) >= $deadline) {
							return $failed;
						}
						$chunk = fread($stdout, 8192);
						if ($chunk === false) {
							return $failed;
						}
						$output .= $chunk;
					}
				}
				return ['completed' => true, 'status' => $status, 'output' => $output];
			}
			usleep(10000);
		}
		return $failed;
	} finally {
		if (is_resource($process)) {
			// On Windows this invokes TerminateProcess. On POSIX the signal
			// also makes the native fixture immune to an ignored SIGTERM.
			proc_terminate($process, 9);
			proc_close($process);
		}
		if (is_resource($stdout)) {
			fclose($stdout);
		}
		fclose($stdin);
	}
}
