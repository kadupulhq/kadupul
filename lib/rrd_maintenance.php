<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Coordinate local RRDtool children and destructive maintenance.
 * Lock the existing RRA directory inode, not a removable lock file. All
 * processes accessing this store must use the same configured RRA directory.
 * Shared locks outlive queued commands: release only after pclose has waited
 * for the child. Maintenance never waits for an active writer.
 */
function rrd_maintenance_acquire($exclusive = false) {
	global $config;

	if (($config['cacti_server_os'] ?? '') === 'win32') {
		return $exclusive ? false : true;
	}

	$path = $config['rra_path'] ?? (($config['base_path'] ?? '') . '/rra');
	$canonical = realpath($path);
	if ($canonical === false || !is_dir($canonical)) {
		return false;
	}

	$handle = @fopen($canonical, 'r');
	if (!is_resource($handle)) {
		return false;
	}

	if (!@flock($handle, $exclusive ? LOCK_EX | LOCK_NB : LOCK_SH)) {
		fclose($handle);
		return false;
	}

	clearstatcache(true, $path);
	clearstatcache(true, $canonical);
	$opened = fstat($handle);
	$current = @stat($path);
	if (!$opened || !$current || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
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
