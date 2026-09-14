<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

namespace PurgeSpikeBackupsWritePermissionLogTest;

/*
 * purge_spike_backups() (poller_spikekill.php) logs both the write-permission
 * and unlink-failure refusals through cacti_log($msg, 'SPIKES'), passing
 * 'SPIKES' as $output (the second parameter) instead of $environ (the
 * third). cacti_log()'s $output controls whether the message is also echoed
 * to poller output (lib/functions.php); a truthy string there means every
 * refusal is printed as well as logged.
 *
 * dirname($filepath) is the same string as $directory here (files sit
 * directly inside the backup directory), so a real, unstubbed is_writable()
 * cannot make the outer directory check pass while the inner per-file check
 * fails. This evals purge_spike_backups() into its own namespace with a
 * fully faked filesystem, the same technique
 * PurgeSpikeBackupsRootBackupDirTest.php uses, and a call-counted
 * is_writable() spy that answers true for the directory-level gate and
 * false for the per-file check that follows it, to reach the
 * write-permission log line deterministically.
 */

$source = file_get_contents(dirname(__DIR__, 4) . '/poller_spikekill.php');

$start = strpos($source, 'function purge_spike_backups(');
expect($start)->not->toBeFalse();

$end = strpos($source, "\n}\n", $start);
$body = substr($source, $start, $end - $start + 2);

/* purge_spike_backups() normalizes spikekill_backupdir through
   cacti_trim_dir_separator() (lib/functions.php), the same pure helper
   spikekill::normalizeDir() delegates to; pull the real implementation in
   too so the eval'd body resolves it instead of fataling on an undefined
   function */
$functions_source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

$trim_start = strpos($functions_source, 'function cacti_trim_dir_separator(');
expect($trim_start)->not->toBeFalse();

$trim_end = strpos($functions_source, "\n}\n", $trim_start);
$trim_body = substr($functions_source, $trim_start, $trim_end - $trim_start + 2);

/* purge_spike_backups() also joins the trimmed backup directory to each
   filename through cacti_join_dir_child() (lib/functions.php), pulled in
   the same way */
$join_start = strpos($functions_source, 'function cacti_join_dir_child(');
expect($join_start)->not->toBeFalse();

$join_end = strpos($functions_source, "\n}\n", $join_start);
$join_body = substr($functions_source, $join_start, $join_end - $join_start + 2);

eval("namespace PurgeSpikeBackupsWritePermissionLogTest;\n" . $trim_body); // nosemgrep: php.lang.security.eval-use.eval-use
eval("namespace PurgeSpikeBackupsWritePermissionLogTest;\n" . $join_body); // nosemgrep: php.lang.security.eval-use.eval-use
eval("namespace PurgeSpikeBackupsWritePermissionLogTest;\n" . $body); // nosemgrep: php.lang.security.eval-use.eval-use

function reset_state() {
	$GLOBALS['purge_write_permission_log']           = array();
	$GLOBALS['purge_write_permission_writable_calls'] = 0;
}

function read_config_option($option) {
	global $purge_write_permission_config;

	return $purge_write_permission_config[$option] ?? '';
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function is_link($path) {
	return false;
}

function is_dir($path) {
	return true;
}

function is_writable($path) {
	$GLOBALS['purge_write_permission_writable_calls']++;

	/* call 1 is the directory-level gate; every call after that is the
	   dirname($filepath) check purge_spike_backups() makes before unlink() */
	return $GLOBALS['purge_write_permission_writable_calls'] === 1;
}

function scandir($path) {
	return array('.', '..', 'host_traffic.backup.123.rrd');
}

function is_file($path) {
	return true;
}

function filemtime($path) {
	return time() - 1000;
}

function cacti_log($message, $output = false, $environ = 'SPIKES') {
	$GLOBALS['purge_write_permission_log'][] = array(
		'message' => $message,
		'output'  => $output,
		'environ' => $environ,
	);
}

beforeEach(function () {
	reset_state();
});

test('a backup skipped for write permissions is not counted as purged and logs with output off', function () {
	global $purge_write_permission_config;

	$purge_write_permission_config = array(
		'spikekill_backupdir' => '/var/lib/cacti/backups',
		'spikekill_purge'     => 500,
	);

	$purges = purge_spike_backups();

	$entries = $GLOBALS['purge_write_permission_log'];

	expect($purges)->toBe(0)
		->and($entries)->toHaveCount(1)
		->and($entries[0]['message'])->toBe('Unable to remove /var/lib/cacti/backups/host_traffic.backup.123.rrd due to write permissions')
		->and($entries[0]['output'])->toBeFalse()
		->and($entries[0]['environ'])->toBe('SPIKES');
});
