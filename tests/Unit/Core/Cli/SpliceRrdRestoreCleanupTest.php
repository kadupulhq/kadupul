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
 * pre-restore identity check failed.
 *
 * The failure branch prints a FATAL message and calls exit(1) directly, the
 * same as every other early exit in this script. That rules out an in-process
 * behavioral test: evaluating the block here would end the Pest process
 * instead of just the assertion, and running it in a child process instead
 * cannot show anything the fix changes, since process exit reclaims the
 * handle either way, with or without the fclose() inside
 * cacti_cli_remove_file(). What is left to verify is the ordering in the
 * source itself: cacti_cli_remove_file($handle, $newxmlfile) must run before
 * the exit(1) that used to leave it open, the same way every other exit in
 * this script is preceded by its own cleanup call.
 */

$src = file_get_contents(__DIR__ . '/../../../../cli/splice_rrd.php');

test('the restore identity check closes the handle before exiting', function () use ($src) {
	$pos = strpos($src, "Refusing to restore \\'");

	expect($pos)->not->toBeFalse();

	$block = substr($src, $pos, 200);

	$cleanup = strpos($block, 'cacti_cli_remove_file($handle, $newxmlfile);');
	$exit    = strpos($block, 'exit(1);');

	expect($cleanup)->not->toBeFalse()
		->and($exit)->not->toBeFalse()
		->and($cleanup)->toBeLessThan($exit);
});
