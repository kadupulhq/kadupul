<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace PollerReplicationStatusTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function db_fetch_row($sql, $log = true, $conn = false) {
	return $GLOBALS['max_packet_result'];
}

function db_execute($sql, $silent = false, $conn = false) {
	return $GLOBALS['replication_write_result'];
}

function cacti_sizeof($value) {
	return count($value);
}

function replicate_log($message, $level = 0) {}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
if (!is_string($source)) {
	throw new \RuntimeException('Cannot read lib/poller.php.');
}
eval('namespace PollerReplicationStatusTest; ' . test_php_function_source($source, 'replicate_out_execute'));
eval('namespace PollerReplicationStatusTest; ' . test_php_function_source($source, 'replicate_table_to_poller'));

test('replication write failures and packet lookup failures cannot report success', function () {
	$GLOBALS['replicate_out_success'] = true;
	$GLOBALS['replication_write_result'] = false;
	expect(replicate_out_execute('DELETE FROM poller_item'))->toBeFalse()
		->and($GLOBALS['replicate_out_success'])->toBeFalse();

	$GLOBALS['replicate_out_success'] = true;
	$GLOBALS['max_packet_result'] = false;
	$data = array();
	expect(replicate_table_to_poller(null, $data, 'poller_item'))->toBeFalse()
		->and($GLOBALS['replicate_out_success'])->toBeFalse();
});
