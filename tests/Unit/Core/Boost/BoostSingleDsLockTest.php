<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * boost_process_poller_output() released 'boost.single_ds.<id>' without ever
 * acquiring it. Commit 0f67ff509 removed an unbounded `while (!GET_LOCK(...))`
 * wait from this function, correctly, but the bounded replacement only landed
 * in the Boost child in poller_boost.php; here the release was left behind and
 * the acquisition was not restored.
 *
 * Before rrdtool 1.5 there is no --skip-past-updates, so the web-triggered
 * on-demand flush and the Boost child could write the same RRDfile at once and
 * lose samples. The acquisition is restored with the child's bound, so a held
 * lock cannot hang a request, and it is released in the finally so a throw
 * cannot leave it held.
 */

namespace BoostSingleDsLockTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function flush_body() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php');

	expect($source)->not->toBeFalse();

	return \test_php_function_source($source, 'boost_process_poller_output');
}

it('acquires the lock it releases', function () {
	$body = flush_body();

	expect($body)->toContain("GET_LOCK('boost.single_ds.");
	expect($body)->toContain("RELEASE_LOCK('boost.single_ds.");
});

it('bounds the wait so a held lock cannot hang the caller', function () {
	$body = flush_body();

	// An unbounded `while (!GET_LOCK(...))` is what 0f67ff509 removed; the
	// replacement must give up rather than spin.
	$offset = strpos($body, "GET_LOCK('boost.single_ds.");

	expect($offset)->not->toBeFalse();

	$window = substr($body, $offset, 500);

	expect($window)->toContain('lock_deadline');
	expect($window)->toContain('return -1;');
});

it('uses the same bound as the Boost child', function () {
	$child = file_get_contents(dirname(__DIR__, 4) . '/poller_boost.php');

	expect($child)->not->toBeFalse();

	$bound = 'max(1, min(30, (int) ';

	expect(flush_body())->toContain($bound);
	expect($child)->toContain($bound);
});

it('releases the lock from the finally block', function () {
	$body = flush_body();

	$finally = strpos($body, '} finally {');
	$release = strpos($body, "RELEASE_LOCK('boost.single_ds.");

	expect($finally)->not->toBeFalse();
	expect($release)->not->toBeFalse();

	// A release before the finally leaks the lock when anything throws.
	expect($release)->toBeGreaterThan($finally);
});

it('only releases a lock it took', function () {
	$body = flush_body();

	// The release is gated on the acquisition flag, so the rrdtool 1.5+ path,
	// which takes no lock, does not issue a release for one it never held.
	$release = strpos($body, "RELEASE_LOCK('boost.single_ds.");
	$window  = substr($body, max(0, $release - 200), 200);

	expect($window)->toContain('if ($locks) {');
});

it('declares the lock flag before the try that the finally cleans up', function () {
	$body = flush_body();

	$declare = strpos($body, '$locks      = false;');
	$try     = strpos($body, "\ttry {");

	expect($declare)->not->toBeFalse();
	expect($try)->not->toBeFalse();

	// The finally reads $locks. Anything throwing between the try and a later
	// declaration would make the cleanup touch an undefined variable, and
	// boost_error_handler turns that warning into its own failure.
	expect($declare)->toBeLessThan($try);
});

it('takes the lock before it snapshots the queue', function () {
	$body = flush_body();

	$lock     = strpos($body, "GET_LOCK('boost.single_ds.");
	$snapshot = strpos($body, 'CREATE TEMPORARY TABLE $temp_table');

	expect($lock)->not->toBeFalse();
	expect($snapshot)->not->toBeFalse();

	// The child locks before it reads any rows for the data source. Locking
	// after the snapshot would leave this path waiting on a stale copy and then
	// replaying rows the child had already written and deleted.
	expect($lock)->toBeLessThan($snapshot);
});

it('drops the staging table from the finally so no exit path leaks one', function () {
	$body = flush_body();

	$finally = strpos($body, '} finally {');
	$drop    = strpos($body, 'DROP TEMPORARY TABLE');

	expect($finally)->not->toBeFalse();
	expect($drop)->not->toBeFalse();

	// The lock timeout returns early, so a drop in the main body would leave a
	// staging table per timeout on connections that loop over data sources.
	expect($drop)->toBeGreaterThan($finally);
	expect(substr($body, max(0, $drop - 120), 120))->toContain('$temp_table !== false');
});

it('releases the lock before it drops the staging table', function () {
	$body = flush_body();

	$release = strpos($body, "RELEASE_LOCK('boost.single_ds.");
	$drop    = strpos($body, 'DROP TEMPORARY TABLE');

	expect($release)->not->toBeFalse();
	expect($drop)->not->toBeFalse();

	// The lock lives on a session that outlives this call; a temporary table
	// dies with the connection. So a throw from the drop must not be what
	// prevents the release.
	expect($release)->toBeLessThan($drop);
});
