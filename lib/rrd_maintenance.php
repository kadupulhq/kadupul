<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Refuse directory paths another account can replace while a lease is held.
 * Root and the service account remain trusted and must quiesce all users before
 * changing storage paths. Check lexical symlink entries as well as their targets.
 */
function rrd_maintenance_directory_is_trusted($path)
{
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
function rrd_maintenance_acquire($exclusive = false, $wait = false, $timeout = null, &$busy = null)
{
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

/** Lock configured storage and every possible trusted root for custom RRD paths. */
function rrd_maintenance_acquire_paths($files, $timeout = 0, &$busy = null)
{
    global $config;
    $busy = false;
    $had_path = array_key_exists('rra_path', $config);
    $saved_path = $config['rra_path'] ?? null;
    $original = $config['rra_path'] ?? (($config['base_path'] ?? '') . '/rra');
    $base = realpath($original);
    if ($base === false || !rrd_maintenance_directory_is_trusted($original)) {
        return false;
    }
    $directories = array($base => true);
    foreach ($files as $file) {
        if (!is_string($file) || $file === '' || strpbrk($file, "\r\n\0") !== false) {
            return false;
        }
        clearstatcache(true, $file);
        if (is_link($file)) {
            return false;
        }
        $parent = realpath(dirname($file));
        if ($parent === false || !rrd_maintenance_directory_is_trusted(dirname($file))) {
            return false;
        }
        if ($parent === $base || strpos($parent, $base . DIRECTORY_SEPARATOR) === 0) {
            continue;
        }
        // A custom file's writer can configure any ancestor as its RRA root.
        // Lock each trusted candidate, not just its immediate directory.
        for ($directory = $parent; ; $directory = dirname($directory)) {
            if (rrd_maintenance_directory_is_trusted($directory)) {
                $directories[$directory] = true;
            }
            if (dirname($directory) === $directory) {
                break;
            }
        }
    }
    ksort($directories, SORT_STRING);
    $locks = array();
    $deadline = microtime(true) + max(0, $timeout);
    try {
        foreach ($directories as $directory => $_) {
            $config['rra_path'] = $directory;
            $lock = rrd_maintenance_acquire(true, $timeout > 0, max(0, $deadline - microtime(true)), $busy);
            if ($lock === false) {
                rrd_maintenance_release($locks);
                return false;
            }
            $locks[] = $lock;
        }
        return $locks;
    } finally {
        if ($had_path) {
            $config['rra_path'] = $saved_path;
        } else {
            unset($config['rra_path']);
        }
    }
}

/** Private workspaces prevent shared-temp symlink substitution throughout a rewrite. */
function rrd_maintenance_workspace()
{
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/kadupul-rrd-' . bin2hex(random_bytes(16));
    if (!@mkdir($directory, 0700)) {
        return false;
    }
    if (!rrd_maintenance_directory_is_trusted($directory)) {
        @rmdir($directory);
        return false;
    }
    register_shutdown_function(function () use ($directory) {
        @rmdir($directory);
    });
    return $directory;
}

function rrd_maintenance_release($handle)
{
    if (is_array($handle)) {
        foreach (array_reverse($handle) as $lock) {
            rrd_maintenance_release($lock);
        } return;
    }
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** One registry owns child pipes, their leases, and their rewrite mode. */
function &rrd_maintenance_pipes()
{
    static $pipes = array();
    return $pipes;
}

function rrd_maintenance_pipe_is_exclusive($pipe)
{
    $pipes = &rrd_maintenance_pipes();
    $key = (int) $pipe;
    return isset($pipes[$key]) && $pipes[$key][0] === $pipe && !empty($pipes[$key][2]);
}

/** Retain both resources until the child has consumed its queued writes. */
function rrd_maintenance_pipe($pipe, $lock = null, $release = false, $exclusive = false)
{
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
function rrd_maintenance_cli_lock($exclusive = false, $wait = false)
{
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
function rrd_maintenance_cli_preflight()
{
    if (getenv('RRDCACHED_ADDRESS')) {
        fwrite(STDERR, "FATAL: Stop external RRD writers and disable RRDCACHED_ADDRESS before maintenance.\n");
        exit(1);
    }

}

/** Refuse an upgrade before database changes when local storage needs migration. */
function rrd_maintenance_configuration_error()
{
    global $config;
    if (($config['cacti_server_os'] ?? '') === 'win32' || read_config_option('storage_location')) {
        return '';
    }
    $path = $config['rra_path'] ?? (($config['base_path'] ?? '') . '/rra');
    if (rrd_maintenance_directory_is_trusted($path) && is_readable($path) && is_writable($path)) {
        return '';
    }
    return __('RRD storage is not ready for coordinated access. Enable PHP POSIX and configure the numeric rrd_maintenance_trusted_uids and rrd_maintenance_trusted_gids in include/config.php for every web and poller service account. Remove world-write permissions and check storage ancestors. See docs/testing/spikekill-safety.md before upgrading.');
}


/** Restore beside the original so a timeout cannot truncate the live RRD. */
function rrd_maintenance_restore($xml_file, $rrd_file, $pipe)
{
    global $config;
    if (is_array($pipe) && read_config_option('storage_location') && empty($config['force_storage_location_local'])) {
        if (strpbrk($xml_file . $rrd_file, "\r\n\0") !== false) {
            return false;
        }
        // Keep the existing remote restore protocol; local inode leases do not apply to a proxy.
        return rrdtool_execute('restore -f ' . cacti_escapeshellarg($xml_file) . ' ' . cacti_escapeshellarg($rrd_file), false, RRDTOOL_OUTPUT_BOOLEAN, $pipe, 'UTIL') === true;
    }
    if (!rrd_maintenance_pipe_is_exclusive($pipe) || is_link($rrd_file) || strpbrk($xml_file . $rrd_file, "\r\n\0") !== false) {
        cacti_log('ERROR: RRD restore requires an exclusive lease and safe regular-file paths.', false, 'UTIL');
        return false;
    }
    return rrd_maintenance_restore_atomic($xml_file, $rrd_file, function ($temporary) use ($xml_file, $pipe) {
        return rrdtool_execute(array('restore', '-f', $xml_file, $temporary), false, RRDTOOL_OUTPUT_BOOLEAN, $pipe, 'UTIL') === true;
    });
}

/** Caller owns the exclusive storage lease throughout snapshot, restore and rename. */
function rrd_maintenance_restore_atomic($xml_file, $rrd_file, $restore)
{
    if (is_link($rrd_file) || strpbrk($xml_file . $rrd_file, "\r\n\0") !== false) {
        return false;
    }
    $directory = realpath(dirname($rrd_file));
    if ($directory === false || !rrd_maintenance_directory_is_trusted($directory) || !is_writable($directory)
        || (file_exists($rrd_file) && !is_writable($rrd_file))) {
        cacti_log('ERROR: RRD restore requires writable storage directory and file; recovery XML retained at ' . $xml_file, false, 'UTIL');
        return false;
    }
    $metadata = @stat($rrd_file);
    if (file_exists($rrd_file) && $metadata === false) {
        return false;
    }
    $temporary = @tempnam($directory, '.rrd-restore-');
    if ($temporary === false) {
        cacti_log('ERROR: RRD restore could not create a temporary file; recovery XML retained at ' . $xml_file, false, 'UTIL');
        return false;
    }
    try {
        if (realpath(dirname($temporary)) !== $directory) {
            cacti_log('ERROR: RRD restore refused a temporary file outside storage.', false, 'UTIL');
            return false;
        }
        if ($restore($temporary) !== true) {
            cacti_log('ERROR: RRD restore failed; original preserved and recovery XML retained at ' . $xml_file, false, 'UTIL');
            return false;
        }
        clearstatcache(true, $temporary);
        if (!is_file($temporary) || is_link($temporary) || filesize($temporary) === 0) {
            return false;
        }
        if ($metadata === false) {
            $metadata = stat($temporary);
            $metadata['mode'] = 0666 & ~umask();
        }
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


/** Bounded direct-argv RRDtool execution; drains both output streams. */
function rrd_maintenance_run_command(array $argv, $stdout_handle, $timeout = 30)
{
    $capture_stdout = ($stdout_handle === null);

    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => $capture_stdout ? array('pipe', 'w') : $stdout_handle,
        2 => array('pipe', 'w'),
    );

    $process = @proc_open($argv, $descriptors, $pipes);

    if (!is_resource($process)) {
        return array('exit' => false, 'stdout' => '', 'stderr' => '');
    }

    fclose($pipes[0]);

    if ($capture_stdout) {
        stream_set_blocking($pipes[1], false);
    }

    stream_set_blocking($pipes[2], false);

    $stdout    = '';
    $stderr    = '';
    $remaining = (int) ($timeout * 1000000);
    $exit      = null;

    while ($remaining > 0) {
        $start  = microtime(true);
        $read   = $capture_stdout ? array($pipes[1], $pipes[2]) : array($pipes[2]);
        $write  = array();
        $except = array();
        $ready = stream_select($read, $write, $except, intdiv($remaining, 1000000), $remaining % 1000000);

        if ($ready === false || $ready === 0 || (feof($pipes[2]) && (!$capture_stdout || feof($pipes[1])))) {
            usleep(1000);
        }

        $status = proc_get_status($process);

        if ($capture_stdout) {
            $stdout .= stream_get_contents($pipes[1]);
        }

        $stderr .= stream_get_contents($pipes[2]);

        /* proc_get_status() returns false on a dead handle. Preserve a
           valid exitcode while it is observable because a later status
           read or proc_close() can return -1 after the child has
           already been reaped. */
        if (!is_array($status) || empty($status['running'])) {
            if (is_array($status) && isset($status['exitcode']) && $status['exitcode'] >= 0) {
                $exit = (int) $status['exitcode'];
            }

            break;
        }

        $remaining -= (int) ((microtime(true) - $start) * 1000000);
    }

    if ($capture_stdout) {
        fclose($pipes[1]);
    }

    fclose($pipes[2]);

    $status = proc_get_status($process);

    if (is_array($status) && !empty($status['running'])) {
        if (isset($status['pid']) && function_exists('posix_kill')) {
            posix_kill($status['pid'], 9);
        }

        proc_terminate($process, 9);
        proc_close($process);

        return array('exit' => false, 'stdout' => $stdout, 'stderr' => $stderr);
    }

    if ($exit === null && is_array($status) && isset($status['exitcode']) && $status['exitcode'] >= 0) {
        $exit = (int) $status['exitcode'];
    }

    $close_exit = proc_close($process);

    if ($exit === null) {
        $exit = $close_exit;
    }

    return array('exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr);
}

/** Synchronous CLI restore; validate the restored file before replacing its destination. */
function rrd_maintenance_restore_command($binary, $xml_file, $rrd_file, $range_check = false)
{
    return rrd_maintenance_restore_atomic($xml_file, $rrd_file, function ($temporary) use ($binary, $xml_file, $range_check) {
        $args = array($binary, 'restore', '-f');
        if ($range_check) {
            $args[] = '-r';
        }
        $args[] = $xml_file;
        $args[] = $temporary;
        $timeout = rrd_maintenance_command_timeout();
        $result = rrd_maintenance_run_command($args, null, $timeout);
        if ($result['exit'] !== 0) {
            return false;
        }
        $result = rrd_maintenance_run_command(array($binary, 'info', $temporary), null, $timeout);
        return $result['exit'] === 0 && trim($result['stdout']) !== '';
    });
}

/** Require durable storage before accepting retryable collector samples. */
function rrd_maintenance_queue_configuration_error()
{
    $engine = db_fetch_cell_prepared('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array('poller_output'));
    if (is_string($engine) && strtolower($engine) === 'innodb') {
        return '';
    }
    return 'The poller_output queue must use InnoDB before collection. Stop collectors and run cli/upgrade_database.php --migrate-poller-queue (or convert poller_output to InnoDB after a backup). Observed engine: ' . (is_string($engine) && $engine !== '' ? $engine : 'unavailable') . '. Retained samples must not be discarded to clear this condition.';
}

/** Stop collection before unsafe storage or a volatile retry queue can lose samples. */
function rrd_maintenance_poller_preflight()
{
    $error = rrd_maintenance_configuration_error();
    if ($error === '') {
        $error = rrd_maintenance_queue_configuration_error();
    }
    if ($error === '') {
        return true;
    }
    cacti_log('ERROR: Poller refused unsafe RRD storage: ' . $error, true, 'POLLER');
    if (function_exists('admin_email')) {
        admin_email(__('RRD storage configuration requires attention'), $error);
    }
    return false;
}

/** Administrators may bound large maintenance commands, up to eight hours. */
function rrd_maintenance_command_timeout()
{
    global $config;
    $seconds = $config['rrd_maintenance_command_timeout'] ?? 300;
    return is_numeric($seconds) && $seconds > 0 ? min(28800, (float) $seconds) : 300;
}

/** Windows has no validated exclusive local storage lease for automatic cleanup. */
function rrd_maintenance_cleanup_supported()
{
    global $config;
    return ($config['cacti_server_os'] ?? '') !== 'win32' || read_config_option('storage_location');
}
