<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace CacheMarkerEntropyTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function random_int($minimum, $maximum) {
	expect([$minimum, $maximum])->toBe([0, \mt_getrandmax()]);
	if ($GLOBALS['cache_marker_entropy'] === 'failure') {
		throw new \RuntimeException('Entropy unavailable');
	}
	return $GLOBALS['cache_marker_entropy'];
}

function date($format) {
	expect($format)->toBe('Y-m-d H:i:s');
	return '2026-09-22 12:34:56';
}

function db_execute_prepared($sql, $parameters) {
	$GLOBALS['cache_marker_writes'][] = [preg_replace('/\s+/', ' ', $sql), $parameters];
	return true;
}

foreach (['device', 'data_source'] as $kind) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/api_' . $kind . '.php');
	// Execute only the named function from a fixed first-party source file.
	eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'api_' . $kind . '_cache_crc_update')); // nosemgrep: php.lang.security.eval-use.eval-use
}

test('cache markers preserve the hash, setting name and database call contract', function ($kind, $entropy, $poller, $custom) {
	$GLOBALS['cache_marker_entropy'] = $entropy;
	$GLOBALS['cache_marker_writes'] = [];
	$function = __NAMESPACE__ . '\\api_' . $kind . '_cache_crc_update';
	$variable = $custom ? 'custom_cache_crc' : 'poller_replicate_' . $kind . '_cache_crc';
	if ($custom) {
		$function($poller, $variable);
	} else {
		$function($poller);
	}
	$expected = hash('ripemd160', '2026-09-22 12:34:56' . $entropy . $poller);
	expect($expected)->toMatch('/^[0-9a-f]{40}$/');
	expect($GLOBALS['cache_marker_writes'])->toBe([
		["REPLACE INTO settings SET value = ?, name='" . $variable . '_' . $poller . "'", [$expected]]
	]);
})->with(['device', 'data_source'])->with([0, \mt_getrandmax()])->with([1, 3])->with([false, true]);

test('cache marker entropy failure cannot overwrite the previous marker', function ($kind) {
	$GLOBALS['cache_marker_entropy'] = 'failure';
	$GLOBALS['cache_marker_writes'] = [];
	$function = __NAMESPACE__ . '\\api_' . $kind . '_cache_crc_update';
	expect(fn () => $function(3))->toThrow(\RuntimeException::class, 'Entropy unavailable');
	expect($GLOBALS['cache_marker_writes'])->toBe([]);
	$GLOBALS['cache_marker_entropy'] = 42;
	$function(3);
	expect($GLOBALS['cache_marker_writes'])->toHaveCount(1);
})->with(['device', 'data_source']);
