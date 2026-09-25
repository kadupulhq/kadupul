<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Both RRD create paths guarded against overwriting an existing file with
 * file_exists(). That call follows a symbolic link, so neither answer it gives
 * refuses one. A dangling link reported false, the guard passed, rrdtool created
 * the file the link named, and the chown/chgrp that follow under a root poller
 * followed it too. A link whose target was already there reported true, the
 * guard returned -1, and the caller read that as "the file exists" and wrote its
 * update through the link. The refusal therefore has to come before the
 * existence test, not after it.
 *
 * data_source_path is stored from the request under '^[^\r\n]*$'
 * (data_sources.php:245) and rrd_check_path() deliberately does not confine a
 * path to the RRA directory, which is a 1.2.31 compatibility requirement. A
 * file at a custom location is therefore still created; what changes here is
 * that the create refuses a link and that ownership is only applied, with
 * lchown/lchgrp, to a file inside the configured RRA directory.
 *
 * Scope worth stating plainly: lchown narrows the swapped-link window rather
 * than closing it, PHP has no fchown so the check and the call cannot share a
 * descriptor, and a link planted between the pre-create check and rrdtool's
 * own open may still redirect the write. The containment test is what removes
 * the ownership escalation outright.
 */

namespace RrdCreateSymlinkRefusalTest;

it('confirms file_exists is false for a dangling symlink', function () {
	$dir = sys_get_temp_dir() . '/rrd-symlink-' . getmypid() . '-' . mt_rand();

	mkdir($dir, 0700, true);

	try {
		$link   = $dir . '/data.rrd';
		$target = $dir . '/absent-target';

		expect(symlink($target, $link))->toBeTrue();

		// The whole basis of the defect: the guard saw nothing here.
		expect(file_exists($link))->toBeFalse();
		expect(is_link($link))->toBeTrue();

		// And a write through it lands on the target, which is what rrdtool did.
		file_put_contents($link, 'x');

		expect(file_exists($target))->toBeTrue();
		expect(is_link($link))->toBeTrue();

		unlink($target);
		unlink($link);
	} finally {
		@rmdir($dir);
	}
});

it('confirms file_exists is true for a symlink whose target is present', function () {
	$dir = sys_get_temp_dir() . '/rrd-livelink-' . getmypid() . '-' . mt_rand();

	mkdir($dir, 0700, true);

	try {
		$link   = $dir . '/data.rrd';
		$target = $dir . '/present-target';

		file_put_contents($target, 'x');

		expect(symlink($target, $link))->toBeTrue();

		// The other half of the defect. A guard that reads this as "the file is
		// already there" returns -1, and the caller then updates through the
		// link, so ordering the link test after the existence test is not enough.
		expect(file_exists($link))->toBeTrue();
		expect(is_link($link))->toBeTrue();

		file_put_contents($link, 'y');

		expect(file_get_contents($target))->toBe('y');

		unlink($link);
		unlink($target);
	} finally {
		@rmdir($dir);
	}
});

it('tests for a link before it trusts an existence result', function () {
	require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

	$creators = array(
		'lib/rrd.php'   => 'rrdtool_function_create',
		'lib/boost.php' => 'boost_rrdtool_function_create',
	);

	foreach ($creators as $file => $function) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($source)->not->toBeFalse();

		$body = \test_php_function_source($source, $function);
		$link = strpos($body, 'is_link($data_source_path)');

		expect($link)->not->toBeFalse();

		// Both the local call and the proxy verb follow the link, so the refusal
		// has to come before either of them rather than in the same chain.
		foreach (array('file_exists($data_source_path)', "rrdtool_execute_path_command('file_exists'") as $existence) {
			$at = strpos($body, $existence);

			expect($at)->not->toBeFalse();
			expect($link)->toBeLessThan($at);
		}
	}
});

it('refuses the create in both paths when the target is a link', function () {
	foreach (array('lib/rrd.php', 'lib/boost.php') as $file) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($source)->not->toBeFalse();
		expect($source)->toContain('is_link($data_source_path)');
		expect($source)->toContain("Refusing to create an RRDfile through the symbolic link");
	}
});

it('never applies a following chown or chgrp to an RRDfile path', function () {
	foreach (array('lib/rrd.php', 'lib/boost.php') as $file) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($source)->not->toBeFalse();

		// A lookbehind, because 'lchown(' contains 'chown(' and a plain
		// substring test could never fail. chown() and chgrp() resolve a link,
		// so is_link() followed by either is a race; lchown()/lchgrp() act on
		// the final component instead.
		foreach (array('data_source_path', 'owned_path') as $variable) {
			expect(preg_match('/(?<!l)chown\\(\\$' . $variable . '/', $source))->toBe(0);
			expect(preg_match('/(?<!l)chgrp\\(\\$' . $variable . '/', $source))->toBe(0);
		}

		// And the ownership call takes the validated path, not the raw one.
		expect($source)->toContain('lchown($owned_path');
		expect($source)->toContain('lchgrp($owned_path');
		expect($source)->not->toContain('lchown($data_source_path');
	}
});

it('returns a validated path inside the RRA directory and false outside it', function () {
	require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

	$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	expect($functions)->not->toBeFalse();

	if (!function_exists(__NAMESPACE__ . '\\cacti_rrd_owned_path')) {
		// test-only eval of source read from this repository, not external input
		eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($functions, 'cacti_rrd_owned_path'));
	}

	$base = sys_get_temp_dir() . '/rra-' . getmypid() . '-' . mt_rand();

	mkdir($base . '/sub', 0700, true);

	// $GLOBALS['config'] outlives this test: Pest runs files in one process for
	// some suites, so leaving rra_path pointing at a directory removed below
	// would make later tests order-dependent.
	$previous = $GLOBALS['config'] ?? null;

	try {
		$GLOBALS['config']['rra_path'] = $base;

		// Inside the tree: returns a path rebuilt from the resolved directory
		// and the basename, so the value reaching lchown() is the validated one.
		expect(cacti_rrd_owned_path($base . '/x.rrd'))->toBe(realpath($base) . '/x.rrd');
		expect(cacti_rrd_owned_path($base . '/sub/x.rrd'))->toBe(realpath($base) . '/sub/x.rrd');

		// Outside it, or unresolvable: ownership is withheld entirely.
		expect(cacti_rrd_owned_path('/etc/x.rrd'))->toBeFalse();
		expect(cacti_rrd_owned_path($base . '/../x.rrd'))->toBeFalse();
		expect(cacti_rrd_owned_path($base . '/absent-dir/x.rrd'))->toBeFalse();
	} finally {
		if ($previous === null) {
			unset($GLOBALS['config']);
		} else {
			$GLOBALS['config'] = $previous;
		}

		@rmdir($base . '/sub');
		@rmdir($base);
	}
});

it('routes the existence check the way execution is routed', function () {
	foreach (array('lib/rrd.php', 'lib/boost.php') as $file) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($source)->not->toBeFalse();

		// rrd_init/rrd_close/rrdtool_execute all honour this flag. A create
		// guard that consults storage_location alone asks the proxy about a
		// file written locally, and the local link refusal never runs.
		expect($source)->toContain("\$config['force_storage_location_local']");
		expect($source)->toContain('$remote_storage');
	}
});

it('withholds ownership from a path that is not a regular file', function () {
	foreach (array('lib/rrd.php', 'lib/boost.php') as $file) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($source)->not->toBeFalse();

		// is_link() is false for a directory and the containment test only
		// checks where the path sits, so a directory under the RRA tree reached
		// lchown(). rrdtool create fails on it, and the poller then changed the
		// ownership of a directory it did not make.
		expect($source)->toContain('!is_file($owned_path)');
	}

	$dir = sys_get_temp_dir() . '/notafile-' . getmypid() . '-' . mt_rand();

	mkdir($dir, 0700, true);

	try {
		expect(is_link($dir))->toBeFalse();
		expect(is_file($dir))->toBeFalse();
	} finally {
		@rmdir($dir);
	}
});

it('changes ownership only when the create was local', function () {
	foreach (array('lib/rrd.php', 'lib/boost.php') as $file) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($source)->not->toBeFalse();

		// Under remote storage the create goes to the proxy, so a local file of
		// the same configured name is not the one this run wrote, and chowning
		// it would touch an unrelated path.
		expect($source)->toMatch('/!\$remote_storage && \$config\[\x27cacti_server_os\x27\]|\$may_own    = !\$remote_storage/');
	}
});

it('refuses a link at the realtime cache path too', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/poller_realtime.php');

	expect($source)->not->toBeFalse();

	// This caller asks rrdtool_function_create() for the command source only
	// and substitutes its own target, so the refusal inside that function does
	// not cover the write it performs.
	expect($source)->toContain('is_link($rt_graph_path)');

	$link = strpos($source, 'is_link($rt_graph_path)');
	$make = strpos($source, 'if (!file_exists($rt_graph_path)) {');

	expect($link)->not->toBeFalse();
	expect($make)->not->toBeFalse();
	expect($link)->toBeLessThan($make);
});

it('makes the symlink refusal a create failure, not an already-exists', function () {
	foreach (array('lib/rrd.php', 'lib/boost.php') as $file) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($source)->not->toBeFalse();

		// rrdtool_function_update() tests `=== false` and boost tests
		// `$created !== false`, so a refusal returning -1 is read as "the file
		// is already there" and the update proceeds regardless.
		$at  = strpos($source, 'Refusing to create an RRDfile through the symbolic link');
		$end = strpos($source, ';', strpos($source, 'return ', $at));

		expect($at)->not->toBeFalse();
		expect(substr($source, strpos($source, 'return ', $at), $end - strpos($source, 'return ', $at)))->toBe('return false');
	}
});

it('reports a skipped realtime run when it refuses a link', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/poller_realtime.php');

	expect($source)->not->toBeFalse();

	// The caller returns false only when $skipped is set, so a refusal that
	// left it unset would report the cycle as successful.
	$at = strpos($source, 'Realtime refusing to create an RRDfile');
	$to = strpos($source, 'continue;', $at);

	expect($at)->not->toBeFalse();
	expect(substr($source, $at, $to - $at))->toContain('$skipped = true;');
});
