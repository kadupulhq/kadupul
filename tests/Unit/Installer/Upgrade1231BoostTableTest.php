<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Both installers stop at the first version whose upgrade reports an error.
 * A missing poller_output_boost_processes table must therefore not fail
 * upgrade_to_1_2_31(), or upgrade_to_1_2_32() never runs to create it.
 */

$root = dirname(__DIR__, 3);

function upgrade1231TestReset($table) {
	$GLOBALS['upgrade1231_test_state'] = array(
		'table' => $table,
		'sql'   => array(),
	);
}

function upgrade1231TestTableExists($table) {
	return $table != 'poller_output_boost_processes' || $GLOBALS['upgrade1231_test_state']['table'];
}

function upgrade1231TestIndexExists($table, $index) {
	return false;
}

function upgrade1231TestExecute($sql) {
	$GLOBALS['upgrade1231_test_state']['sql'][] = $sql;
}

function upgrade1231TestAddColumn($table, $column) {
	$GLOBALS['upgrade1231_test_state']['sql'][] = 'ALTER TABLE `' . $table . '` ADD `' . $column['name'] . '`';
}

function upgrade1231TestAddKey($table, $type, $key, $columns) {
	$GLOBALS['upgrade1231_test_state']['sql'][] = 'ALTER TABLE `' . $table . '` ADD ' . $type . ' ' . $key;
}

function upgrade1231LoadFunction($root) {
	if (function_exists('upgrade1231TestUpgrade')) {
		return;
	}

	$source = file_get_contents($root . '/install/upgrades/1_2_31.php');
	expect($source)->not->toBeFalse();

	$start = strpos($source, 'function upgrade_to_1_2_31(');
	expect($start)->not->toBeFalse();

	$function = str_replace(array(
		'upgrade_to_1_2_31',
		'db_table_exists',
		'db_index_exists',
		'db_install_execute',
		'db_install_add_column',
		'db_install_add_key',
	), array(
		'upgrade1231TestUpgrade',
		'upgrade1231TestTableExists',
		'upgrade1231TestIndexExists',
		'upgrade1231TestExecute',
		'upgrade1231TestAddColumn',
		'upgrade1231TestAddKey',
	), substr($source, $start));

	eval($function);
}

function upgrade1231BoostStatements() {
	return array_values(array_filter($GLOBALS['upgrade1231_test_state']['sql'], function ($sql) {
		return strpos($sql, 'poller_output_boost_processes') !== false;
	}));
}

beforeEach(function () use ($root) {
	upgrade1231LoadFunction($root);
});

test('upgrade_to_1_2_31 leaves a missing Boost process table for the 1.2.32 repair', function () {
	upgrade1231TestReset(false);
	upgrade1231TestUpgrade();

	expect(upgrade1231BoostStatements())->toBe(array())
		->and($GLOBALS['upgrade1231_test_state']['sql'])->toContain('ALTER TABLE settings_user MODIFY COLUMN name varchar(255) NOT NULL default ""');
});

test('upgrade_to_1_2_31 still adds the Boost process columns and key when the table exists', function () {
	upgrade1231TestReset(true);
	upgrade1231TestUpgrade();

	expect(upgrade1231BoostStatements())->toBe(array(
		'TRUNCATE TABLE poller_output_boost_processes',
		'ALTER TABLE `poller_output_boost_processes` ADD `run_id`',
		'ALTER TABLE `poller_output_boost_processes` ADD `child_id`',
		'ALTER TABLE `poller_output_boost_processes` ADD UNIQUE run_child',
	));
});
