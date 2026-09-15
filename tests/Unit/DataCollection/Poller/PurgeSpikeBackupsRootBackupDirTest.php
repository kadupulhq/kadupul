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

namespace PurgeSpikeBackupsRootBackupDirTest;

/*
 * purge_spike_backups() strips a trailing slash off spikekill_backupdir
 * before the is_link()/is_dir() guard runs, because is_link('dir/')
 * follows the final symlink instead of stating the link itself. A bare
 * rtrim() turns '/' or '///' into '', which then fails that guard and
 * silently skips the purge, the one directory spikekill::normalizeDir()
 * (lib/spikekill.php) is careful to keep as '/'.
 *
 * This evals purge_spike_backups() into its own namespace with spy
 * versions of is_link(), is_dir(), is_writable() and scandir(), the same
 * technique StructureRraPathsRecursiveOwnershipSafetyTest.php uses, so the
 * scan can be proven to target '/' without ever touching the real root.
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

eval("namespace PurgeSpikeBackupsRootBackupDirTest;\n" . $trim_body); // nosemgrep: php.lang.security.eval-use.eval-use
eval("namespace PurgeSpikeBackupsRootBackupDirTest;\n" . $body); // nosemgrep: php.lang.security.eval-use.eval-use

function reset_calls() {
	$GLOBALS['purge_root_backupdir_calls'] = array();
}

function calls() {
	return $GLOBALS['purge_root_backupdir_calls'];
}

function read_config_option($option) {
	global $purge_root_backupdir_config;

	return $purge_root_backupdir_config[$option] ?? '';
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function is_link($path) {
	$GLOBALS['purge_root_backupdir_calls'][] = array('is_link', $path);

	return false;
}

function is_dir($path) {
	$GLOBALS['purge_root_backupdir_calls'][] = array('is_dir', $path);

	return true;
}

function is_writable($path) {
	$GLOBALS['purge_root_backupdir_calls'][] = array('is_writable', $path);

	return true;
}

function scandir($path) {
	$GLOBALS['purge_root_backupdir_calls'][] = array('scandir', $path);

	return array('.', '..');
}

beforeEach(function () {
	reset_calls();
});

test('a bare root backup dir is normalized to / and reaches the scan, not skipped as empty', function () {
	global $purge_root_backupdir_config;

	$purge_root_backupdir_config = array(
		'spikekill_backupdir' => '/',
		'spikekill_purge'     => 500,
	);

	purge_spike_backups();

	expect(calls())->toBe(array(
		array('is_link', '/'),
		array('is_dir', '/'),
		array('is_writable', '/'),
		array('scandir', '/'),
	));
});

test('a root backup dir configured with repeated trailing slashes is normalized to / the same way', function () {
	global $purge_root_backupdir_config;

	$purge_root_backupdir_config = array(
		'spikekill_backupdir' => '///',
		'spikekill_purge'     => 500,
	);

	purge_spike_backups();

	expect(calls())->toBe(array(
		array('is_link', '/'),
		array('is_dir', '/'),
		array('is_writable', '/'),
		array('scandir', '/'),
	));
});
