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
 * way copyFileSafely() refuses one for the RRD backup. runRRDDump()
 * replaces the shell redirection with proc_open(), writing rrdtool's
 * stdout straight into the held handle instead of reopening the file by
 * name.
 */

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';

/* read_config_option()/cacti_log() are guarded with function_exists()
   because PurgeSpikeBackupsWritableCheckTest.php stubs the same globals
   and both files run in the same Pest process. */

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
	   stdout and exits per RRDTOOL_STUB_EXIT, so runRRDDump() is
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
		"exit 1\n");
	chmod($this->rrdtool_stub, 0700);

	putenv('RRDTOOL_STUB_EXIT=0');
});

afterEach(function () {
	putenv('RRDTOOL_STUB_EXIT');

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
