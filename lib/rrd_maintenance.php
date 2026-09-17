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
function rrd_maintenance_acquire($exclusive = false, $wait = false, $timeout = null, &$busy = null) {
	global $config;
	$busy = false;

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
	$would_block = 0;
	while (!@flock($handle, $flags, $would_block)) {
		if ($timeout === null || hrtime(true) >= $deadline) {
			$busy = $would_block === 1;
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

	// Destructive maintenance must fail closed where exclusive locks are unavailable.
	$lock = rrd_maintenance_acquire($exclusive, $wait);
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
    if (read_config_option('storage_location') && ($config['force_storage_location_local'] ?? false) !== true) {
        return '';
    }
    $path = $config['rra_path'] ?? (($config['base_path'] ?? '') . '/rra');
    if (($config['cacti_server_os'] ?? '') === 'win32') {
        return is_dir($path) && is_readable($path) && is_writable($path) ? '' :
            __('RRD storage is not ready: the configured directory must exist and be readable and writable by this service account.') . ' [path=' . $path . ']';
    }
    if (rrd_maintenance_directory_is_trusted($path) && is_readable($path) && is_writable($path)) {
        return '';
    }
    return __('RRD storage is not ready for coordinated access. Enable PHP POSIX and configure the numeric rrd_maintenance_trusted_uids and rrd_maintenance_trusted_gids in include/config.php for every web and poller service account. Remove world-write permissions and check storage ancestors. See docs/testing/spikekill-safety.md before upgrading.') . sprintf(' [path=%s; uid=%s; gid=%s]', $path, function_exists('posix_geteuid') ? posix_geteuid() : 'POSIX unavailable', function_exists('posix_getegid') ? posix_getegid() : 'POSIX unavailable');
}


/** Restore beside the original so a timeout cannot truncate the live RRD. */
function rrd_maintenance_restore($xml_file, $rrd_file, $pipe) {
    if (!rrd_maintenance_pipe_is_exclusive($pipe) || is_link($rrd_file) || strpbrk($xml_file . $rrd_file, "\r\n\0") !== false) {
        cacti_log('ERROR: RRD restore requires an exclusive lease and safe regular-file paths.', false, 'UTIL');
        return false;
    }
    $directory = realpath(dirname($rrd_file));
    if ($directory === false || !is_writable($directory) || !is_writable($rrd_file)) {
        cacti_log('ERROR: RRD restore requires writable storage directory and file; recovery XML retained at ' . $xml_file, false, 'UTIL');
        return false;
    }
    $metadata = @stat($rrd_file);
    $temporary = $metadata === false ? false : @tempnam($directory, '.rrd-restore-');
    if ($temporary === false) {
        cacti_log('ERROR: RRD restore could not create a temporary file; recovery XML retained at ' . $xml_file, false, 'UTIL');
        return false;
    }
    try {
        if (realpath(dirname($temporary)) !== $directory) {
            cacti_log('ERROR: RRD restore refused a temporary file outside storage.', false, 'UTIL');
            return false;
        }
        if (rrdtool_execute(array('restore', '-f', $xml_file, $temporary), false, RRDTOOL_OUTPUT_BOOLEAN, $pipe, 'UTIL') !== true) {
            cacti_log('ERROR: RRD restore failed; original preserved and recovery XML retained at ' . $xml_file, false, 'UTIL');
            return false;
        }
        clearstatcache(true, $temporary);
        if ((fileowner($temporary) !== $metadata['uid'] && !@chown($temporary, $metadata['uid']))
            || (filegroup($temporary) !== $metadata['gid'] && !@chgrp($temporary, $metadata['gid']))
            || !@chmod($temporary, $metadata['mode'] & 0777)) {
            cacti_log('ERROR: RRD restore could not preserve ownership; recovery XML retained at ' . $xml_file, false, 'UTIL');
            return false;
        }
        if (!@rename($temporary, $rrd_file)) {
            cacti_log('ERROR: RRD restore could not replace original; recovery XML retained at ' . $xml_file, false, 'UTIL');
            return false;
        }
        return true;
    } finally {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
    }
}


/** Require durable storage before accepting retryable collector samples. */
function rrd_maintenance_queue_configuration_error($queue_connection = false) {
    $engine = db_fetch_cell_prepared('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array('poller_output'), '', true, $queue_connection);
    if (is_string($engine) && strtolower($engine) === 'innodb') {
        return '';
    }
    return sprintf(__('The poller_output queue must use InnoDB before collection. Stop all collectors, including remote collectors, and run cli/upgrade_database.php (or convert poller_output to InnoDB after a backup). Observed engine: %s. Retained samples must not be discarded to clear this condition.'), is_string($engine) && $engine !== '' ? $engine : __('unavailable'));
}

/** Stop collection before unsafe storage or a volatile retry queue can lose samples. */
function rrd_maintenance_poller_preflight($check_storage = true, $queue_connection = false) {
    $error = $check_storage ? rrd_maintenance_configuration_error() : '';
    if ($error === '') { $error = rrd_maintenance_queue_configuration_error($queue_connection); }
    if ($error === '') {
        return true;
    }
    cacti_log('ERROR: Poller refused unsafe RRD storage or queue: ' . $error, true, 'POLLER');
    if (function_exists('admin_email') && debounce_run_notification('rrd_preflight_refused', 1800)) {
        admin_email(__('RRD storage configuration requires attention'), $error);
    }
    return false;
}

/** Windows has no validated exclusive local storage lease for automatic cleanup. */
function rrd_maintenance_cleanup_supported() {
    global $config;
    return ($config['cacti_server_os'] ?? '') !== 'win32' ||
        (read_config_option('storage_location') && ($config['force_storage_location_local'] ?? false) !== true);
}
