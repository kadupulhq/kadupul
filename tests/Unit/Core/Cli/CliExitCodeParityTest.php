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
 * Wrappers such as batchgapfix.php store the removespikes.php exit code, and
 * scripts test splice_rrd.php for specific failures, so each message keeps the
 * code it had in 1.2.31. Both scripts connect to the database before parsing
 * arguments, so the pairs are read from source: each message must be followed
 * by the listed exit() before any other exit().
 */

function cli_exit_code_after(string $source, string $message) : ?string {
	$offset = strpos($source, $message);

	if ($offset === false) {
		return null;
	}

	if (preg_match('/exit\((-?\d+)\);/', $source, $match, 0, $offset) !== 1) {
		return null;
	}

	return $match[1];
}

dataset('1.2.31 exit codes', array(
	'removespikes invalid parameter' => array('cli/removespikes.php', "print 'ERROR: Invalid Parameter ' . \$parameter", '-3'),
	'removespikes spike removal failed' => array('cli/removespikes.php', 'print "ERROR: Remove Spikes experienced errors\n";', '-1'),
	'splice old file missing' => array('cli/splice_rrd.php', "print 'FATAL: File \\'' . \$oldrrd . '\\' does not exist.'", '-9'),
	'splice old file not writable' => array('cli/splice_rrd.php', "print 'FATAL: File \\'' . \$oldrrd . '\\' is not writable by this account.'", '-8'),
	'splice new file missing' => array('cli/splice_rrd.php', "print 'FATAL: File \\'' . \$newrrd . '\\' does not exist.'", '-9'),
	'splice new file not writable' => array('cli/splice_rrd.php', "print 'FATAL: File \\'' . \$newrrd . '\\' is not writable by this account.'", '-8'),
	'splice final file not writable' => array('cli/splice_rrd.php', "print 'FATAL: File \\'' . \$finrrd . '\\' is not writable by this account.'", '-8'),
	'splice invalid parameter' => array('cli/splice_rrd.php', "print 'ERROR: Invalid Parameter ' . \$parameter", '-3'),
	'splice no old file' => array('cli/splice_rrd.php', "print 'FATAL: You must specify a old RRDfile!'", '-2'),
	'splice no new file' => array('cli/splice_rrd.php', "print 'FATAL: You must specify a New RRDfile!'", '-2'),
	'splice no final file' => array('cli/splice_rrd.php', "print 'FATAL: You must specify a New RRDfile or use the overwrite option!'", '-2'),
	'splice rrdtool missing' => array('cli/splice_rrd.php', "'Please insure RRDTool can be found using one of these methods!'", '-1'),
	'splice old dump failed' => array('cli/splice_rrd.php', "print 'FATAL: RRDtool Command Failed on \\'' . \$oldrrd", '-12'),
	'splice new dump failed' => array('cli/splice_rrd.php', "print 'FATAL: RRDtool Command Failed on \\'' . \$newrrd", '-12'),
));

test('maintenance scripts keep their 1.2.31 exit code for each failure', function (string $file, string $message, string $code) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

	expect($source)->not->toBeFalse()
		->and(cli_exit_code_after($source, $message))->toBe($code);
})->with('1.2.31 exit codes');

test('splice_rrd stops with exit 1 when a temporary name is already taken', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/splice_rrd.php');

	expect(cli_exit_code_after($source, "print 'FATAL: ' . \$handle . PHP_EOL;"))->toBe('1');
});
