<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Refuse directory paths another account can replace while a lease is held.
 * Root and the service account remain trusted and must quiesce all users before
 * changing storage paths. Check lexical symlink entries as well as their targets.
 */
function rrd_maintenance_directory_is_trusted($path) {
	if (!function_exists('posix_geteuid') || DIRECTORY_SEPARATOR === '\\' || $path === '') {
		return false;
	}
	if ($path[0] !== '/') {
		$path = getcwd() . '/' . $path;
	}
	global $config;
	$owners = array(0, posix_geteuid());
	$groups = $config['rrd_maintenance_trusted_gids'] ?? array();
	$additional_owners = $config['rrd_maintenance_trusted_uids'] ?? array();
	// Trust is an administrator decision, never inferred from file ownership.
	if (!is_array($groups) || !is_array($additional_owners)) {
		return false;
	}
	foreach (array_merge($groups, $additional_owners) as $id) {
		if (!is_int($id) || $id < 0) {
			return false;
		}
	}
	$owners = array_merge($owners, $additional_owners);
	$pending = array(array(rtrim($path, '/') ?: '/', true));
	$checked = array();
	while ($pending) {
		list($candidate, $leaf) = array_pop($pending);
		$key = $candidate . ($leaf ? ':leaf' : ':ancestor');
		if (isset($checked[$key])) {
			continue;
		}
		$checked[$key] = true;
		clearstatcache(true, $candidate);
		$entry = @lstat($candidate);
		$directory = @stat($candidate);
		$canonical = realpath($candidate);
		if (!$entry || !$directory || $canonical === false || ($directory['mode'] & 0170000) !== 0040000
			|| !in_array($entry['uid'], $owners, true) || !in_array($directory['uid'], $owners, true)
			|| ((($directory['mode'] & 0002) !== 0
			|| (($directory['mode'] & 0020) !== 0 && !in_array($directory['gid'], $groups, true)))
			&& ($leaf || ($directory['mode'] & 01000) === 0))) {
			return false;
		}
		if ($canonical !== $candidate) {
			$pending[] = array($canonical, $leaf);
		}
		$parent = dirname($candidate);
		if ($parent !== $candidate) {
			$pending[] = array($parent, false);
		}
	}
	return true;
}

/** Coordinate local RRDtool children and destructive maintenance.
 * Lock the existing RRA directory inode, not a removable lock file. All
 * processes accessing this store must use the same configured RRA directory.
 * Shared locks outlive queued commands: release only after pclose has waited
 * for the child. Exclusive maintenance refuses active writers by default;
 * callers may explicitly wait when their operation permits it.
 */
function rrd_maintenance_acquire($exclusive = false, $wait = false, $timeout = null) {
	global $config;

	if (($config['cacti_server_os'] ?? '') === 'win32') {
		return $exclusive ? false : true;
	}

	$path = $config['rra_path'] ?? (($config['base_path'] ?? '') . '/rra');
	$canonical = realpath($path);
	if ($canonical === false || !rrd_maintenance_directory_is_trusted($path)) {
		return false;
	}

	$expected = @stat($canonical);
	$handle = @fopen($canonical, 'r');
	if (!is_resource($handle)) {
		return false;
	}

	if (!$exclusive && $timeout === null) {
		$timeout = 5;
	}
	$flags = ($exclusive ? LOCK_EX : LOCK_SH) | (($wait && $timeout === null) ? 0 : LOCK_NB);
	$deadline = hrtime(true) + max(0, (float) $timeout) * 1000000000;
	while (!@flock($handle, $flags)) {
		if ($timeout === null || hrtime(true) >= $deadline) {
			fclose($handle);
			return false;
		}
		usleep(100000);
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

/** One registry owns child pipes, their leases, and their rewrite mode. */
function &rrd_maintenance_pipes() {
	static $pipes = array();
	return $pipes;
}

function rrd_maintenance_pipe_is_exclusive($pipe) {
	$pipes = &rrd_maintenance_pipes();
	$key = (int) $pipe;
	return isset($pipes[$key]) && $pipes[$key][0] === $pipe && !empty($pipes[$key][2]);
}

/** Retain both resources until the child has consumed its queued writes. */
function rrd_maintenance_pipe($pipe, $lock = null, $release = false, $exclusive = false) {
	$pipes = &rrd_maintenance_pipes();
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
	} elseif ($lock !== null && $lock !== false) {
		$pipes[$key] = array($pipe, $lock, $exclusive);
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

/** Refuse an upgrade before database changes when local storage needs migration. */
function rrd_maintenance_configuration_error() {
    global $config;
    if (($config['cacti_server_os'] ?? '') === 'win32' || read_config_option('storage_location')) {
        return '';
    }
    $path = $config['rra_path'] ?? (($config['base_path'] ?? '') . '/rra');
    if (rrd_maintenance_directory_is_trusted($path) && is_readable($path)) {
        return '';
    }
    return __('RRD storage is not ready for coordinated access. Enable PHP POSIX and configure the numeric rrd_maintenance_trusted_uids and rrd_maintenance_trusted_gids in include/config.php for every web and poller service account. Remove world-write permissions and check storage ancestors. See docs/testing/spikekill-safety.md before upgrading.');
}


/** Restore beside the original so a timeout cannot truncate the live RRD. */
function rrd_maintenance_restore($xml_file, $rrd_file, $pipe) {
    if (!rrd_maintenance_pipe_is_exclusive($pipe) || is_link($rrd_file)) {
        return false;
    }
    $metadata = @stat($rrd_file);
    $temporary = $metadata === false ? false : tempnam(dirname($rrd_file), '.rrd-restore-');
    if ($temporary === false) {
        return false;
    }
    try {
        if (rrdtool_execute(array('restore', '-f', $xml_file, $temporary), false, RRDTOOL_OUTPUT_BOOLEAN, $pipe, 'UTIL') !== true) {
            cacti_log('ERROR: RRD restore failed; original preserved and recovery XML retained at ' . $xml_file, false, 'UTIL');
            return false;
        }
        clearstatcache(true, $temporary);
        if ((fileowner($temporary) !== $metadata['uid'] && !chown($temporary, $metadata['uid']))
            || (filegroup($temporary) !== $metadata['gid'] && !chgrp($temporary, $metadata['gid']))
            || !chmod($temporary, $metadata['mode'] & 0777)) {
            cacti_log('ERROR: RRD restore could not preserve ownership; recovery XML retained at ' . $xml_file, false, 'UTIL');
            return false;
        }
        return rename($temporary, $rrd_file);
    } finally {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
    }
}
