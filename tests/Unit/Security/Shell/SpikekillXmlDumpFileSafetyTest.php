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
 * remove_spikes() runs as root and used to dump an RRD's XML into a
 * predictably-named file under $this->tempdir (a directory the poller
 * user's web process can write to) with shell '>' redirection, then
 * later rewrote that same name with file_put_contents(). A symlink
 * planted at that name between the dump and the rewrite would have
 * redirected either write.
 *
 * createXmlFileExclusively() replaces the predictable name with an
 * exclusively-created random one, refusing a symlinked tempdir the same
 * way copyFileSafely() refuses one for the RRD backup. runRRDDump() and
 * createRRDFileFromXML() both run rrdtool through runRRDCommand(), which
 * replaces a shell redirection or shell_exec() with proc_open(): the dump
 * writes straight into the held handle instead of reopening the file by
 * name, and the restore's exit status is no longer hidden behind
 * shell_exec(). runRRDCommand() drains stdout/stderr with stream_select()
 * so reading one pipe to completion can never stall on the other filling
 * up.
 */

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';

/* read_config_option()/cacti_log()/__()/__esc() are guarded with
   function_exists() because PurgeSpikeBackupsWritableCheckTest.php stubs
   read_config_option()/cacti_log() and both files run in the same Pest
   process. */

function spikekill_xmldump_test_stub_config($values) {
	global $spikekill_shell_test_config;

	$spikekill_shell_test_config = $values;
}

if (!function_exists('read_config_option')) {
	function read_config_option($option) {
		global $spikekill_shell_test_config;

		return $spikekill_shell_test_config[$option] ?? '';
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $output = false, $environ = 'SPIKEKILL') {
		global $spikekill_shell_test_log;

		$spikekill_shell_test_log[] = $message;
	}
}

if (!function_exists('__esc')) {
	function __esc($format) {
		$args = func_get_args();
		array_shift($args);

		return htmlspecialchars($args ? vsprintf($format, $args) : $format, ENT_QUOTES);
	}
}

function invoke_spikekill_xmldump_private(string $method, array $args) {
	$reflection = new ReflectionClass('spikekill');
	$instance   = $reflection->newInstanceWithoutConstructor();
	$m          = $reflection->getMethod($method);
	$m->setAccessible(true);

	return $m->invokeArgs($instance, $args);
}

beforeEach(function () {
	global $spikekill_shell_test_log;

	$spikekill_shell_test_log = array();

	$this->dir = sys_get_temp_dir() . '/spikekill_xmldump_test_' . uniqid();
	mkdir($this->dir, 0700, true);

	/* a stub 'rrdtool' whose 'dump' subcommand writes fixed content to
	   stdout and exits per RRDTOOL_STUB_EXIT, and whose 'restore'
	   subcommand exits per RESTORE_STUB_EXIT after optionally writing
	   RESTORE_STUB_STDOUT_BYTES/RESTORE_STUB_STDERR_BYTES bytes to stdout
	   and stderr, so runRRDDump() and createRRDFileFromXML() are both
	   exercised against a real child process without a real rrdtool
	   binary or a real RRD file */
	$this->rrdtool_stub = $this->dir . '/rrdtool-stub.sh';
	file_put_contents($this->rrdtool_stub, "#!/bin/sh\n" .
		"if [ \"\$1\" = 'dump' ]; then\n" .
		"  if [ \"\$RRDTOOL_STUB_EXIT\" != '0' ] && [ -n \"\$RRDTOOL_STUB_EXIT\" ]; then\n" .
		"    echo 'stub dump failed' >&2\n" .
		"    exit \"\$RRDTOOL_STUB_EXIT\"\n" .
		"  fi\n" .
		"  printf '<xml>dumped-content</xml>'\n" .
		"  exit 0\n" .
		"fi\n" .
		"if [ \"\$1\" = 'restore' ]; then\n" .
		"  if [ -n \"\$RESTORE_STUB_STDOUT_BYTES\" ]; then\n" .
		"    head -c \"\$RESTORE_STUB_STDOUT_BYTES\" /dev/zero | tr '\\0' 'o'\n" .
		"  fi\n" .
		"  if [ -n \"\$RESTORE_STUB_STDERR_BYTES\" ]; then\n" .
		"    head -c \"\$RESTORE_STUB_STDERR_BYTES\" /dev/zero | tr '\\0' 'e' >&2\n" .
		"  fi\n" .
		"  restore_exit=\"\${RESTORE_STUB_EXIT:-0}\"\n" .
		"  if [ \"\$restore_exit\" != '0' ]; then\n" .
		"    echo 'stub restore failed' >&2\n" .
		"  fi\n" .
		"  exit \"\$restore_exit\"\n" .
		"fi\n" .
		"exit 1\n");
	chmod($this->rrdtool_stub, 0700);

	putenv('RRDTOOL_STUB_EXIT=0');
	putenv('RESTORE_STUB_EXIT=0');
});

afterEach(function () {
	putenv('RRDTOOL_STUB_EXIT');
	putenv('RESTORE_STUB_EXIT');
	putenv('RESTORE_STUB_STDOUT_BYTES');
	putenv('RESTORE_STUB_STDERR_BYTES');

	foreach (glob($this->dir . '/*') as $item) {
		is_link($item) ? unlink($item) : unlink($item);
	}

	rmdir($this->dir);
});

test('a random-named file is created exclusively in the tempdir', function () {
	$result = invoke_spikekill_xmldump_private('createXmlFileExclusively', [$this->dir]);

	expect($result)->toBeArray()
		->and(dirname($result['path']))->toBe($this->dir)
		->and(basename($result['path']))->not->toBe(basename($this->dir))
		->and(file_exists($result['path']))->toBeTrue()
		->and(is_resource($result['handle']))->toBeTrue()
		->and(fileperms($result['path']) & 0777)->toBe(0600);

	fclose($result['handle']);
	unlink($result['path']);
});

test('two calls in the same directory never collide on the same name', function () {
	$first  = invoke_spikekill_xmldump_private('createXmlFileExclusively', [$this->dir]);
	$second = invoke_spikekill_xmldump_private('createXmlFileExclusively', [$this->dir]);

	expect($first['path'])->not->toBe($second['path']);

	fclose($first['handle']);
	fclose($second['handle']);
	unlink($first['path']);
	unlink($second['path']);
});

test('a symlinked tempdir is refused and nothing is created through it', function () {
	$real_dir = $this->dir . '/real-tempdir';
	mkdir($real_dir, 0700, true);

	$link = $this->dir . '/tempdir-link';
	symlink($real_dir, $link);

	$result = invoke_spikekill_xmldump_private('createXmlFileExclusively', [$link]);

	expect($result)->toBeFalse()
		->and(glob($real_dir . '/*'))->toBe([]);

	unlink($link);
	rmdir($real_dir);
});

test('runRRDDump writes rrdtool stdout into the held handle and reports success', function () {
	spikekill_xmldump_test_stub_config(array('path_rrdtool' => $this->rrdtool_stub));

	$xmlfile = $this->dir . '/dump-target.xml';
	$handle  = fopen($xmlfile, 'xb+');

	$ok = invoke_spikekill_xmldump_private('runRRDDump', ['/does/not/matter.rrd', $handle]);

	rewind($handle);
	$written = stream_get_contents($handle);
	fclose($handle);

	expect($ok)->toBeTrue()
		->and($written)->toBe('<xml>dumped-content</xml>');

	unlink($xmlfile);
});

test('runRRDDump reports failure when rrdtool exits non-zero', function () {
	putenv('RRDTOOL_STUB_EXIT=1');

	spikekill_xmldump_test_stub_config(array('path_rrdtool' => $this->rrdtool_stub));

	$xmlfile = $this->dir . '/dump-target.xml';
	$handle  = fopen($xmlfile, 'xb+');

	$ok = invoke_spikekill_xmldump_private('runRRDDump', ['/does/not/matter.rrd', $handle]);

	fclose($handle);
	unlink($xmlfile);

	expect($ok)->toBeFalse();
});

/* createRRDFileFromXML() runs 'rrdtool restore'; these tests create a real
   instance (rather than the shared helper) so the private $strout property
   the method writes to can be read back with reflection afterward */
function spikekill_xmldump_create_rrd_from_xml($xmlfile, $rrdfile, $stat) {
	$reflection = new ReflectionClass('spikekill');
	$instance   = $reflection->newInstanceWithoutConstructor();
	$m          = $reflection->getMethod('createRRDFileFromXML');
	$m->setAccessible(true);

	$ok = $m->invokeArgs($instance, [$xmlfile, $rrdfile, $stat]);

	$strout_prop = $reflection->getProperty('strout');
	$strout_prop->setAccessible(true);

	return array('ok' => $ok, 'strout' => $strout_prop->getValue($instance));
}

test('createRRDFileFromXML refuses to restore when the xml file changed identity', function () {
	spikekill_xmldump_test_stub_config(array('path_rrdtool' => $this->rrdtool_stub));

	$xmlfile = $this->dir . '/dump-target.xml';
	file_put_contents($xmlfile, '<xml/>');

	/* a stat taken from a different file, standing in for the file at
	   $xmlfile having been swapped out from under the held stat since it
	   was written */
	$other = $this->dir . '/other.xml';
	file_put_contents($other, '<xml/>');
	$stat = lstat($other);
	unlink($other);

	$result = spikekill_xmldump_create_rrd_from_xml($xmlfile, $this->dir . '/target.rrd', $stat);

	unlink($xmlfile);

	expect($result['ok'])->toBeFalse();
});

test('createRRDFileFromXML reports failure and surfaces stderr when rrdtool restore exits non-zero', function () {
	putenv('RESTORE_STUB_EXIT=3');

	spikekill_xmldump_test_stub_config(array('path_rrdtool' => $this->rrdtool_stub));

	$xmlfile = $this->dir . '/dump-target.xml';
	file_put_contents($xmlfile, '<xml/>');
	$stat = lstat($xmlfile);

	$result = spikekill_xmldump_create_rrd_from_xml($xmlfile, $this->dir . '/target.rrd', $stat);

	unlink($xmlfile);

	expect($result['ok'])->toBeFalse()
		->and($result['strout'])->toContain('stub restore failed');
});

test('createRRDFileFromXML succeeds and reports rrdtool output when restore exits zero', function () {
	spikekill_xmldump_test_stub_config(array('path_rrdtool' => $this->rrdtool_stub));

	$xmlfile = $this->dir . '/dump-target.xml';
	file_put_contents($xmlfile, '<xml/>');
	$stat = lstat($xmlfile);

	$result = spikekill_xmldump_create_rrd_from_xml($xmlfile, $this->dir . '/target.rrd', $stat);

	unlink($xmlfile);

	expect($result['ok'])->toBeTrue();
});

test('createRRDFileFromXML drains large concurrent stdout and stderr without deadlocking', function () {
	putenv('RESTORE_STUB_STDOUT_BYTES=300000');
	putenv('RESTORE_STUB_STDERR_BYTES=300000');

	spikekill_xmldump_test_stub_config(array('path_rrdtool' => $this->rrdtool_stub));

	$xmlfile = $this->dir . '/dump-target.xml';
	file_put_contents($xmlfile, '<xml/>');
	$stat = lstat($xmlfile);

	$result = spikekill_xmldump_create_rrd_from_xml($xmlfile, $this->dir . '/target.rrd', $stat);

	unlink($xmlfile);

	/* both pipes exceed the typical 64KB OS pipe buffer; reaching this
	   assertion at all (rather than hanging) is the deadlock regression
	   guard, and the combined length confirms neither pipe was truncated */
	expect($result['ok'])->toBeTrue()
		->and(strlen($result['strout']))->toBeGreaterThanOrEqual(600000);
});

test('runRRDCommand drains a real child process and returns its exit code without warnings', function () {
	/* exercises stream_select() with the default 30-second timeout
	   against a real proc_open(), not the rrdtool stub, so a bad
	   argument to stream_select() (its microseconds parameter must
	   stay under 1,000,000, unlike the seconds/microseconds split this
	   file's other tests pass through the stub) would surface here as
	   a warning under Pest's error handler rather than a silent pass */
	$result = invoke_spikekill_xmldump_private('runRRDCommand', [
		[PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(7);'],
		null,
	]);

	expect($result['exit'])->toBe(7)
		->and($result['stdout'])->toBe('out')
		->and($result['stderr'])->toBe('err');
});

/* remove_spikes() only reaches its restore/backup/write-XML tail once a
   real rrdtool dump has been parsed into statistics that trip std_kills,
   out_kills or var_kills, which needs read_user_setting(), cacti_sizeof(),
   number_format_i18n() and friends beyond what this file already stubs.
   Driving that end to end is disproportionate to the bug: remove_spikes()
   returning true past a failure was a control-flow mistake in how it read
   the return value of writeXMLFile() and backupRRDFile(), not a bug in
   either method itself. writeXMLFile() failing here, backupRRDFile()
   failing in SpikekillBackupSymlinkSafetyTest.php, and
   createRRDFileFromXML() failing above are the same three false returns
   remove_spikes() now checks. */

test('writeXMLFile returns false when the handle is already closed', function () {
	$xmlfile = $this->dir . '/writexml-target.xml';
	$handle  = fopen($xmlfile, 'xb+');
	fclose($handle);

	$result = invoke_spikekill_xmldump_private('writeXMLFile', ['<xml/>', $handle]);

	expect($result)->toBeFalse();

	unlink($xmlfile);
});
