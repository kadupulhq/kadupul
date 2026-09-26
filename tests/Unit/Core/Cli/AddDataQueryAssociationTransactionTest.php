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
*/

namespace AddDataQueryAssociationTransactionTest;

function db_begin_transaction() {
	$GLOBALS['association_transaction']['begins']++;

	return true;
}

function db_execute_prepared($sql, $params = array()) {
	return true;
}

function run_data_query($host_id, $data_query_id) {
	return $GLOBALS['association_transaction']['reindex'];
}

function is_error_message() {
	return false;
}

function db_rollback_transaction() {
	$GLOBALS['association_transaction']['rollbacks']++;

	return true;
}

function db_commit_transaction() {
	$GLOBALS['association_transaction']['commits']++;

	return true;
}

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/add_data_query.php');

if ($source === false || preg_match('/^function add_data_query_association\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract add_data_query_association() from cli/add_data_query.php');
}

eval('namespace AddDataQueryAssociationTransactionTest;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

beforeEach(function () {
	$GLOBALS['association_transaction'] = array('begins' => 0, 'rollbacks' => 0, 'commits' => 0, 'reindex' => false);
});

test('initial reindex failure rolls the new association back', function () {
	expect(add_data_query_association(4, 7, 1))->toBe('reindex_failed')
		->and($GLOBALS['association_transaction']['rollbacks'])->toBe(1)
		->and($GLOBALS['association_transaction']['commits'])->toBe(0);
});

test('the association commits only after initial reindex succeeds', function () {
	$GLOBALS['association_transaction']['reindex'] = true;

	expect(add_data_query_association(4, 7, 1))->toBe('success')
		->and($GLOBALS['association_transaction']['rollbacks'])->toBe(0)
		->and($GLOBALS['association_transaction']['commits'])->toBe(1);
});
