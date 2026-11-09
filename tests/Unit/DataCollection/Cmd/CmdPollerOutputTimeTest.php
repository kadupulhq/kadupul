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

// Stable timestamps across delayed database writes are exercised by
// SubsystemFailureNativeTest against the real collector tables.
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function cmdOutputTime_read_config_option($name) {
	return $GLOBALS['cmd_output_time']['options'][$name] ?? '';
}
function cmdOutputTime_cacti_log(...$args) {}
function cmdOutputTime_poller_output_enqueue($spool, $rows, $redirect) {
	$GLOBALS['cmd_output_time']['queued'] = [$spool, $rows, $redirect];
	return $GLOBALS['cmd_output_time']['enqueue'];
}
function cmdOutputTime_poller_output_replay($spool, $connection) {
	$GLOBALS['cmd_output_time']['replayed'] = [$spool, $connection];
	return $GLOBALS['cmd_output_time']['replay'];
}
function cmdOutputTimeRun(bool $enqueue, bool $replay, bool $boost): bool {
	if (!function_exists('cmdOutputTime_cmd_write_poller_output')) {
		$source = test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/cmd.php'), 'cmd_write_poller_output');
		eval(preg_replace('/\\b(cmd_write_poller_output|read_config_option|poller_output_enqueue|poller_output_replay|cacti_log)\\(/', 'cmdOutputTime_$1(', $source)); // nosemgrep: php.lang.security.eval-use.eval-use
	}
	$GLOBALS['output_spool'] = 'spool';
	$GLOBALS['cmd_output_time'] = [
		'options' => ['boost_redirect' => $boost ? 'on' : '', 'boost_rrd_update_enable' => 'on'],
		'enqueue' => $enqueue, 'replay' => $replay,
	];
	return cmdOutputTime_cmd_write_poller_output([[7, 'traffic_in', '0']], 'poller');
}

test('every output handoff preserves values and configured destinations', function (bool $boost): void {
	expect(cmdOutputTimeRun(true, true, $boost))->toBeTrue()
		->and($GLOBALS['cmd_output_time']['queued'])->toBe(['spool', [[7, 'traffic_in', '0']], $boost])
		->and($GLOBALS['cmd_output_time']['replayed'])->toBe(['spool', 'poller']);
})->with([true, false]);

test('failed local retention prevents handoff and reports failure', function (): void {
	expect(cmdOutputTimeRun(false, true, true))->toBeFalse()
		->and($GLOBALS['cmd_output_time'])->not->toHaveKey('replayed');
});

test('failed database delivery never reports a successful flush', function (): void {
	expect(cmdOutputTimeRun(true, false, true))->toBeFalse();
});

test('each flush checks the shared writer and stops collection with a failing exit status', function (): void {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cmd.php');
	expect(substr_count($source, 'if (!cmd_write_poller_output($output_array, $poller_db_cnn_id)) {'))->toBe(3)
		->and(substr_count($source, '$poller_write_failed = true;'))->toBe(3)
		->and($source)->toContain('if ($output_count > 0 && !$poller_write_failed) {')
		->and($source)->toContain('exit($poller_write_failed ? 1 : 0);');
});

test('cmd.php writes neither output table directly; the spool delivers both copies', function (): void {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cmd.php');
	// The one INSERT for both tables lives in poller_output_deliver(); see
	// SubsystemFailureNativeTest for matching times and rollback of both copies.
	expect($source)->not->toContain('INSERT IGNORE INTO poller_output')
		->and($source)->not->toContain('SET timestamp');
});
