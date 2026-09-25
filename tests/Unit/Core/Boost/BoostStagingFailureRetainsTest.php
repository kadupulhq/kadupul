<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * boost_process_poller_output() copies a data source's archived samples into a
 * temporary table and later runs DELETE FROM <archive> WHERE local_data_id = ?.
 * That delete is scoped by data source, not by what was read, so a staging copy
 * that failed left the samples unread and then destroyed them. The copies were
 * issued with the return discarded and $log = false, which also disables the
 * deadlock retry in db_execute_prepared(), so nothing surfaced.
 *
 * The delete must now be reachable only when every staging step succeeded.
 */

namespace BoostStagingFailureRetainsTest;

function boost_source() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php');

	expect($source)->not->toBeFalse();

	return $source;
}

/** The body of boost_process_poller_output(), where staging and the deletes live. */
function process_body(string $source) : string {
	$start = strpos($source, 'function boost_process_poller_output(');

	expect($start)->not->toBeFalse();

	$end = strpos($source, "\nfunction ", $start + 1);

	return substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
}

it('checks the result of every staging statement', function () {
	$body = process_body(boost_source());

	// Each staging statement writes into the temporary table; none of them may
	// be issued with the result discarded.
	preg_match_all('/(?:db_execute|db_execute_prepared)\((?:"|\')(?:CREATE TEMPORARY TABLE|INSERT IGNORE INTO) \$temp_table/', $body, $matches);

	expect($matches[0])->toHaveCount(3);

	foreach ($matches[0] as $statement) {
		$offset = strpos($body, $statement);
		$before = substr($body, max(0, $offset - 40), 40);

		expect($before)->toContain('if (');
	}
});

it('gates the archive delete on staging having succeeded', function () {
	$body = process_body(boost_source());

	// $updates_ok guards the archive delete, so it must start from the staging
	// result rather than from an unconditional true.
	expect($body)->toContain('$updates_ok      = $staging_ok;');
	expect($body)->not->toContain('$updates_ok      = true;');
});

it('still deletes the archive rows when staging succeeded', function () {
	$body = process_body(boost_source());

	expect($body)->toContain('DELETE FROM $table WHERE local_data_id = ?');
	expect($body)->toContain('if ($updates_ok && cacti_count($archive_tables)) {');
});

it('gates the mid-page flush on updates_ok as well', function () {
	$body = process_body(boost_source());

	// Both flushes must be gated. An ungated mid-page flush writes live
	// samples after a staging failure, which strands the retained archived
	// samples behind a newer last-update time.
	preg_match_all('/boost_rrdtool_function_update\(/', $body, $calls);

	expect($calls[0])->toHaveCount(2);
	expect($body)->toContain('if ($outlen > $upd_string_len && $updates_ok) {');
	expect($body)->toContain('if ($vals_in_buffer && $updates_ok) {');
});
