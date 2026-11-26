<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Coverage backfill for PR #7149. csrf-magic only validates the
 * magic token on POST. The whitelist_update case in data_input.php
 * executes a worker and therefore must reject non-POST
 * before reaching the executor call, otherwise a GET-triggered CSRF
 * gadget on an authenticated admin can fire the rebuild push.
 */

$source = file_get_contents(__DIR__ . '/../../../../data_input.php');

test('whitelist_update requires strict POST and refuses web-initiated execution', function () use ($source) {
	$start = strpos($source, "case 'whitelist_update':");
	expect($start)->not->toBeFalse();

	$end = strpos($source, 'break;', $start);
	expect($end)->not->toBeFalse();

	$body = substr($source, $start, $end - $start);

	expect($body)->toContain('csrf_require_post(true);')
		->and($body)->not->toContain('cacti_exec(')
		->and($body)->not->toContain('shell_exec(');
});
