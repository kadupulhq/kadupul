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

/*
 * poller.php decides every four hours that collectors get --mibs. The time is
 * recorded only once a collector has been started with --mibs, so a run that
 * exits earlier (an overlapping cron run, a missing spine binary, disabled
 * polling) leaves the window open for the next run.
 */

namespace Kadupul\Tests\SystemMibRecord;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function read_config_option($key) {
	return $GLOBALS['mib_settings'][$key] ?? '';
}

function set_config_option($key, $value) {
	$GLOBALS['mib_writes'][]       = array($key, $value);
	$GLOBALS['mib_settings'][$key]    = $value;
}

if (!function_exists(__NAMESPACE__ . '\\mibs_poll')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');

	if (!is_string($source)) {
		throw new \RuntimeException('Unable to read poller.php.');
	}

	$decide = test_php_block_source($source, '// retrieve the last time the poller ran');
	$record = test_php_block_source($source, '/* record the system MIB run');

	/* the record block runs twice to show it writes once per process */
	eval('namespace ' . __NAMESPACE__ . '; function mibs_poll($poller_id, $current_time, $launch, $started_processes) {'
		. '$mibs = false; $mibs_recorded = false;'
		. $decide
		. 'if ($launch) {' . $record . $record . '}'
		. 'return $mibs; }'); // nosemgrep: php.lang.security.eval-use.eval-use
}

/** @param list<array{0: int, 1: int, 2: bool, 3: int}> $runs poller id, time, reached launch, collectors started */
function mibs_sequence(array $settings, array $runs) {
	$GLOBALS['mib_settings'] = $settings;
	$GLOBALS['mib_writes']   = array();
	$observed                = array();

	foreach ($runs as $run) {
		$observed[] = mibs_poll($run[0], $run[1], $run[2], $run[3]);
	}

	return array($observed, $GLOBALS['mib_writes']);
}

test('a poll that exits before starting a collector keeps the MIB window open', function () {
	list($observed, $writes) = mibs_sequence(array('poller_mibsrun_2' => 20000), array(
		array(2, 36000, false, 0),
		array(2, 36060, true, 0),
		array(2, 36120, true, 2),
		array(2, 36180, true, 2),
	));

	expect($observed)->toBe(array(true, true, true, false))
		->and($writes)->toBe(array(array('poller_mibsrun_2', 36120)));
});

test('a launched MIB collection is recorded once and does not leak between collectors', function () {
	list($observed, $writes) = mibs_sequence(array(), array(
		array(2, 36000, true, 1),
		array(2, 36060, true, 1),
		array(3, 36060, true, 1),
		array(2, 50400, true, 1),
		array(2, 50401, true, 1),
	));

	expect($observed)->toBe(array(true, false, true, false, true))
		->and($writes)->toBe(array(array('poller_mibsrun_2', 36000), array('poller_mibsrun_3', 36060), array('poller_mibsrun_2', 50401)));
});

test('the four-hour decision itself writes nothing', function () {
	list($observed, $writes) = mibs_sequence(array('poller_mibsrun_2' => 21599), array(array(2, 36000, false, 0)));

	expect($observed)->toBe(array(true))->and($writes)->toBe(array());
});
