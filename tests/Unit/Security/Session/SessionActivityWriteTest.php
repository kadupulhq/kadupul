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
 * Database sessions do not lock the request. A slow request can store an
 * older sess_last_activity after a newer request, and the idle check would
 * then expire a session that was used recently. The write keeps the later
 * value.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function session_activity_merge(string $stored, string $data) : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/include/session.php');
	$child  = "<?php\n" . cacti_test_function_source($source, 'cacti_db_session_monotonic_activity') . "\n"
		. "\$in = json_decode(stream_get_contents(STDIN), true);\n"
		. "print json_encode(array('data' => cacti_db_session_monotonic_activity(\$in['stored'], \$in['data'])));\n";

	return cacti_test_run_php_source($child, array('stored' => $stored, 'data' => $data))['data'];
}

test('an older session write keeps the newer activity time', function () {
	$stored = 'sess_user_id|s:2:"42";sess_last_activity|i:2000;';
	$data   = 'sess_user_id|s:2:"42";sess_last_activity|i:1000;';

	expect(session_activity_merge($stored, $data))->toBe('sess_user_id|s:2:"42";sess_last_activity|i:2000;');
});

test('a newer session write replaces the activity time', function () {
	$stored = 'sess_last_activity|i:1000;';
	$data   = 'sess_last_activity|i:2000;';

	expect(session_activity_merge($stored, $data))->toBe($data);
});

test('a session write without a stored activity time is left as it is', function () {
	$data = 'sess_last_activity|i:1000;';

	expect(session_activity_merge('', $data))->toBe($data);
});
