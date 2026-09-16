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
 * remove_spikes() ties together the dump (runRRDDump), the stddev
 * detection pass (calculateOverallStatistics/updateXML), the pre-restore
 * backup (backupRRDFile) and the restore (createRRDFileFromXML) covered
 * individually by SpikekillXmlDumpFileSafetyTest.php and
 * SpikekillBackupSymlinkSafetyTest.php. Those files deliberately stopped
 * short of calling remove_spikes() itself, on the grounds that driving it
 * end to end needs read_user_setting(), cacti_sizeof() and friends beyond
 * what a targeted safety test needs, and that its three false-return
 * checks (writeXMLFile, backupRRDFile, createRRDFileFromXML) were already
 * exercised individually.
 *
 * This test takes on that stubbing to close the remaining gap: nothing
 * previously called remove_spikes() itself, so a regression in how it
 * wires those pieces together (for example, checking the wrong return
 * value, or losing the temp XML cleanup on one of the failure paths)
 * would not have been caught. Everything at or below the rrdtool
 * boundary is stubbed, the same way SpikekillXmlDumpFileSafetyTest.php
 * stubs the rrdtool binary itself; nothing here touches a real RRD file
 * or a real rrdtool.
 *
 * The class is instantiated normally (not via reflection) because
 * remove_spikes(), get_errors() and the constructor are all public, and
 * running the real constructor exercises the same read_config_option()
 * calls production does.
 */

/* spikekill::normalizeDir() delegates to cacti_trim_dir_separator()
   (lib/functions.php); only this one pure function is extracted by
   source, the same technique SpikekillBackupSymlinkSafetyTest.php uses,
   rather than require'ing lib/functions.php as a whole, which would
   define the real read_config_option(), cacti_log() and cacti_sizeof()
   ahead of every stub below and every other test file's function_exists()
   guard in this same Pest process */
if (!function_exists('cacti_trim_dir_separator')) {
	$spikekill_functions_source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	$trim_start = strpos($spikekill_functions_source, 'function cacti_trim_dir_separator(');
	expect($trim_start)->not->toBeFalse();

	$trim_end = strpos($spikekill_functions_source, "\n}\n", $trim_start);
	$trim_body = substr($spikekill_functions_source, $trim_start, $trim_end - $trim_start + 2);

	eval($trim_body); // nosemgrep: php.lang.security.eval-use.eval-use
}

require_once dirname(__DIR__, 3) . '/Helpers/SpikekillPathFunctions.php';

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';

/* read_config_option()/cacti_log()/cacti_sizeof()/__esc() are guarded with
   function_exists() because PurgeSpikeBackupsWritableCheckTest.php,
   SpikekillBackupSymlinkSafetyTest.php, SpikekillXmlDumpFileSafetyTest.php
   and HeadersSecureTest.php also stub some of these and all of these
   files run in the same Pest process. Every read_config_option() stub
   reads and writes $GLOBALS['__test_config_options'], the store
   HeadersSecureTest.php already used, so whichever file's guarded
   definition wins the function_exists() race still honors this file's
   stubbed values instead of silently falling back to another file's. */

function spikekill_e2e_test_stub_config($values) {
	$GLOBALS['__test_config_options'] = $values;
}

if (!function_exists('read_config_option')) {
	function read_config_option($option) {
		return $GLOBALS['__test_config_options'][$option] ?? '';
	}
}

if (!function_exists('read_user_setting')) {
	function read_user_setting($name, $default = '', $force = false) {
		return $default;
	}
}

if (!function_exists('cacti_sizeof')) {
	function cacti_sizeof($value) {
		return is_array($value) ? count($value) : 0;
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $output = false, $environ = 'SPIKEKILL', $level = '') {
		global $spikekill_e2e_test_log;

		$spikekill_e2e_test_log[] = $message;
	}
}

if (!function_exists('__esc')) {
	function __esc($format) {
		$args = func_get_args();
		array_shift($args);

		return htmlspecialchars($args ? vsprintf($format, $args) : $format, ENT_QUOTES);
	}
}

if (!function_exists('__')) {
	function __($format) {
		$args = func_get_args();
		array_shift($args);

		return $args ? vsprintf($format, $args) : $format;
	}
}

if (!defined('POLLER_VERBOSITY_DEBUG')) {
	define('POLLER_VERBOSITY_DEBUG', 0);
}

function spikekill_e2e_instance($rrdfile) {
	/* stddev/'avg' with every threshold passed explicitly (rather than
	   left to fall through to read_config_option()/read_user_setting()
	   defaults, which this stub resolves to '') avoids the class doing
	   arithmetic on an empty string, which throws under PHP 8 */
	return new spikekill($rrdfile, 'stddev', 'avg', '1', '', '', '2', '500', '100');
}

beforeEach(function () {
	global $spikekill_e2e_test_log;

	$spikekill_e2e_test_log = array();

	$_SESSION = $_SESSION ?? array();
	unset($_SESSION['sess_user_id']);

	$GLOBALS['config']['cacti_server_os'] = 'unix';

	$this->dir = sys_get_temp_dir() . '/spikekill_e2e_test_' . uniqid();
	mkdir($this->dir, 0700, true);

	/* the RRD and the backup/temp directory live apart, or the backup's
	   first-choice destination name (basename($rrdfile) under the backup
	   dir) would collide with the source RRD itself when both sit in the
	   same directory */
	$this->rrd_dir = $this->dir . '/rrd';
	mkdir($this->rrd_dir, 0700, true);

	$this->backup_dir = $this->dir . '/backup';
	mkdir($this->backup_dir, 0700, true);

	$this->rrdfile = $this->rrd_dir . '/source.rrd';
	file_put_contents($this->rrdfile, 'original-rrd-bytes');

	/* nineteen rows at 1.0e+01 and one at 1.0e+05: whatever population or
	   sample variance formula calculateStandardDeviation() uses, a
	   1-standard-deviation cutoff around a mean this close to 10 is off
	   by orders of magnitude from 1.0e+05, so exactly one row trips
	   std_kills regardless of the exact formula */
	$rows = '';

	for ($i = 0; $i < 19; $i++) {
		$rows .= '<row><timestamp>' . (1000000000 + $i * 300) . '</timestamp><v>1.0000000000e+01</v></row>' . "\n";
	}

	$rows .= '<row><timestamp>' . (1000000000 + 19 * 300) . '</timestamp><v>1.0000000000e+05</v></row>' . "\n";

	$this->dump_fixture = $this->dir . '/dump-fixture.xml';
	file_put_contents($this->dump_fixture,
		"<step>300</step>\n" .
		"<name>traffic_in</name>\n" .
		"<min>0</min>\n" .
		"<max>1000000</max>\n" .
		"<rra>\n" .
		"<cf>AVERAGE</cf>\n" .
		"<pdp_per_row>1</pdp_per_row>\n" .
		"<database>\n" .
		$rows .
		"</database>\n" .
		"</rra>\n"
	);

	/* a stub 'rrdtool' whose 'dump' subcommand streams the fixture above
	   to stdout regardless of the rrdfile argument (the same shape
	   SpikekillXmlDumpFileSafetyTest.php's stub uses), and whose
	   'restore' subcommand exits per RESTORE_STUB_EXIT without touching
	   the filesystem */
	$this->rrdtool_stub = $this->dir . '/rrdtool-stub.sh';
	file_put_contents($this->rrdtool_stub, "#!/bin/sh\n" .
		"if [ \"\$1\" = 'dump' ]; then\n" .
		"  cat \"\$RRDTOOL_STUB_DUMP_FIXTURE\"\n" .
		"  exit 0\n" .
		"fi\n" .
		"if [ \"\$1\" = 'restore' ]; then\n" .
		"  printf restored-rrd-bytes > \"\$5\"\n" .
        "  restore_exit=\"\${RESTORE_STUB_EXIT:-0}\"\n" .
		"  if [ \"\$restore_exit\" != '0' ]; then\n" .
		"    echo 'stub restore failed' >&2\n" .
		"  fi\n" .
		"  exit \"\$restore_exit\"\n" .
		"fi\n" .
		"exit 1\n");
	chmod($this->rrdtool_stub, 0700);

	putenv('RRDTOOL_STUB_DUMP_FIXTURE=' . $this->dump_fixture);
	putenv('RESTORE_STUB_EXIT=0');

	$GLOBALS['config']['rra_path'] = $this->rrd_dir;

	spikekill_e2e_test_stub_config(array(
		'spikekill_backupdir' => $this->backup_dir,
		'path_rrdtool'        => $this->rrdtool_stub,
	));
});

afterEach(function () {
	putenv('RRDTOOL_STUB_DUMP_FIXTURE');
	putenv('RESTORE_STUB_EXIT');

	/* unlink() only needs write access to the containing directory, not
	   to the file itself, so the failed-backup test's chmod(0222) needs
	   no reverting here */
	foreach (glob($this->rrd_dir . '/*') as $item) {
		unlink($item);
	}

	rmdir($this->rrd_dir);

	foreach (glob($this->backup_dir . '/*') as $item) {
		unlink($item);
	}

	rmdir($this->backup_dir);

	foreach (glob($this->dir . '/*') as $item) {
		unlink($item);
	}

	rmdir($this->dir);
});

test('remove_spikes runs the dump/backup/restore round trip end to end and reports success', function () {
	$instance = spikekill_e2e_instance($this->rrdfile);

	$ok = $instance->remove_spikes();

	$backup = $this->backup_dir . '/source.rrd';

	expect($ok)->toBeTrue()
		->and($instance->get_errors())->toBe('')
		->and(file_exists($backup))->toBeTrue()
		->and(file_get_contents($backup))->toBe('original-rrd-bytes')
		->and(glob($this->backup_dir . '/spikekill.*.xml'))->toBe([]);

	unlink($backup);
});

test('remove_spikes refuses a source swapped after initialization before invoking the dump', function () {
	$instance = spikekill_e2e_instance($this->rrdfile);
	rename($this->rrdfile, $this->rrd_dir . '/original.rrd');
	symlink($this->rrd_dir . '/original.rrd', $this->rrdfile);
	$marker = $this->dir . '/dump-ran';
	file_put_contents($this->rrdtool_stub, "#!/bin/sh\ntouch " . escapeshellarg($marker) . "\nexit 0\n");

	expect($instance->remove_spikes())->toBeFalse()
		->and(file_exists($marker))->toBeFalse()
		->and(glob($this->backup_dir . '/*'))->toBe([]);
});

test('remove_spikes reports failure and cleans up the temp XML when the restore fails', function () {
	putenv('RESTORE_STUB_EXIT=1');

	$instance = spikekill_e2e_instance($this->rrdfile);

	$ok = $instance->remove_spikes();

	$backup = $this->backup_dir . '/source.rrd';

	expect($ok)->toBeFalse()
		->and($instance->get_errors())->toContain('Unable to restore')
		->and(implode(' ', $GLOBALS['spikekill_e2e_test_log']))->not->toContain('Removed ')
		/* the restore failing after a successful backup must not remove
		   the backup an admin would need to recover from it manually */
		->and(file_exists($backup))->toBeTrue()
		->and(glob($this->backup_dir . '/spikekill.*.xml'))->toBe([]);

	unlink($backup);
});

test('remove_spikes refuses an unreadable source and cleans up the temp XML', function () {
	if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
		$this->markTestSkipped('file permissions have no effect running as root');
	}

	/* write-only, no read: is_writable() (initialize_spikekill's
	   precondition) stays true, but backupRRDFile()'s copyFileSafely()
	   fails at fopen($source, 'rb') the same way a permissions error
	   would in production, without needing to touch the shared
	   backup/temp directory the dump step also depends on */
	chmod($this->rrdfile, 0222);

	$instance = spikekill_e2e_instance($this->rrdfile);

	/* copyFileSafely()'s fopen($source, 'rb') is not '@'-suppressed (only
	   its exclusive-create opens are), so the permission-denied open
	   below raises a real E_WARNING; swallow it the same way the
	   unwritable-directory test in SpikekillBackupSymlinkSafetyTest.php
	   does */
	set_error_handler(function () {
		return true;
	});

	$ok = $instance->remove_spikes();

	restore_error_handler();

	expect($ok)->toBeFalse()
		->and($instance->get_errors())->toContain('could not be verified safely')
		->and(glob($this->backup_dir . '/*'))->toBe([])
		->and(glob($this->backup_dir . '/spikekill.*.xml'))->toBe([]);
});

test('remove_spikes refuses a symlinked RRD source', function () {
	/* initialize_spikekill() previously checked the RRD path with
	   file_exists() and is_writable() alone, both of which follow a
	   symlink; a poller-writable path swapped for a link would have let
	   root dump, back up and restore through it */
	$real_rrdfile = $this->rrd_dir . '/real-source.rrd';
	rename($this->rrdfile, $real_rrdfile);
	symlink($real_rrdfile, $this->rrdfile);

	$instance = spikekill_e2e_instance($this->rrdfile);

	$ok = $instance->remove_spikes();

	expect($ok)->toBeFalse()
		->and($instance->get_errors())->toContain('is not a regular file')
		->and(glob($this->backup_dir . '/*'))->toBe([]);

	/* afterEach() globs and unlinks everything left under rrd_dir, which
	   covers both the symlink at the original name and real-source.rrd */
});

test('remove_spikes refuses when the RRD source is swapped for a different file during the dump', function ($dryrun) {
	/* the rrdtool 'dump' stub itself performs the swap, so it lands inside
	   the window between initialize_spikekill() capturing the source
	   identity and backupRRDFile()'s re-check of it, the same as an
	   attacker racing a real rrdtool dump would */
	$evil_target = $this->dir . '/evil-target.rrd';
	file_put_contents($evil_target, 'evil-bytes');

	$swap_stub = $this->dir . '/rrdtool-swap-stub.sh';
	file_put_contents($swap_stub, "#!/bin/sh\n" .
		"if [ \"\$1\" = 'dump' ]; then\n" .
		"  cat \"\$RRDTOOL_STUB_DUMP_FIXTURE\"\n" .
		"  rm -f \"\$2\"\n" .
		"  ln -s \"\$SPIKEKILL_TEST_EVIL_TARGET\" \"\$2\"\n" .
		"  exit 0\n" .
		"fi\n" .
		"if [ \"\$1\" = 'restore' ]; then\n" .
		"  exit 0\n" .
		"fi\n" .
		"exit 1\n");
	chmod($swap_stub, 0700);

	putenv('SPIKEKILL_TEST_EVIL_TARGET=' . $evil_target);

	spikekill_e2e_test_stub_config(array(
		'spikekill_backupdir' => $this->backup_dir,
		'path_rrdtool'        => $swap_stub,
	));

	$instance = spikekill_e2e_instance($this->rrdfile);

	$instance->dryrun = $dryrun;
    $ok = $instance->remove_spikes();

	putenv('SPIKEKILL_TEST_EVIL_TARGET');

	expect($ok)->toBeFalse()
		->and($instance->get_errors())->toContain('identity changed')
		->and(glob($this->backup_dir . '/*'))->toBe([]);

	/* afterEach() globs and unlinks everything left under rrd_dir (the
	   symlink the stub planted) and dir (evil_target, the swap stub) */
})->with(array(false, true));

test('remove_spikes refuses a requested backup when the RRD source is swapped during the dump', function () {
	/* a fixture with no outlier row: std_kills/out_kills/var_kills all
	   stay false, so updateXML()/backupRRDFile()/createRRDFileFromXML()
	   never run and the $this->backup-requested copyFileSafely() call at
	   the top of remove_spikes() is the only thing that can catch the
	   swap. That call is separate from, and runs before, the pre-restore
	   backupRRDFile() the swap test above already covers. */
	$rows = '';

	for ($i = 0; $i < 20; $i++) {
		$rows .= '<row><timestamp>' . (1000000000 + $i * 300) . '</timestamp><v>1.0000000000e+01</v></row>' . "\n";
	}

	$no_spike_fixture = $this->dir . '/dump-fixture-no-spike.xml';
	file_put_contents($no_spike_fixture,
		"<step>300</step>\n" .
		"<name>traffic_in</name>\n" .
		"<min>0</min>\n" .
		"<max>1000000</max>\n" .
		"<rra>\n" .
		"<cf>AVERAGE</cf>\n" .
		"<pdp_per_row>1</pdp_per_row>\n" .
		"<database>\n" .
		$rows .
		"</database>\n" .
		"</rra>\n"
	);

	$evil_target = $this->dir . '/evil-target-backup.rrd';
	file_put_contents($evil_target, 'evil-bytes');

	$swap_stub = $this->dir . '/rrdtool-swap-backup-stub.sh';
	file_put_contents($swap_stub, "#!/bin/sh\n" .
		"if [ \"\$1\" = 'dump' ]; then\n" .
		"  cat \"\$RRDTOOL_STUB_DUMP_FIXTURE\"\n" .
		"  rm -f \"\$2\"\n" .
		"  ln -s \"\$SPIKEKILL_TEST_EVIL_TARGET\" \"\$2\"\n" .
		"  exit 0\n" .
		"fi\n" .
		"if [ \"\$1\" = 'restore' ]; then\n" .
		"  exit 0\n" .
		"fi\n" .
		"exit 1\n");
	chmod($swap_stub, 0700);

	putenv('RRDTOOL_STUB_DUMP_FIXTURE=' . $no_spike_fixture);
	putenv('SPIKEKILL_TEST_EVIL_TARGET=' . $evil_target);

	spikekill_e2e_test_stub_config(array(
		'spikekill_backupdir' => $this->backup_dir,
		'path_rrdtool'        => $swap_stub,
	));

	$instance = spikekill_e2e_instance($this->rrdfile);
	$instance->backup = true;

	$ok = $instance->remove_spikes();

	putenv('SPIKEKILL_TEST_EVIL_TARGET');

	expect($ok)->toBeFalse()
		->and($instance->get_errors())->toContain('identity changed')
		->and(glob($this->backup_dir . '/*'))->toBe([]);

	/* afterEach() globs and unlinks everything left under rrd_dir (the
	   symlink the stub planted) and dir (evil_target, the swap stub) */
});

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($value, $decimals = 0) { return number_format($value, $decimals); }
}

test('requested snapshots survive successful processing while dry runs leave no files', function ($dryrun, $html) {
    $instance = spikekill_e2e_instance($this->rrdfile);
    $instance->backup = true;
    $instance->dryrun = $dryrun;
    $instance->html = $html;
    expect($instance->remove_spikes())->toBeTrue();
    $snapshots = glob($this->backup_dir . '/source.backup.*.rrd');
    expect($snapshots)->toHaveCount($dryrun ? 0 : 1)
        ->and(glob($this->backup_dir . '/*.xml'))->toBe(array());
    if (!$dryrun) {
        expect(file_get_contents($snapshots[0]))->toBe('original-rrd-bytes');
    } else {
        expect(glob($this->backup_dir . '/*'))->toBe(array());
    }
})->with(array(array(false, false), array(false, true), array(true, false), array(true, true)));

test('failed and empty dumps stop processing and remove transient XML files', function ($exit) {
    file_put_contents($this->rrdtool_stub, "#!/bin/sh\nexit " . $exit . "\n");
    $instance = spikekill_e2e_instance($this->rrdfile);
    expect($instance->remove_spikes())->toBeFalse()
        ->and(file_get_contents($this->rrdfile))->toBe('original-rrd-bytes')
        ->and(glob($this->backup_dir . '/*'))->toBe(array())
        ->and($instance->get_errors())->not->toBe('');
})->with(array(0, 1));

test('whitespace-only dumps fail before backup or restore', function () {
    file_put_contents($this->dump_fixture, " \n\t\n");
    $instance = spikekill_e2e_instance($this->rrdfile);
    $instance->backup = true;
    expect($instance->remove_spikes())->toBeFalse()
        ->and(file_get_contents($this->rrdfile))->toBe('original-rrd-bytes')
        ->and(glob($this->backup_dir . '/*'))->toBe(array());
});

test('restore diagnostics are escaped only in HTML output', function ($html) {
    $diagnostic = '<img src=x onerror=alert(1)> & "failure"';
    file_put_contents($this->rrdtool_stub, str_replace('stub restore failed', $diagnostic, file_get_contents($this->rrdtool_stub)));
    putenv('RESTORE_STUB_EXIT=1');
    $instance = spikekill_e2e_instance($this->rrdfile);
    $instance->html = $html;
    expect($instance->remove_spikes())->toBeFalse()
        ->and($instance->get_output())->toContain($html ? htmlspecialchars($diagnostic, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $diagnostic);
    if ($html) { expect($instance->get_output())->not->toContain('<img'); }
})->with(array(false, true));

test('restore never follows a live destination swapped during the command', function () {
    $outside = $this->dir . '/outside.rrd';
    file_put_contents($outside, 'unrelated bytes');
    $stub = file_get_contents($this->rrdtool_stub);
    $attack = 'rm -f ' . escapeshellarg($this->rrdfile) . '; ln -s ' . escapeshellarg($outside) . ' ' . escapeshellarg($this->rrdfile);
    $stub = str_replace('printf restored-rrd-bytes', $attack . "\nprintf restored-rrd-bytes", $stub);
    file_put_contents($this->rrdtool_stub, $stub);
    $instance = spikekill_e2e_instance($this->rrdfile);
    expect($instance->remove_spikes())->toBeFalse()
        ->and(file_get_contents($outside))->toBe('unrelated bytes')
        ->and(file_get_contents($this->backup_dir . '/source.rrd'))->toBe('original-rrd-bytes')
        ->and(glob($this->rrd_dir . '/*.xml'))->toBe(array());
});

test('partial or empty restore preserves the live RRD', function ($empty) {
    if ($empty) {
        file_put_contents($this->rrdtool_stub, str_replace('printf restored-rrd-bytes', 'printf ""', file_get_contents($this->rrdtool_stub)));
    } else { putenv('RESTORE_STUB_EXIT=1'); }
    $instance = spikekill_e2e_instance($this->rrdfile);
    expect($instance->remove_spikes())->toBeFalse()
        ->and(file_get_contents($this->rrdfile))->toBe('original-rrd-bytes')
        ->and(glob($this->rrd_dir . '/*.xml'))->toBe(array());
})->with(array(false, true));

test('atomic restore preserves RRD ownership and permissions', function () {
    chmod($this->rrdfile, 0640);
    $before = stat($this->rrdfile);
    $instance = spikekill_e2e_instance($this->rrdfile);
    expect($instance->remove_spikes())->toBeTrue();
    clearstatcache(true);
    $after = stat($this->rrdfile);
    expect($after['uid'])->toBe($before['uid'])->and($after['gid'])->toBe($before['gid'])
        ->and($after['mode'] & 0777)->toBe(0640)
        ->and(file_get_contents($this->rrdfile))->toBe('restored-rrd-bytes');
});


test('missing sample arrays preserve unavailable window statistics', function ($html) {
    $instance = spikekill_e2e_instance($this->rrdfile);
    $instance->html = $html;
    $class = new ReflectionClass(spikekill::class);
    foreach (array('rra_pdp' => array(1), 'rra_cf' => array('AVERAGE'), 'ds_name' => array('value')) as $name => $value) {
        $property = $class->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }
    $rra = array(array(array('totalsamples' => 0, 'numsamples' => 0)));
    $samples = array();
    $calculate = $class->getMethod('calculateOverallStatistics');
    $calculate->setAccessible(true);
    $calculate->invokeArgs($instance, array(&$rra, &$samples));
    expect($rra[0][0]['outwind_samples'])->toBe('N/A')
        ->and($rra[0][0]['outwind_killed'])->toBe('N/A');
    $output = $class->getMethod('outputStatistics');
    $output->setAccessible(true);
    $output->invoke($instance, $rra);
    $property = $class->getProperty('strout');
    $property->setAccessible(true);
    $text = $property->getValue($instance);
    if ($html) {
        preg_match_all('/<td[^>]*>(.*?)<\/td>/', $text, $matches);
        expect(array_slice($matches[1], -2))->toBe(array('N/A', 'N/A'));
    } else {
        expect($text)->toMatch('/N\/A\s+N\/A\s*$/');
    }
})->with(array(false, true));


test('spike removal refuses active writers without touching data and releases its lock on failure', function () {
    require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';
    $writer = rrd_maintenance_acquire();
    $instance = spikekill_e2e_instance($this->rrdfile);
    try {
        expect($instance->remove_spikes())->toBeFalse()
            ->and($instance->get_errors())->toContain('storage is busy')
            ->and(file_get_contents($this->rrdfile))->toBe('original-rrd-bytes')
            ->and(glob($this->backup_dir . '/*'))->toBe([]);
    } finally { rrd_maintenance_release($writer); }
    putenv('RESTORE_STUB_EXIT=1');
    $instance = spikekill_e2e_instance($this->rrdfile);
    expect($instance->remove_spikes())->toBeFalse();
    $exclusive = rrd_maintenance_acquire(true);
    try { expect(is_resource($exclusive))->toBeTrue(); }
    finally { rrd_maintenance_release($exclusive); }
});

test('spike removal refuses a cache daemon before dumping or changing the source', function () {
    $previous = getenv('RRDCACHED_ADDRESS');
    putenv('RRDCACHED_ADDRESS=unix:/unused/test.sock');
    try {
        $instance = spikekill_e2e_instance($this->rrdfile);
        expect($instance->remove_spikes())->toBeFalse()
            ->and($instance->get_errors())->toContain('RRDCACHED_ADDRESS')
            ->and(file_get_contents($this->rrdfile))->toBe('original-rrd-bytes')
            ->and(glob($this->backup_dir . '/*'))->toBe([]);
    } finally { putenv($previous === false ? 'RRDCACHED_ADDRESS' : 'RRDCACHED_ADDRESS=' . $previous); }
});

test('spike command deadlines use the documented bounded configuration', function ($configured, $expected) {
    $GLOBALS['__test_config_options']['spikekill_timeout'] = $configured;
    $instance = spikekill_e2e_instance($this->rrdfile);
    $method = new ReflectionMethod(spikekill::class, 'commandTimeout');
    $method->setAccessible(true);
    expect($method->invoke($instance))->toBe($expected);
})->with(array(array(0, 3600), array(-1, 3600), array(1, 1), array(28800, 28800), array(PHP_INT_MAX, 28800)));
