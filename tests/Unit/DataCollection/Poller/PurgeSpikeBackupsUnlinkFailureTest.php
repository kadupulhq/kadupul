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

namespace PurgeSpikeBackupsUnlinkFailureTest;

/*
 * purge_spike_backups() (poller_spikekill.php) used to increment $purges
 * right after calling unlink(), without checking unlink()'s return value.
 * A backup that unlink() failed to remove (a stale NFS handle, an ACL
 * denying delete despite is_writable(dirname(...)) saying otherwise, a
 * race with another process) was still counted as purged, so an admin
 * reading the purge count had no way to notice backups were piling up.
 *
 * unlink() failing while the containing directory reports writable isn't
 * reproducible through real filesystem permissions on a portable, non-root
 * test (unlink() only needs write access to the directory, not the file),
 * so this evals purge_spike_backups() into its own namespace with a spy
 * unlink() that always fails, the same technique
 * PurgeSpikeBackupsRootBackupDirTest.php uses for is_link()/is_dir().
 */

$source = file_get_contents(dirname(__DIR__, 4) . '/poller_spikekill.php');

$start = strpos($source, 'function purge_spike_backups(');
expect($start)->not->toBeFalse();

$end = strpos($source, "\n}\n", $start);
$body = substr($source, $start, $end - $start + 2);

/* purge_spike_backups() normalizes spikekill_backupdir through
   cacti_trim_dir_separator() (lib/functions.php); pulled in the same way
   as PurgeSpikeBackupsRootBackupDirTest.php so the eval'd body resolves it
   instead of fataling on an undefined function */
$functions_source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

$trim_start = strpos($functions_source, 'function cacti_trim_dir_separator(');
expect($trim_start)->not->toBeFalse();

$trim_end = strpos($functions_source, "\n}\n", $trim_start);
$trim_body = substr($functions_source, $trim_start, $trim_end - $trim_start + 2);

eval("namespace PurgeSpikeBackupsUnlinkFailureTest;\n" . $trim_body); // nosemgrep: php.lang.security.eval-use.eval-use
eval("namespace PurgeSpikeBackupsUnlinkFailureTest;\n" . $body); // nosemgrep: php.lang.security.eval-use.eval-use

function reset_log() {
	$GLOBALS['purge_unlink_failure_log'] = array();
}

function read_config_option($option) {
	global $purge_unlink_failure_config;

	return $purge_unlink_failure_config[$option] ?? '';
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function cacti_log($message, $output = false, $environ = 'SPIKES') {
	$GLOBALS['purge_unlink_failure_log'][] = $message;
}

function unlink($path) {
	return false;
}

beforeEach(function () {
	reset_log();

	$this->dir = sys_get_temp_dir() . '/purge_unlink_failure_test_' . uniqid();
	mkdir($this->dir, 0700, true);
});

afterEach(function () {
	foreach (\glob($this->dir . '/*') as $item) {
		\unlink($item);
	}

	rmdir($this->dir);
});

test('a backup unlink() fails to remove is not counted as purged and is logged', function () {
	global $purge_unlink_failure_config;

	$backup = $this->dir . '/host_traffic.backup.123.rrd';
	file_put_contents($backup, 'backup-bytes');
	touch($backup, time() - 1000);

	$purge_unlink_failure_config = array(
		'spikekill_backupdir' => $this->dir,
		'spikekill_purge'     => 500,
	);

	$purges = purge_spike_backups();

	expect($purges)->toBe(0)
		->and(calls())->toBe([$backup . ' due to unlink failure']);
});

function calls() {
	return array_map(function ($message) {
		return preg_replace('/^Unable to remove /', '', $message);
	}, $GLOBALS['purge_unlink_failure_log']);
}
