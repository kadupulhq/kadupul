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

namespace Kadupul\Tests\StructureRraRecursiveOwnership;

/*
 * cli/structure_rra_paths.php runs sp_recursive_chown() and
 * sp_recursive_chgrp() as root to take ownership of a newly created RRA
 * directory. The prior code called glob($path . '/*') and recursed into
 * whatever it found before ever checking whether $path itself was a
 * symlink, so a symlinked directory's real contents were chown()/chgrp()'d
 * as root instead of being refused.
 *
 * These tests eval the extracted functions into this file's own namespace
 * and define spy versions of glob(), chown(), lchown(), chgrp() and
 * lchgrp() here too. PHP resolves an unqualified function call from inside
 * a namespace against that namespace first, falling back to the global
 * builtin only if no such function is defined here, so the extracted code
 * calls these spies instead of touching the filesystem's ownership. That
 * lets the call ordering be verified directly, without needing root or
 * group-membership privileges chown()/chgrp() would otherwise require.
 *
 * eval() here runs a byte-for-byte function body copied out of the shipped
 * source file above, immediately before this comment, not any external or
 * previously-written data, so this is the same reasoning as the existing
 * source-extraction tests in this suite (see StructureRraPathsSourceSafetyTest
 * and SpikekillBackupSymlinkSafetyTest).
 *
 * eval()'d code always compiles in the global namespace regardless of the
 * namespace declared in the surrounding file, so the namespace this file
 * uses is declared again inside each eval() string; that is what makes the
 * extracted functions' own unqualified calls resolve against the spies
 * below instead of the real filesystem builtins.
 */

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/structure_rra_paths.php');

$namespace_decl = "namespace Kadupul\\Tests\\StructureRraRecursiveOwnership;\n";

foreach (array('sp_recursive_chown', 'sp_recursive_chgrp') as $function) {
	$start = strpos($source, "function $function(");
	expect($start)->not->toBeFalse();

	$end = strpos($source, "\n}\n", $start);
	$body = substr($source, $start, $end - $start + 2);

	eval($namespace_decl . $body); // nosemgrep: php.lang.security.eval-use.eval-use
}

function reset_calls() {
	$GLOBALS['structure_rra_ownership_calls'] = array();
}

function calls() {
	return $GLOBALS['structure_rra_ownership_calls'];
}

function glob($pattern) {
	$GLOBALS['structure_rra_ownership_calls'][] = array('glob', $pattern);

	return \glob($pattern);
}

function chown($path, $user) {
	$GLOBALS['structure_rra_ownership_calls'][] = array('chown', $path);

	return true;
}

function lchown($path, $user) {
	$GLOBALS['structure_rra_ownership_calls'][] = array('lchown', $path);

	return true;
}

function chgrp($path, $group) {
	$GLOBALS['structure_rra_ownership_calls'][] = array('chgrp', $path);

	return true;
}

function lchgrp($path, $group) {
	$GLOBALS['structure_rra_ownership_calls'][] = array('lchgrp', $path);

	return true;
}

beforeEach(function () {
	reset_calls();

	$this->base = sys_get_temp_dir() . '/structure_rra_ownership_test_' . uniqid();
	mkdir($this->base, 0700, true);
});

afterEach(function () {
	foreach (\glob($this->base . '/*') as $item) {
		if (is_link($item) || !is_dir($item)) {
			unlink($item);
		} else {
			rmdir($item);
		}
	}

	rmdir($this->base);
});

test('sp_recursive_chown refuses a symlinked path without globbing into its contents', function () {
	$real = $this->base . '/real-target';
	mkdir($real, 0700, true);
	file_put_contents($real . '/canary', 'do-not-touch');

	$link = $this->base . '/link';
	symlink($real, $link);

	sp_recursive_chown($link, 1000);

	expect(calls())->toBe(array(array('lchown', $link)));

	unlink($real . '/canary');
	rmdir($real);
	unlink($link);
});

test('sp_recursive_chgrp refuses a symlinked path without globbing into its contents', function () {
	$real = $this->base . '/real-target';
	mkdir($real, 0700, true);
	file_put_contents($real . '/canary', 'do-not-touch');

	$link = $this->base . '/link';
	symlink($real, $link);

	sp_recursive_chgrp($link, 1000);

	expect(calls())->toBe(array(array('lchgrp', $link)));

	unlink($real . '/canary');
	rmdir($real);
	unlink($link);
});

test('sp_recursive_chown still recurses into a real directory and reaches its contents', function () {
	$dir = $this->base . '/real';
	mkdir($dir, 0700, true);
	file_put_contents($dir . '/item', 'contents');

	sp_recursive_chown($dir, 1000);

	expect(calls())->toBe(array(
		array('glob', $dir . '/*'),
		array('lchown', $dir . '/item'),
	));

	unlink($dir . '/item');
	rmdir($dir);
});

test('sp_recursive_chgrp still recurses into a real directory and reaches its contents', function () {
	$dir = $this->base . '/real';
	mkdir($dir, 0700, true);
	file_put_contents($dir . '/item', 'contents');

	sp_recursive_chgrp($dir, 1000);

	expect(calls())->toBe(array(
		array('glob', $dir . '/*'),
		array('lchgrp', $dir . '/item'),
	));

	unlink($dir . '/item');
	rmdir($dir);
});

test('sp_recursive_chown uses lchown on a regular file passed directly', function () {
	$file = $this->base . '/ds.rrd';
	file_put_contents($file, 'rrd');

	sp_recursive_chown($file, 1000);

	expect(calls())->toBe(array(array('lchown', $file)));
});

test('sp_recursive_chgrp uses lchgrp on a regular file passed directly', function () {
	$file = $this->base . '/ds.rrd';
	file_put_contents($file, 'rrd');

	sp_recursive_chgrp($file, 1000);

	expect(calls())->toBe(array(array('lchgrp', $file)));
});

test('sp_recursive_chown uses lchown on a symlink found inside a real directory', function () {
	$dir = $this->base . '/real';
	mkdir($dir, 0700, true);
	symlink($this->base . '/elsewhere', $dir . '/item');

	sp_recursive_chown($dir, 1000);

	expect(calls())->toBe(array(
		array('glob', $dir . '/*'),
		array('lchown', $dir . '/item'),
	));

	unlink($dir . '/item');
	rmdir($dir);
});
