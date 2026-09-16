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
 *
 * runRRDCommand()'s 30 second default timeout is meant for a caller that
 * passes none; runRRDDump() and createRRDFileFromXML() now pass
 * commandTimeout(), which reads 'spikekill_timeout' (up to 8 hours,
 * default 1 hour), so a legitimate long-running dump or restore is no
 * longer capped at 30 seconds the way it was when both call sites left
 * the argument out entirely. 1.2.31 ran both through shell_exec() with no
 * deadline of its own; commandTimeout() is the closest equivalent that
 * still gives runRRDCommand()'s stream_select() loop a bound.
 */

require_once dirname(__DIR__, 3) . '/Helpers/SpikekillPathFunctions.php';

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';

/* read_config_option()/cacti_log()/__()/__esc() are guarded with
   function_exists() because PurgeSpikeBackupsWritableCheckTest.php,
   SpikekillBackupSymlinkSafetyTest.php and HeadersSecureTest.php also stub
   read_config_option() and all four files run in the same Pest process.
   Every one of those stubs reads and writes
   $GLOBALS['__test_config_options'], the store HeadersSecureTest.php
   already used, so whichever file's guarded definition wins the race still
   honors this file's stubbed values instead of silently falling back to
   another file's. */

function spikekill_xmldump_test_stub_config($values) {
	$GLOBALS['__test_config_options'] = $values;
}

if (!function_exists('read_config_option')) {
	function read_config_option($option) {
		return $GLOBALS['__test_config_options'][$option] ?? '';
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
		"  printf restored-rrd-bytes > \"\$5\"\n" .
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
		->and(dirname($result['path']))->toBe(realpath($this->dir))
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
function spikekill_xmldump_create_rrd_from_xml($xmlfile, $rrdfile, $stat, $rrdfile_stat = 'auto') {
	$reflection = new ReflectionClass('spikekill');
	$instance   = $reflection->newInstanceWithoutConstructor();

	/* createRRDFileFromXML() re-checks the restore destination against
	   $rrdfile_stat, the identity initialize_spikekill() would have
	   captured; production never reaches this method without $rrdfile
	   already existing (initialize_spikekill()'s file_exists() check), so
	   these tests create it too and prime the capture from its real
	   identity unless a test passes its own mismatched stat */
	if (!file_exists($rrdfile)) {
		file_put_contents($rrdfile, '');
	}

	$rrdfile_stat_prop = $reflection->getProperty('rrdfile_stat');
	$rrdfile_stat_prop->setAccessible(true);
	$rrdfile_stat_prop->setValue($instance, $rrdfile_stat === 'auto' ? lstat($rrdfile) : $rrdfile_stat);

	$m = $reflection->getMethod('createRRDFileFromXML');
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

test('createRRDFileFromXML refuses to restore when the restore destination changed identity', function () {
	spikekill_xmldump_test_stub_config(array('path_rrdtool' => $this->rrdtool_stub));

	$xmlfile = $this->dir . '/dump-target.xml';
	file_put_contents($xmlfile, '<xml/>');
	$stat = lstat($xmlfile);

	$rrdfile = $this->dir . '/target.rrd';
	file_put_contents($rrdfile, 'rrd-bytes');

	/* a stat taken from a different file, standing in for $rrdfile having
	   been swapped for a symlink or a different file since
	   initialize_spikekill() captured its identity */
	$other = $this->dir . '/other.rrd';
	file_put_contents($other, 'other-bytes');
	$other_stat = lstat($other);
	unlink($other);

	$result = spikekill_xmldump_create_rrd_from_xml($xmlfile, $rrdfile, $stat, $other_stat);

	unlink($xmlfile);
	unlink($rrdfile);

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

test('runRRDCommand kills a command that outlives its timeout instead of blocking forever', function () {
	/* proves the enforcement side of commandTimeout(): a genuinely wedged
	   rrdtool still gets killed, whatever timeout is in effect, rather
	   than the loop blocking indefinitely */
	$result = invoke_spikekill_xmldump_private('runRRDCommand', [
		[PHP_BINARY, '-r', 'sleep(3); exit(0);'],
		null,
		1,
	]);

	expect($result['exit'])->toBeFalse();
});

test('commandTimeout uses the configured spikekill_timeout instead of the 30 second runRRDCommand default', function () {
	/* 1.2.31 ran the dump/restore round trip through shell_exec() with no
	   deadline of its own; runRRDCommand()'s 30 second default (meant for
	   a caller that passes none) must not become an artificial ceiling on
	   a legitimate multi-hour dump/restore just because it happens to be
	   runRRDCommand()'s fallback */
	spikekill_xmldump_test_stub_config(array('spikekill_timeout' => '28800'));

	expect(invoke_spikekill_xmldump_private('commandTimeout', []))->toBe(28800);
});

test('commandTimeout falls back to one hour, not 30 seconds, when spikekill_timeout is not configured', function () {
	spikekill_xmldump_test_stub_config(array());

	expect(invoke_spikekill_xmldump_private('commandTimeout', []))->toBe(3600);
});

test('dump and restore enforce the configured command timeout', function ($operation) {
    file_put_contents($this->rrdtool_stub, "#!/bin/sh\nexec sleep 6\n");
    spikekill_xmldump_test_stub_config(array(
        'path_rrdtool' => $this->rrdtool_stub,
        'spikekill_timeout' => '1',
    ));
    $xmlfile = $this->dir . '/timeout.xml';
    $started = microtime(true);

    if ($operation === 'dump') {
        $handle = fopen($xmlfile, 'xb+');
        try {
            $ok = invoke_spikekill_xmldump_private('runRRDDump', [$this->dir . '/target.rrd', $handle]);
        } finally {
            fclose($handle);
        }
    } else {
        file_put_contents($xmlfile, '<xml/>');
        $result = spikekill_xmldump_create_rrd_from_xml($xmlfile, $this->dir . '/target.rrd', lstat($xmlfile));
        $ok = $result['ok'];
    }

    expect($ok)->toBeFalse()
        ->and(microtime(true) - $started)->toBeLessThan(4.0);
})->with(['dump', 'restore']);

test('writeXMLFile returns false when the handle is already closed', function () {
	$xmlfile = $this->dir . '/writexml-target.xml';
	$handle  = fopen($xmlfile, 'xb+');
	fclose($handle);

	$result = invoke_spikekill_xmldump_private('writeXMLFile', ['<xml/>', $handle]);

	expect($result)->toBeFalse();

	unlink($xmlfile);
});

/* PHP caches the last stat() and lstat() result per path, and on 8.3+
   clears that cache on any plain stream read, write or flush.  The swap
   below therefore runs in a child process with no pipes and is waited on
   with proc_get_status() alone, the same as an attacker's own process
   would act, so nothing in this process refreshes the cache before the
   method under test reads it. */
if (!function_exists('spikekill_swap_externally')) {
	function spikekill_swap_externally($script) {
		$process = proc_open(array('sh', '-c', $script), array(), $pipes);

		do {
			usleep(10000);
			$status = proc_get_status($process);
		} while ($status['running']);

		return array('process' => $process, 'exit' => $status['exitcode']);
	}
}

test('createRRDFileFromXML does not trust a cached lstat from before the xml file was swapped', function () {
	spikekill_xmldump_test_stub_config(array('path_rrdtool' => $this->rrdtool_stub));

	$xmlfile = $this->dir . '/dump-target.xml';
	file_put_contents($xmlfile, '<xml/>');

	$replacement = $this->dir . '/replacement.xml';
	file_put_contents($replacement, '<xml>planted</xml>');

	$stat = lstat($xmlfile);

	$swap = spikekill_swap_externally('mv ' . escapeshellarg($replacement) . ' ' . escapeshellarg($xmlfile));

	$result = spikekill_xmldump_create_rrd_from_xml($xmlfile, $this->dir . '/target.rrd', $stat);

	proc_close($swap['process']);

	unlink($xmlfile);

	expect($swap['exit'])->toBe(0)
		->and($result['ok'])->toBeFalse();
});

test('createXmlFileExclusively does not trust a cached is_link() from before the tempdir was swapped for a symlink', function () {
	$tempdir = $this->dir . '/tempdir';
	mkdir($tempdir, 0700);

	$elsewhere = $this->dir . '/elsewhere';
	mkdir($elsewhere, 0700);

	is_link($tempdir);

	$swap = spikekill_swap_externally('rmdir ' . escapeshellarg($tempdir) . ' && ln -s ' . escapeshellarg($elsewhere) . ' ' . escapeshellarg($tempdir));

	$info = invoke_spikekill_xmldump_private('createXmlFileExclusively', [$tempdir]);

	proc_close($swap['process']);

	if (is_array($info)) {
		fclose($info['handle']);
	}

	$leaked = glob($elsewhere . '/*');

	array_map('unlink', $leaked);
	unlink($tempdir);
	rmdir($elsewhere);

	expect($swap['exit'])->toBe(0)
		->and($info)->toBeFalse()
		->and($leaked)->toBe([]);
});
