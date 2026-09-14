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
 * Regression test for the handle splice_rrd.php left open when the
 * pre-restore identity check failed, and for the short-write check on the
 * fwrite() that precedes it.
 *
 * Both failure branches print a FATAL message and call exit(1) directly, the
 * same as every other early exit in this script. That rules out an in-process
 * behavioral test: evaluating either block here would end the Pest process
 * instead of just the assertion, and running one in a child process instead
 * cannot show anything the fix changes, since process exit reclaims the
 * handle either way, with or without the fclose() inside
 * cacti_cli_remove_file(). What is left to verify is the ordering in the
 * source itself: cacti_cli_remove_file($handle, $newxmlfile) must run before
 * the exit(1) that used to leave it open, the same way every other exit in
 * this script is preceded by its own cleanup call.
 */

$src = file_get_contents(__DIR__ . '/../../../../cli/splice_rrd.php');

/**
 * Assert that a FATAL message text is followed, within a short window, by
 * the handle cleanup and then the exit that used to leave it open.
 *
 * @param string $src     The full source of cli/splice_rrd.php.
 * @param string $message The FATAL message text to locate, as written in
 *                         the source with its literal escaped quotes.
 */
function splice_rrd_assert_cleanup_before_exit($src, $message) {
	$pos = strpos($src, $message);

	expect($pos)->not->toBeFalse($message);

	$block = substr($src, $pos, 200);

	$cleanup = strpos($block, 'cacti_cli_remove_file($handle, $newxmlfile);');
	$exit    = strpos($block, 'exit(1);');

	expect($cleanup)->not->toBeFalse($message)
		->and($exit)->not->toBeFalse($message)
		->and($cleanup)->toBeLessThan($exit, $message);
}

test('the restore identity check closes the handle before exiting', function () use ($src) {
	splice_rrd_assert_cleanup_before_exit($src, "Refusing to restore \\'' . \$newxmlfile . '\\' because it changed after it was written");
});

test('the short XML write check closes the handle before exiting', function () use ($src) {
	splice_rrd_assert_cleanup_before_exit($src, "Refusing to restore \\'' . \$newxmlfile . '\\' because the XML file was not written completely");
});

test('the write is checked for a short fwrite() and a failed fflush() before restoring', function () use ($src) {
	$write_pos = strpos($src, '$bytes_written = fwrite($handle, $new_xml);');

	expect($write_pos)->not->toBeFalse();

	$block = substr($src, $write_pos, 300);

	expect($block)->toContain('$bytes_written !== strlen($new_xml)')
		->and($block)->toContain('!fflush($handle)');

	$create_pos  = strpos($src, '$handle = cacti_cli_create_file($newxmlfile);');
	$restore_pos = strpos($src, 'if (!$dryrun) {');

	expect($create_pos)->not->toBeFalse()
		->and($restore_pos)->not->toBeFalse()
		->and($create_pos)->toBeLessThan($write_pos)
		->and($write_pos)->toBeLessThan($restore_pos);
});
