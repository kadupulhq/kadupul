<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * md5sum.php reached fail(EXIT_MD5ERR) on a hash mismatch under --quiet, but
 * nothing defined that constant. On PHP 8 an undefined constant is a fatal
 * Error, so the one path that reports a corrupted file died with status 255
 * instead of the code the caller was meant to read.
 *
 * Checking the whole set rather than the one name, because the fault is that
 * a fail() reference and its define_exit() drifted apart, and any later
 * addition can drift the same way.
 */

namespace Md5sumExitConstantsTest;

function md5sum_source() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/md5sum.php');

	expect($source)->not->toBeFalse();

	return $source;
}

/** Names passed to fail(), which must resolve at runtime. */
function referenced_constants(string $source) : array {
	preg_match_all('/fail\(\s*(EXIT_[A-Z0-9_]+)/', $source, $matches);

	return array_values(array_unique($matches[1]));
}

/** Names define_exit() actually creates. */
function defined_constants(string $source) : array {
	preg_match_all("/define_exit\(\s*'(EXIT_[A-Z0-9_]+)'/", $source, $matches);

	return array_values(array_unique($matches[1]));
}

it('defines every exit constant that fail() is given', function () {
	$source = md5sum_source();

	$missing = array_diff(referenced_constants($source), defined_constants($source));

	expect($missing)->toBe(array());
});

it('reads the mismatch constant as a distinct code', function () {
	$source = md5sum_source();

	expect(referenced_constants($source))->toContain('EXIT_MD5ERR');

	preg_match("/define_exit\(\s*'EXIT_MD5ERR'\s*,\s*(-?\d+)/", $source, $match);

	expect($match)->not->toBeEmpty();

	// Reusing a code would make a mismatch indistinguishable from the failure
	// already using it, which is what the caller reads the status for.
	preg_match_all("/define_exit\(\s*'EXIT_[A-Z0-9_]+'\s*,\s*(-?\d+)/", $source, $all);

	expect(count($all[1]))->toBe(count(array_unique($all[1])));
});
