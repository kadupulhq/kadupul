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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * splice_rrd.php and float_rrdfiles.php write their 1.2.31 names in the
 * shared temporary directory again. The names are predictable, so every file
 * is created exclusively: a planted symlink or an existing file is refused
 * and its target is left untouched.
 */

require_once __DIR__ . '/../../../../lib/maintenance_cli.php';

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/cacti_cli_tmp_' . getmypid() . '_' . mt_rand();

	mkdir($this->dir, 0700);

	$this->victim = $this->dir . '/victim';

	file_put_contents($this->victim, 'original');
});

afterEach(function () {
	foreach (array_merge(glob($this->dir . '/*') ?: array(), glob($this->dir . '/.*') ?: array()) as $file) {
		if (is_link($file) || is_file($file)) {
			unlink($file);
		}
	}

	rmdir($this->dir);
});

test('a free 1.2.31 name is created and written', function () {
	$path   = $this->dir . '/new.dump.12345';
	$handle = cacti_cli_create_file($path);

	expect($handle)->toBeResource();

	fwrite($handle, 'xml');
	fclose($handle);

	expect(file_get_contents($path))->toBe('xml');
});

test('a symlink planted at the name is refused and its target is untouched', function () {
	$path = $this->dir . '/new.dump.12345';

	symlink($this->victim, $path);

	expect(cacti_cli_create_file($path))->toBe("Refusing to write '$path' because it is a symbolic link")
		->and(file_get_contents($this->victim))->toBe('original');
});

test('a dangling symlink is refused and nothing is created at its target', function () {
	$path   = $this->dir . '/42.xml';
	$target = $this->dir . '/created-through-link';

	symlink($target, $path);

	expect(cacti_cli_create_file($path))->toContain('because it is a symbolic link')
		->and(file_exists($target))->toBeFalse();
});

test('an existing file is refused with a clear message and kept as it is', function () {
	expect(cacti_cli_create_file($this->victim))->toBe("Refusing to overwrite existing file '{$this->victim}'")
		->and(file_get_contents($this->victim))->toBe('original');
});

test('the debug log is created when missing and appended when it is ours', function () {
	$path = $this->dir . '/clearer.log';

	$first = cacti_cli_open_log($path);
	fwrite($first, "one\n");
	fclose($first);

	$second = cacti_cli_open_log($path);
	fwrite($second, "two\n");
	fclose($second);

	expect(file_get_contents($path))->toBe("one\ntwo\n");
});

test('the debug log refuses a symlink and a directory', function () {
	$link = $this->dir . '/clearer.log';

	symlink($this->victim, $link);

	expect(cacti_cli_open_log($link))->toBeString()
		->and(file_get_contents($this->victim))->toBe('original')
		->and(cacti_cli_open_log($this->dir))->toBe("Refusing to append to '{$this->dir}' because it is not a regular file");
});

test('created files are owner-only under a 022 umask, which is restored afterwards', function () {
	$previous = umask(022);

	try {
		$path   = $this->dir . '/new.dump.12345';
		$handle = cacti_cli_create_file($path);

		expect($handle)->toBeResource()
			->and(umask())->toBe(022);

		fclose($handle);

		$log = cacti_cli_open_log($this->dir . '/clearer.log');

		expect($log)->toBeResource()
			->and(umask())->toBe(022);

		fclose($log);
		clearstatcache();

		expect(fileperms($path) & 0777)->toBe(0600)
			->and(fileperms($this->dir . '/clearer.log') & 0777)->toBe(0600);
	} finally {
		umask($previous);
	}
});

test('the umask is restored when a name is refused or cannot be created', function () {
	$previous = umask(022);

	try {
		$link = $this->dir . '/new.dump.12345';

		symlink($this->victim, $link);

		expect(cacti_cli_create_file($link))->toBeString()
			->and(umask())->toBe(022)
			->and(cacti_cli_create_file($this->dir . '/missing/new.dump.1'))->toBe("Unable to create '{$this->dir}/missing/new.dump.1'")
			->and(umask())->toBe(022);
	} finally {
		umask($previous);
	}
});

test('an existing debug log keeps its mode', function () {
	$path = $this->dir . '/clearer.log';

	file_put_contents($path, "one\n");
	chmod($path, 0644);

	$log = cacti_cli_open_log($path);

	expect($log)->toBeResource();

	fwrite($log, "two\n");
	fclose($log);
	clearstatcache();

	expect(fileperms($path) & 0777)->toBe(0644)
		->and(file_get_contents($path))->toBe("one\ntwo\n");
});

test('temporary files and the debug log keep binary data byte for byte', function () {
	$bytes  = "RRD\x00\r\n\x1a\n\xff";
	$path   = $this->dir . '/backup.rrd.1';
	$handle = cacti_cli_create_file($path);

	fwrite($handle, $bytes);
	fclose($handle);

	$log = cacti_cli_open_log($path . '.log');
	fwrite($log, $bytes);
	fclose($log);

	$log = cacti_cli_open_log($path . '.log');
	fwrite($log, $bytes);
	fclose($log);

	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/maintenance_cli.php');

	expect(file_get_contents($path))->toBe($bytes)
		->and(file_get_contents($path . '.log'))->toBe($bytes . $bytes)
		->and($source)->toContain("@fopen(\$path, 'xb')")
		->and($source)->toContain("@fopen(\$path, 'ab')")
		->and($source)->not->toMatch("/fopen\\(\\\$path, '[xa]'\\)/");
});

test('a created file is removed by name while the name still refers to it', function () {
	$path   = $this->dir . '/42.xml';
	$handle = cacti_cli_create_file($path);

	expect(cacti_cli_remove_file($handle, $path))->toBeTrue()
		->and(is_resource($handle))->toBeFalse()
		->and(file_exists($path))->toBeFalse();
});

test('a name swapped for a symlink after the create is not removed and its target is untouched', function () {
	$path   = $this->dir . '/42.xml';
	$handle = cacti_cli_create_file($path);

	unlink($path);
	symlink($this->victim, $path);

	expect(cacti_cli_remove_file($handle, $path))->toBeFalse()
		->and(is_link($path))->toBeTrue()
		->and(file_get_contents($this->victim))->toBe('original');
});

test('a name swapped for another regular file after the create is not removed', function () {
	$path   = $this->dir . '/42.xml';
	$handle = cacti_cli_create_file($path);

	unlink($path);
	file_put_contents($path, 'someone else');

	expect(cacti_cli_remove_file($handle, $path))->toBeFalse()
		->and(file_get_contents($path))->toBe('someone else');
});

test('the create helper never changes or removes a file by name', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/maintenance_cli.php');

	preg_match('/^function cacti_cli_create_file\(.*?^}$/ms', $source, $body);

	expect($body)->toHaveKey(0);

	$code = preg_replace('#/\*.*?\*/#s', '', $body[0]);

	expect($code)->not->toContain('chmod(')
		->and($code)->not->toContain('unlink(')
		->and($code)->toContain('cacti_cli_remove_file($handle, $path);');
});

test('splice_rrd uses the 1.2.31 names and creates every temporary file exclusively', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/splice_rrd.php');

	expect($source)->toContain("\$oldxmlfile = '/tmp/' . str_replace('.rrd', '', basename(\$oldrrd)) . '.dump.' . \$seed;")
		->and($source)->toContain("\$newxmlfile = '/tmp/' . str_replace('.rrd', '', basename(\$newrrd)) . '.dump.' . \$seed;")
		->and($source)->toContain("\$newfile = basename(\$rrdfile) . '.' . \$seed;")
		->and(substr_count($source, 'cacti_cli_create_file('))->toBe(3)
		->and($source)->not->toContain("tempnam(sys_get_temp_dir(), 'cacti_splice_')")
		->and($source)->not->toContain('file_put_contents($newxmlfile')
		->and($source)->not->toContain('return copy(');
});
