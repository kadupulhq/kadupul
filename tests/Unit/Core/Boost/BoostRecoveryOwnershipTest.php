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
 * poller_recovery.php forwards rows a remote collector stored while offline.
 * When one row belongs to a data source that main no longer assigns to this
 * poller, the batch must not stop the whole recovery: 1.2.31 then set the
 * poller back to status 2.  Rows main does not assign are still not forwarded.
 */

$root = dirname(__DIR__, 4);

function boostRecoveryOwnership_db_fetch_assoc_prepared($sql, $params = array(), $log = true, $conn = false) {
	$state =& $GLOBALS['boost_recovery_ownership'];
	$state['lookups']++;

	if (in_array($state['lookups'], $state['failed_lookups'], true)) {
		return false;
	}

	$rows = array();

	foreach (array_slice($params, 1) as $id) {
		if (in_array($id, $state['assigned'], true)) {
			$rows[] = array('local_data_id' => (string) $id);
		}
	}

	return $rows;
}

function boostRecoveryOwnership_boost_flush_output_batch($value_tuples, $conn = false) {
	$GLOBALS['boost_recovery_ownership']['forwarded'] = array_merge($GLOBALS['boost_recovery_ownership']['forwarded'], $value_tuples);

	return true;
}

function boostRecoveryOwnership_recovery_delete_acknowledged_rows($rows, $conn) {
	$GLOBALS['boost_recovery_ownership']['deleted'] = array_merge($GLOBALS['boost_recovery_ownership']['deleted'], $rows);

	return true;
}

function boostRecoveryOwnership_db_qstr($value, $conn = false) {
	return "'" . addslashes($value) . "'";
}

function boostRecoveryOwnership_cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function boostRecoveryOwnership_cacti_log($message) {
	$GLOBALS['boost_recovery_ownership']['logs'][] = $message;
}

function boostRecoveryOwnershipBlock($source, $needle) {
	$start = strpos($source, $needle);

	expect($start)->not->toBeFalse();

	$depth = 0;

	for ($offset = strpos($source, '{', $start), $length = strlen($source); $offset < $length; $offset++) {
		if ($source[$offset] === '{') {
			$depth++;
		} elseif ($source[$offset] === '}' && --$depth === 0) {
			return substr($source, $start, $offset + 1 - $start);
		}
	}

	return false;
}

function boostRecoveryOwnershipLoad($root) {
	if (function_exists('boostRecoveryOwnershipBatch')) {
		return;
	}

	$source = file_get_contents($root . '/poller_recovery.php');
	$rename = function ($code) {
		return preg_replace('/\b(recovery_owned_data_source_ids|recovery_delete_acknowledged_rows|boost_validate_poller_ownership|boost_flush_output_batch|db_fetch_assoc_prepared|db_qstr|cacti_sizeof|cacti_log)\(/', 'boostRecoveryOwnership_$1(', $code);
	};

	$helper = boostRecoveryOwnershipBlock($source, 'function recovery_owned_data_source_ids(');
	$batch  = boostRecoveryOwnershipBlock($source, 'if (cacti_sizeof($rows)) {');

	expect($helper)->not->toBeFalse()
		->and($batch)->not->toBeFalse();

	eval($rename($helper));

	/* the batch block runs inside the recovery while loop, so give its break a loop */
	eval('function boostRecoveryOwnershipBatch($rows, $poller_id) {
		$remote_db_cnn_id = "main";
		$local_db_cnn_id  = "local";
		$records_inserted = 0;
		$transfer_failed  = false;

		do {' . $rename($batch) . '} while (false);

		return array("transfer_failed" => $transfer_failed, "records_inserted" => $records_inserted);
	}');
}

function boostRecoveryOwnershipRows() {
	return array(
		array('local_data_id' => '5', 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00', 'output' => '10'),
		array('local_data_id' => '9', 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00', 'output' => '20'),
		array('local_data_id' => '5', 'rrd_name' => 'traffic_out', 'time' => '2026-01-01 00:05:00', 'output' => '30'),
	);
}

beforeEach(function () use ($root) {
	boostRecoveryOwnershipLoad($root);

	$GLOBALS['boost_recovery_ownership'] = array(
		'assigned'       => array(5),
		'failed_lookups' => array(),
		'lookups'        => 0,
		'forwarded'      => array(),
		'deleted'        => array(),
		'logs'           => array(),
	);
});

test('a data source main no longer assigns does not stop recovery', function () {
	$result = boostRecoveryOwnershipBatch(boostRecoveryOwnershipRows(), 3);
	$state  = $GLOBALS['boost_recovery_ownership'];

	expect($result['transfer_failed'])->toBeFalse()
		->and($result['records_inserted'])->toBe(2)
		->and($state['forwarded'])->toBe(array("(5,'traffic_in','2026-01-01 00:05:00','10')", "(5,'traffic_out','2026-01-01 00:05:00','30')"))
		->and($state['deleted'])->toHaveCount(3)
		->and(implode("\n", $state['logs']))->toContain('Discarding 1 records for data sources not assigned to this poller');
});

test('rows for assigned data sources are all forwarded', function () {
	$GLOBALS['boost_recovery_ownership']['assigned'] = array(5, 9);

	$result = boostRecoveryOwnershipBatch(boostRecoveryOwnershipRows(), 3);

	expect($result['transfer_failed'])->toBeFalse()
		->and($result['records_inserted'])->toBe(3)
		->and($GLOBALS['boost_recovery_ownership']['lookups'])->toBe(1)
		->and($GLOBALS['boost_recovery_ownership']['forwarded'])->toHaveCount(3);
});

test('an ownership lookup that fails keeps every row and fails the transfer', function () {
	$GLOBALS['boost_recovery_ownership']['failed_lookups'] = array(1, 2, 3);

	$result = boostRecoveryOwnershipBatch(boostRecoveryOwnershipRows(), 3);

	expect($result['transfer_failed'])->toBeTrue()
		->and($GLOBALS['boost_recovery_ownership']['forwarded'])->toBe(array())
		->and($GLOBALS['boost_recovery_ownership']['deleted'])->toBe(array());
});

test('a row without a data source id is discarded with its own log line', function () {
	$rows   = boostRecoveryOwnershipRows();
	$rows[] = array('local_data_id' => '0', 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00', 'output' => '40');

	$result = boostRecoveryOwnershipBatch($rows, 3);
	$state  = $GLOBALS['boost_recovery_ownership'];
	$logs   = implode("\n", $state['logs']);

	expect($result['transfer_failed'])->toBeFalse()
		->and($result['records_inserted'])->toBe(2)
		->and($state['deleted'])->toHaveCount(4)
		->and($logs)->toContain('Discarding 1 records without a valid data source id')
		->and($logs)->toContain('Discarding 1 records for data sources not assigned to this poller');
});

test('a batch of rows without a data source id needs no lookup and logs the discard', function () {
	$rows = array(array('local_data_id' => '0', 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00', 'output' => '40'));

	$result = boostRecoveryOwnershipBatch($rows, 3);
	$state  = $GLOBALS['boost_recovery_ownership'];
	$logs   = implode("\n", $state['logs']);

	expect($result['transfer_failed'])->toBeFalse()
		->and($state['lookups'])->toBe(0)
		->and($state['forwarded'])->toBe(array())
		->and($state['deleted'])->toHaveCount(1)
		->and($logs)->toContain('Discarding 1 records without a valid data source id')
		->and($logs)->not->toContain('not assigned to this poller');
});

test('a lookup that fails once and would succeed on retry still keeps every row', function () {
	$GLOBALS['boost_recovery_ownership']['assigned']       = array(5, 9);
	$GLOBALS['boost_recovery_ownership']['failed_lookups'] = array(1);

	$result = boostRecoveryOwnershipBatch(boostRecoveryOwnershipRows(), 3);

	expect($result['transfer_failed'])->toBeTrue()
		->and($result['records_inserted'])->toBe(0)
		->and($GLOBALS['boost_recovery_ownership']['forwarded'])->toBe(array())
		->and($GLOBALS['boost_recovery_ownership']['deleted'])->toBe(array());
});
