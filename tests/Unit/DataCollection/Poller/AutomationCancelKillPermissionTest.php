<?php
declare(strict_types=1);
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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

namespace AutomationCancelKillPermissionTest;
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/automation_runtime.php');
eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, 'automation_stop_children'));
function microtime($float = false) { return $GLOBALS['automation_cancel_clock']; }
function usleep($microseconds) { $GLOBALS['automation_cancel_clock'] += $microseconds / 1000000; }
function proc_get_status($process) { return array('running' => $GLOBALS['automation_cancel_running']); }
function proc_terminate($process, $signal = 15) {
	$GLOBALS['automation_cancel_signals'][] = array($process, $signal);
	if (!$GLOBALS['automation_cancel_denied']) { $GLOBALS['automation_cancel_running'] = false; }
	return !$GLOBALS['automation_cancel_denied'];
}
function proc_close($process) { $GLOBALS['automation_cancel_closed'][] = $process; return 0; }

test('cancellation retains ownership when termination is denied and closes stopped owned children', function ($denied) {
	$GLOBALS['automation_cancel_clock'] = 0.0;
	$GLOBALS['automation_cancel_running'] = true;
	$GLOBALS['automation_cancel_denied'] = $denied;
	$GLOBALS['automation_cancel_signals'] = $GLOBALS['automation_cancel_closed'] = array();
	$handle = fopen('php://memory', 'r+');
	try {
		automation_stop_children(array($handle));
		expect($GLOBALS['automation_cancel_signals'][0])->toBe(array($handle, 15));
		if ($denied) {
			expect($GLOBALS['automation_cancel_closed'])->toBe(array());
			expect($GLOBALS['automation_cancel_signals'][1])->toBe(array($handle, 9));
		} else {
			expect($GLOBALS['automation_cancel_closed'])->toBe(array($handle));
		}
	} finally { fclose($handle); }
})->with(array(true, false));
