<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Coordinate local RRDtool children and destructive maintenance.
 * Lock the existing RRA directory inode, not a removable lock file. All
 * processes accessing this store must use the same configured RRA directory.
 * Shared locks outlive queued commands: release only after pclose has waited
 * for the child. Exclusive maintenance refuses active writers by default;
 * callers may explicitly wait when their operation permits it.
 */
function rrd_maintenance_acquire($exclusive = false, $wait = false) {
	global $config;

	if (($config['cacti_server_os'] ?? '') === 'win32') {
		return $exclusive ? false : true;
	}

	$path = $config['rra_path'] ?? (($config['base_path'] ?? '') . '/rra');
	$canonical = realpath($path);
	if ($canonical === false || !is_dir($canonical)) {
		return false;
	}

	$expected = @stat($canonical);
	$handle = @fopen($canonical, 'r');
	if (!is_resource($handle)) {
		return false;
	}

	if (!@flock($handle, $exclusive ? LOCK_EX | ($wait ? 0 : LOCK_NB) : LOCK_SH)) {
		fclose($handle);
		return false;
	}

	clearstatcache(true, $path);
	clearstatcache(true, $canonical);
	$opened = fstat($handle);
	$current = @stat($path);
	if (!$expected || !$opened || !$current || ($expected['mode'] & 0170000) !== 0040000 || $opened['dev'] !== $expected['dev'] || $opened['ino'] !== $expected['ino'] || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
		fclose($handle);
		return false;
	}

	return $handle;
}

function rrd_maintenance_release($handle) {
	if (is_resource($handle)) {
		flock($handle, LOCK_UN);
		fclose($handle);
	}
}

/** Retain both resources until the child has consumed its queued writes. */
function rrd_maintenance_pipe($pipe, $lock = null, $release = false) {
	static $pipes = array();
	static $registered = false;

	if (!$registered) {
		$registered = true;
		register_shutdown_function(function () use (&$pipes) {
			foreach ($pipes as $entry) {
				if (is_resource($entry[0])) {
					pclose($entry[0]);
				}
				rrd_maintenance_release($entry[1]);
			}
			$pipes = array();
		});
	}

	$key = (int) $pipe;
	if ($release) {
		if (isset($pipes[$key])) {
			rrd_maintenance_release($pipes[$key][1]);
			unset($pipes[$key]);
		}
	} elseif ($lock !== null) {
		$pipes[$key] = array($pipe, $lock);
	}

	return isset($pipes[$key]) && $pipes[$key][0] === $pipe && is_resource($pipe);
}

/** A CLI must stop before touching data when its lock cannot be obtained. */
function rrd_maintenance_cli_lock($exclusive = false, $wait = false) {
	global $config;

	if ($exclusive) {
		rrd_maintenance_cli_preflight();
	}

	// Spike removal is unavailable on Windows; retain other CLI behavior there.
	$lock = rrd_maintenance_acquire($exclusive && ($config['cacti_server_os'] ?? '') !== 'win32', $wait);
	if ($lock === false) {
		fwrite(STDERR, "FATAL: RRD storage is busy or its maintenance lock is unavailable.\n");
		exit(1);
	}
	return $lock;
}

/** Reject cache-daemon rewrites before flushing or modifying any data. */
function rrd_maintenance_cli_preflight() {
	if (getenv('RRDCACHED_ADDRESS')) {
		fwrite(STDERR, "FATAL: Stop external RRD writers and disable RRDCACHED_ADDRESS before maintenance.\n");
		exit(1);
	}

}
