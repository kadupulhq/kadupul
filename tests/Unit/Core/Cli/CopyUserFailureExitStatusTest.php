<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * copy_user.php ended each of its three failure paths with die() given a
 * string. die() only sets an exit status when it is given an integer, so a
 * missing template user or a failed copy printed an error and still reported
 * success to the caller.
 *
 * The statements are run in a child interpreter rather than matched in the
 * source, so the assertion is the status a shell would see.
 */

namespace CopyUserFailureExitStatusTest;

function copy_user_source() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/copy_user.php');

	expect($source)->not->toBeFalse();

	return $source;
}

/** Status of a statement run on its own, as a caller would observe it. */
function status_of(string $statement) : int {
	$code = 'define("PHP_EOL_SHIM", 1); ' . $statement;
	$out  = array();
	$rc   = 0;

	exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' >/dev/null 2>&1', $out, $rc);

	return $rc;
}

/** Each failure branch: the print/exit or die that terminates it. */
function failure_statements(string $source) : array {
	preg_match_all('/^\t(?:die\(.*?\);|print [^;]*?;\n\texit\(-?\d+\);)$/m', $source, $matches);

	return $matches[0];
}

it('ends every failure branch with a non-zero status', function () {
	$statements = failure_statements(copy_user_source());

	expect($statements)->toHaveCount(3);

	foreach ($statements as $statement) {
		expect(status_of($statement))->not->toBe(0);
	}
});

it('still reports success when nothing failed', function () {
	expect(status_of('print "done";'))->toBe(0);
});

/**
 * The conditions guarding the two user lookups, as the script writes them.
 * Keyed on $user_auth so the user_copy() branch, which reports the same
 * message but tests a return value rather than a row, is left out.
 */
function lookup_guards(string $source) : array {
	preg_match_all('/^if \(([^)]*\$user_auth[^{]*)\) \{\n\tprint /m', $source, $matches);

	return array_map('trim', $matches[1]);
}

/**
 * Evaluate a guard against what db_fetch_row() returns for a row that is not
 * there. lib/database.php:806 returns array(), and isset(array()) is true, so
 * an isset() test never enters the branch it guards.
 */
function guard_fires_on_missing_row(string $condition) : bool {
	$code = 'function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }'
		. ' $user_auth = array();'
		. ' echo (' . $condition . ') ? "FIRES" : "DEAD";';

	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);

	return implode('', $out) === 'FIRES';
}

it('enters the failure branch when the user lookup found nothing', function () {
	$guards = lookup_guards(copy_user_source());

	expect($guards)->toHaveCount(2);

	foreach ($guards as $condition) {
		expect(guard_fires_on_missing_row($condition))->toBeTrue();
	}
});
