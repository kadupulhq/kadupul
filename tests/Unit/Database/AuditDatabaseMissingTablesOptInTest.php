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

namespace AuditDatabaseMissingTablesOptInTest;

/*
 * 1.2.31 cli/audit_database.php audited only the tables that exist. Listing
 * absent core tables, and creating them on --repair, runs only with
 * --missing-tables.
 */

require_once dirname(__DIR__, 3) . '/lib/audit.php';

function audit_missing_core_tables(array $expected_tables, array $actual_tables) : array {
	return \audit_missing_core_tables($expected_tables, $actual_tables);
}

function audit_extract_create_table(string $schema_sql, string $table) {
	return \audit_extract_create_table($schema_sql, $table);
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function create_tables($load = true) {
}

function db_fetch_assoc($sql) {
	if (stripos($sql, 'SHOW TABLES') === 0) {
		return array();
	}

	$GLOBALS['audit_opt_in_schema_queries']++;

	return array(array('table_name' => 'poller_output_boost_local_data_ids'));
}

$source = file_get_contents(dirname(__DIR__, 3) . '/cli/audit_database.php');

if ($source === false || preg_match('/^function report_audit_results\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract report_audit_results() from cli/audit_database.php');
}

eval('namespace AuditDatabaseMissingTablesOptInTest;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

beforeEach(function () {
	$GLOBALS['config']['base_path']           = dirname(__DIR__, 3);
	$GLOBALS['database_default']              = 'cacti';
	$GLOBALS['altersopt']                     = false;
	$GLOBALS['audit_opt_in_schema_queries']   = 0;
});

test('without --missing-tables an absent core table is not reported or created', function () {
	$GLOBALS['missingopt'] = false;

	ob_start();
	$alters = report_audit_results(false);
	$output = ob_get_clean();

	expect($alters)->toBe(array())
		->and($output)->not->toContain('Missing core table')
		->and($GLOBALS['audit_opt_in_schema_queries'])->toBe(0);
});

test('with --missing-tables an absent core table is reported with its canonical create statement', function () {
	$GLOBALS['missingopt'] = true;

	ob_start();
	$alters = report_audit_results(false);
	$output = ob_get_clean();

	expect($output)->toContain("Scanning Table: 'poller_output_boost_local_data_ids'")
		->and($output)->toContain('Missing core table')
		->and(array_keys($alters))->toBe(array('poller_output_boost_local_data_ids'))
		->and($alters['poller_output_boost_local_data_ids']['__create_table__'])->toStartWith('CREATE TABLE `poller_output_boost_local_data_ids`');
});

test('the option is parsed and documented', function () use ($source) {
	expect($source)->toContain("'missing-tables',")
		->and($source)->toContain("case 'missing-tables':")
		->and($source)->toContain('--missing-tables - ');
});
