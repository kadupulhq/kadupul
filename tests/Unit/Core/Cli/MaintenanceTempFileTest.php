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
