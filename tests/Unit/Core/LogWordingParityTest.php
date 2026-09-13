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
 * Operators match these log and console lines, so they keep their 1.2.31
 * text. The checks behind them are unchanged; only the wording is pinned.
 */

function log_wording_source(string $file) : string {
	$source = file_get_contents(dirname(__DIR__, 3) . '/' . $file);

	if ($source === false) {
		throw new RuntimeException('Unable to read ' . $file);
	}

	return $source;
}

dataset('1.2.31 wording', array(
	'data source validation' => array(
		'lib/functions.php',
		"\"data has \$space_cnt spaces and \$delim_cnt fields which is \" . ((\$space_cnt + 1 == \$delim_cnt) ? '' : 'NOT') . ' okay'",
		'fields; this is'
	),
	'script server file root' => array(
		'script_server.php',
		"cacti_log(\"WARNING: Script file '\$include_file' resolves outside base path. Rejected.\", false, 'PHPSVR');",
		'outside the allowed script roots. Rejected.'
	),
	'script server function root' => array(
		'script_server.php',
		"cacti_log(\"WARNING: Function '\$function' defined outside base path ('\$fn_file'). Rejected.\", false, 'PHPSVR');",
		"defined outside the allowed script roots ('"
	),
	'audit repair partial summary' => array(
		'cli/audit_database.php',
		"print 'Repair Completed!  ' . \$good . ' Alters succeeded and ' . \$bad . ' failed!' . PHP_EOL;",
		' operations succeeded and '
	),
	'audit repair summary' => array(
		'cli/audit_database.php',
		"print 'Repair Completed!  All ' . \$good . ' Alters succeeded!' . PHP_EOL;",
		' operations succeeded!'
	),
	'gap fill stale process' => array(
		'cli/batchgapfix.php',
		'printf("NOTE: Process with PID: %s, not found likely crashed." . PHP_EOL, $logged_pid);',
		'does not match the registered command'
	),
	'recovery write' => array(
		'poller_recovery.php',
		"cacti_log('RECOVERY: Writing ' . \$record_count . ' records (' . \$packet_size . ' bytes) to main (last slice).', false, 'POLLER');",
		" records to main.', false"
	),
));

test('log and console lines keep their 1.2.31 wording', function (string $file, string $expected, string $replaced) {
	$source = log_wording_source($file);

	expect($source)->toContain($expected)
		->and($source)->not->toContain($replaced);
})->with('1.2.31 wording');

test('the second recovery-running line is debug only, so default logs match 1.2.31', function () {
	$source = log_wording_source('poller_recovery.php');

	expect($source)->toContain("cacti_process_pid_for_log(\$recovery_pid) . ').', false, 'POLLER', POLLER_VERBOSITY_DEBUG);")
		->and($source)->toContain("cacti_log('RECOVERY: Recovery process still running for Poller ' . \$poller_id . '.  PID is ' . \$recovery_pid, false, 'POLLER');");
});
