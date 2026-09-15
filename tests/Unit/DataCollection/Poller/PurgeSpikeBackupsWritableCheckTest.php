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

/*
 * poller_spikekill.php's purge_spike_backups() runs as the poller (or web)
 * user and deletes expired backups that lib/spikekill.php's
 * copyFileSafely() created earlier while running as root under
 * umask(0177), so they are 0600 and owned by root. The prior code gated
 * each deletion on is_writable($filepath), which is always false for the
 * poller user against a root-owned file, so those backups were never
 * purged and just accumulated. Deleting a file only requires write access
 * to its containing directory, not to the file itself, so the check is
 * moved to is_writable(dirname($filepath)). A is_link() guard also skips
 * a planted symlink outright rather than following it into is_file().
 *
 * The function is extracted here by string and eval()'d, the same way
 * StructureRraPathsDestDirSafetyTest exercises structure_rra_paths.php,
 * because poller_spikekill.php runs top-level poller setup on include.
 *
 * read_config_option()/cacti_log()/cacti_sizeof() are guarded with
 * function_exists() because SpikekillXmlDumpFileSafetyTest.php,
 * SpikekillBackupSymlinkSafetyTest.php and HeadersSecureTest.php stub the
 * same globals and all four files run in the same Pest process. Every one
 * of those read_config_option() stubs reads and writes
 * $GLOBALS['__test_config_options'], the store HeadersSecureTest.php
 * already used, so whichever file's guarded definition wins the
 * function_exists() race still honors this file's stubbed values instead
 * of silently falling back to another file's.
 */

function purge_spike_backups_test_stub_config($values) {
	$GLOBALS['__test_config_options'] = $values;
}

if (!function_exists('read_config_option')) {
	function read_config_option($option) {
		return $GLOBALS['__test_config_options'][$option] ?? '';
	}
}

if (!function_exists('cacti_sizeof')) {
	function cacti_sizeof($value) {
		return is_array($value) ? count($value) : 0;
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $output = false, $environ = 'SPIKES') {
		global $spikekill_shell_test_log;

		$spikekill_shell_test_log[] = $message;
	}
}

$source = file_get_contents(dirname(__DIR__, 3) . '/../poller_spikekill.php');

$start = strpos($source, 'function purge_spike_backups(');
expect($start)->not->toBeFalse();

$end = strpos($source, "\n}\n", $start);
$body = substr($source, $start, $end - $start + 2);

eval($body); // nosemgrep: php.lang.security.eval-use.eval-use

/* purge_spike_backups() normalizes spikekill_backupdir through
   cacti_trim_dir_separator() (lib/functions.php), the same pure helper
   spikekill::normalizeDir() delegates to. Pulled in by the same
   extraction technique as above, guarded because
   SpikekillBackupSymlinkSafetyTest.php needs it too and both files run in
   the same Pest process. */
if (!function_exists('cacti_trim_dir_separator')) {
	$functions_source = file_get_contents(dirname(__DIR__, 3) . '/../lib/functions.php');

	$trim_start = strpos($functions_source, 'function cacti_trim_dir_separator(');
	expect($trim_start)->not->toBeFalse();

	$trim_end = strpos($functions_source, "\n}\n", $trim_start);
	$trim_body = substr($functions_source, $trim_start, $trim_end - $trim_start + 2);

	eval($trim_body); // nosemgrep: php.lang.security.eval-use.eval-use
}

/* purge_spike_backups() joins the trimmed backup directory to each
   filename through cacti_join_dir_child() (lib/functions.php), the same
   pure helper spikekill.php's requested-backup and temp-XML paths use.
   Pulled in by the same extraction technique as above. */
if (!function_exists('cacti_join_dir_child')) {
	$functions_source = file_get_contents(dirname(__DIR__, 3) . '/../lib/functions.php');

	$join_start = strpos($functions_source, 'function cacti_join_dir_child(');
	expect($join_start)->not->toBeFalse();

	$join_end = strpos($functions_source, "\n}\n", $join_start);
	$join_body = substr($functions_source, $join_start, $join_end - $join_start + 2);

	eval($join_body); // nosemgrep: php.lang.security.eval-use.eval-use
}

beforeEach(function () {
	global $spikekill_shell_test_log;

	$spikekill_shell_test_log = array();

	$this->dir = sys_get_temp_dir() . '/purge_spike_backups_test_' . uniqid();
	mkdir($this->dir, 0700, true);
});

afterEach(function () {
	foreach (glob($this->dir . '/*') as $item) {
		unlink($item);
	}

	rmdir($this->dir);
});

test('an expired root-owned backup is purged when the directory is writable', function () {
	$backup = $this->dir . '/host_traffic.backup.123.rrd';
	file_put_contents($backup, 'backup-bytes');

	/* 0400 leaves the file non-writable to the test user even though it
	   owns it, simulating the root-owned, poller-inaccessible backup this
	   test is about; 0600 would still be writable to the owner and pass
	   under the old is_writable($filepath) check too, proving nothing */
	chmod($backup, 0400);
	touch($backup, time() - 1000);

	purge_spike_backups_test_stub_config(array(
		'spikekill_backupdir' => $this->dir,
		'spikekill_purge'     => 500,
	));

	$purges = purge_spike_backups();

	expect($purges)->toBe(1)
		->and(file_exists($backup))->toBeFalse();
});

test('a backup newer than the retention window is left alone', function () {
	$backup = $this->dir . '/host_traffic.backup.123.rrd';
	file_put_contents($backup, 'backup-bytes');
	touch($backup, time());

	purge_spike_backups_test_stub_config(array(
		'spikekill_backupdir' => $this->dir,
		'spikekill_purge'     => 500,
	));

	$purges = purge_spike_backups();

	expect($purges)->toBe(0)
		->and(file_exists($backup))->toBeTrue();

	unlink($backup);
});

test('a symlink planted in the backup directory is skipped, not followed or removed', function () {
	/* the target lives outside the scanned directory so the only entry
	   purge_spike_backups() iterates over is the symlink itself */
	$evil_target = sys_get_temp_dir() . '/purge_spike_backups_evil_' . uniqid() . '.rrd';
	file_put_contents($evil_target, 'do-not-touch');
	touch($evil_target, time() - 1000);

	$link = $this->dir . '/host_traffic.backup.999.rrd';
	symlink($evil_target, $link);

	purge_spike_backups_test_stub_config(array(
		'spikekill_backupdir' => $this->dir,
		'spikekill_purge'     => 500,
	));

	$purges = purge_spike_backups();

	expect($purges)->toBe(0)
		->and(is_link($link))->toBeTrue()
		->and(file_exists($evil_target))->toBeTrue();

	unlink($link);
	unlink($evil_target);
});

test('no retention configured skips the purge entirely', function () {
	purge_spike_backups_test_stub_config(array(
		'spikekill_backupdir' => $this->dir,
		'spikekill_purge'     => '',
	));

	expect(purge_spike_backups())->toBeFalse();
});

test('a symlinked backup directory is refused before it is ever scanned, even with a trailing slash', function () {
	/* spikekill_backupdir defaults to a path with a trailing slash
	   (include/global_settings.php); is_link('dir/') follows the final
	   symlink to stat what it points at instead of the link itself, so
	   the directory-level is_link() check has to run against the
	   trailing-slash-stripped value or a symlinked backup directory
	   would still be scanned */
	$real_dir = $this->dir . '/real-backupdir';
	mkdir($real_dir, 0700, true);

	$expired = $real_dir . '/host_traffic.backup.123.rrd';
	file_put_contents($expired, 'backup-bytes');
	touch($expired, time() - 1000);

	$link = $this->dir . '/backupdir-link';
	symlink($real_dir, $link);

	purge_spike_backups_test_stub_config(array(
		'spikekill_backupdir' => $link . '/',
		'spikekill_purge'     => 500,
	));

	$purges = purge_spike_backups();

	expect($purges)->toBe(0)
		->and(file_exists($expired))->toBeTrue();

	unlink($expired);
	unlink($link);
	rmdir($real_dir);
});
