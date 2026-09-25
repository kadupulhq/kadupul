<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * boost_error_handler() wrapped its whole body in a POLLER_VERBOSITY_DEBUG
 * check. The handler does not return false, so PHP's own reporting never runs
 * for anything it sees, and the gate therefore did not make an error quieter,
 * it threw it away. A flush that failed on an undefined index or a failed
 * fopen left no record anywhere.
 *
 * What changes: the classes that mean something went wrong are logged at any
 * verbosity, notices and deprecations stay on DEBUG, and repeats of one error
 * site are capped below DEBUG so a per-sample warning cannot fill the log.
 */

namespace BoostErrorHandlerEvidence;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

const POLLER_VERBOSITY_DEBUG = 5;
const BOOST_ERROR_REPEAT_LIMIT = 10;

// test-only eval of source read from this repository, not external input
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(
	file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php'),
	'boost_error_handler'
));

function read_config_option($name) {
	return $name === 'log_verbosity' ? $GLOBALS['errh_verbosity'] : '';
}

/**
 * Drive the mask from the case rather than from the process, so a case cannot
 * leak a changed reporting level into the rest of the file.
 */
function error_reporting() {
	return $GLOBALS['errh_mask'];
}

function cacti_log($message, ...$args) {
	$GLOBALS['errh_logs'][] = $message;
}

/**
 * Raise one error through the handler. Each case passes its own line number so
 * the repeat counter, which is static for the life of the process, keys on a
 * site no other case shares.
 */
function raise(int $verbosity, int $errno, string $message, int $line, int $times = 1, $mask = E_ALL) : array {
	$GLOBALS['errh_verbosity'] = $verbosity;
	$GLOBALS['errh_mask']      = $mask;
	$GLOBALS['errh_logs']      = array();

	for ($i = 0; $i < $times; $i++) {
		$returned = boost_error_handler($errno, $message, '/lib/boost.php', $line);

		expect($returned)->toBeTrue();
	}

	return $GLOBALS['errh_logs'];
}

it('logs a warning at the default verbosity', function () {
	$logs = raise(1, E_WARNING, 'fopen(): failed to open stream', 101);

	// The defect: this was the only copy of the evidence and it was discarded.
	expect($logs)->toHaveCount(1);
	expect($logs[0])->toContain('failed to open stream');
	expect($logs[0])->toContain("TYPE:'Warning'");
});

it('logs a user error and a recoverable error at the default verbosity', function () {
	expect(raise(1, E_USER_ERROR, 'template missing', 102))->toHaveCount(1);
	expect(raise(1, E_RECOVERABLE_ERROR, 'argument type', 103))->toHaveCount(1);
	expect(raise(1, E_USER_WARNING, 'queue truncated', 119))->toHaveCount(1);
});

it('keeps notices and deprecations on debug', function () {
	expect(raise(1, E_NOTICE, 'Undefined index: rrd_path', 104))->toHaveCount(0);
	expect(raise(1, E_DEPRECATED, 'passing null', 105))->toHaveCount(0);
	expect(raise(1, 2048, 'strict standards', 106))->toHaveCount(0);

	expect(raise(POLLER_VERBOSITY_DEBUG, E_NOTICE, 'Undefined index: rrd_path', 107))->toHaveCount(1);
	expect(raise(POLLER_VERBOSITY_DEBUG, E_DEPRECATED, 'passing null', 108))->toHaveCount(1);
});

it('still drops the two suppressed messages at every verbosity', function () {
	foreach (array(1, POLLER_VERBOSITY_DEBUG) as $verbosity) {
		expect(raise($verbosity, E_WARNING, 'date_default_timezone(): not set', 109))->toHaveCount(0);
		expect(raise($verbosity, E_NOTICE, 'Only variables should be passed', 110))->toHaveCount(0);
	}
});

it('caps repeats of one site below debug and says it is doing so', function () {
	// Well past the limit, so a suppression line emitted per call rather than
	// once would show as a count that tracks the call count.
	$logs = raise(1, E_WARNING, 'Undefined array key "output"', 111, BOOST_ERROR_REPEAT_LIMIT + 50);

	// The limit, then one line naming the suppression, then silence.
	expect($logs)->toHaveCount(BOOST_ERROR_REPEAT_LIMIT + 1);
	expect($logs[BOOST_ERROR_REPEAT_LIMIT])->toContain('Suppressing further repeats');
	expect($logs[BOOST_ERROR_REPEAT_LIMIT])->toContain('Undefined array key');
});

it('counts each error site separately', function () {
	// A different line is a different site, so one noisy loop does not silence
	// the first occurrence of an unrelated fault.
	expect(raise(1, E_WARNING, 'a', 112, BOOST_ERROR_REPEAT_LIMIT + 5))->toHaveCount(BOOST_ERROR_REPEAT_LIMIT + 1);
	expect(raise(1, E_WARNING, 'b', 113))->toHaveCount(1);
});

it('does not cap on debug', function () {
	$logs = raise(POLLER_VERBOSITY_DEBUG, E_WARNING, 'noisy', 114, BOOST_ERROR_REPEAT_LIMIT + 5);

	expect($logs)->toHaveCount(BOOST_ERROR_REPEAT_LIMIT + 5);
});

it('respects the reporting mask so an @ silenced failure stays silent', function () {
	// The mask PHP installs for the duration of an @ expression on PHP 8.
	$silenced = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;

	// @stream_select and @fwrite on the rrdtool pipe, @unlink and @rename in
	// the atomic cache write, and @socket_connect on the RRDproxy failover all
	// run while this handler is installed, and all expect to fail sometimes.
	expect(raise(1, E_WARNING, 'stream_select(): interrupted', 115, 1, $silenced))->toHaveCount(0);
	expect(raise(POLLER_VERBOSITY_DEBUG, E_NOTICE, 'unlink(): no such file', 116, 1, $silenced))->toHaveCount(0);

	// An error the mask still carries is reported, silenced or not.
	expect(raise(1, E_USER_ERROR, 'template missing', 117, 1, $silenced))->toHaveCount(1);
});

it('does not spend the repeat allowance on silenced errors', function () {
	$silenced = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;

	raise(1, E_WARNING, 'rename(): permission denied', 118, BOOST_ERROR_REPEAT_LIMIT + 5, $silenced);

	// The mask test precedes the counter, so the same site reports normally
	// once it is raised unsilenced.
	expect(raise(1, E_WARNING, 'rename(): permission denied', 118))->toHaveCount(1);
});

it('confirms PHP hands a silenced error to the handler', function () {
	// PHP_BINARY is phpdbg under some coverage setups, where -r does not behave
	// the same and the case would fail for an unrelated reason.
	if (PHP_SAPI !== 'cli') {
		test()->markTestSkipped('needs the CLI binary to run a subprocess');
	}

	// The premise of the mask test, measured rather than assumed: without the
	// mask the handler sees the error and error_reporting() reports what @ set.
	$code = 'set_error_handler(function ($n, $m) { echo $n, " ", error_reporting(), PHP_EOL; return true; });'
		. ' @$undefined["k"];';

	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);

	expect($out)->not->toBeEmpty();

	// One expression can raise more than one diagnostic; the first is enough.
	$parts = explode(' ', trim($out[0]));

	expect($parts)->toHaveCount(2);
	expect((int) $parts[0])->toBeGreaterThan(0);
	expect((int) $parts[1] & E_WARNING)->toBe(0);
});

it('no longer wraps the whole handler in the verbosity check', function () {
	$body = \test_php_function_source(
		file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php'),
		'boost_error_handler'
	);

	// The error string has to be built for every class the handler logs, so a
	// verbosity test that precedes it is the gate this change removed.
	$gate = strpos($body, 'POLLER_VERBOSITY_DEBUG');
	$log  = strpos($body, "cacti_log('PROGERR: ' . \$err");

	expect($gate)->not->toBeFalse();
	expect($log)->not->toBeFalse();
	expect($body)->toContain('$debug_only');

	// The mask has to come before the counter, or a silenced error spends the
	// allowance that the real one needs.
	$mask  = strpos($body, 'error_reporting()');
	$count = strpos($body, 'static $seen');

	expect($mask)->not->toBeFalse();
	expect($count)->not->toBeFalse();
	expect($mask)->toBeLessThan($count);
});

it('declares the repeat limit as a constant the application loads', function () {
	$constants = file_get_contents(dirname(__DIR__, 4) . '/include/global_constants.php');

	expect($constants)->not->toBeFalse();
	expect($constants)->toContain("define('BOOST_ERROR_REPEAT_LIMIT'");
});
