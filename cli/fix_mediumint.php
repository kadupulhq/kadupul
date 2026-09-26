#!/usr/bin/env php
<?php
/**
 * fix_mediumint.php
 *
 * Widens supported MEDIUMINT columns in the Cacti database.
 *
 * @package Cacti\CLI
 */
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

require(__DIR__ . '/../include/cli_check.php');

ini_set('max_execution_time', '0');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$debug = false;
$local = false;
$dry_run = false;
$failures = 0;

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter, 2);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-d':
			case '--debug':
				$debug = true;
				break;
			case '--local':
				$local = true;
				break;
			case '--dry-run':
				$dry_run = true;
				break;
			case '--version':
			case '-V':
			case '-v':
				display_version();
				exit(0);
			case '--help':
			case '-H':
			case '-h':
				display_help();
				exit(0);
			default:
				print 'ERROR: Invalid Parameter ' . $parameter . PHP_EOL . PHP_EOL;
				display_help();
				exit(1);
		}
	}
}

if (!$local && $config['poller_id'] > 1) {
	db_switch_remote_to_main();

	print 'NOTE: Fixing MediumInt Columns for Main Database' . PHP_EOL;
} else {
	print 'NOTE: Fixing MediumInt Columns for Local Database' . PHP_EOL;
}

$total = database_fix_mediumint_columns();

print ($dry_run ? "NOTE: Column widths would be adjusted on $total Tables." : "NOTE: Column widths adjusted on $total Tables!") . PHP_EOL;
if ($failures > 0) {
	print "ERROR: Failed to update $failures Tables." . PHP_EOL;
	exit(1);
}

function database_fix_mediumint_columns() {
	global $database_default, $failures, $dry_run;

	$total = 0;

	// Known Tables
	$tables = array(
		'data_input_data' => 'data_template_data_id',

		'data_template_data' => 'id, local_data_template_data_id, local_data_id',
		'data_template_rrd'  => 'id, local_data_template_rrd_id, local_data_id',

		'graph_local' => 'id',
		'data_local'  => 'id',

		'data_source_purge_action'       => 'local_data_id',
		'data_source_purge_temp'         => 'local_data_id',
		'data_source_stats_daily'        => 'local_data_id',
		'data_source_stats_hourly'       => 'local_data_id',
		'data_source_stats_hourly_cache' => 'local_data_id',
		'data_source_stats_hourly_last'  => 'local_data_id',
		'data_source_stats_monthly'      => 'local_data_id',
		'data_source_stats_weekly'       => 'local_data_id',
		'data_source_stats_yearly'       => 'local_data_id',

		'graph_templates_graph'     => 'id, local_graph_id, local_graph_template_graph_id',
		'graph_template_input_defs' => 'graph_template_item_id',
		'graph_templates_item'      => 'id, local_graph_template_item_id, local_graph_id, task_item_id',
		'graph_tree_items'          => 'local_graph_id',

		'poller_item'            => 'local_data_id',
		'poller_output'          => 'local_data_id',
		'poller_output_boost'    => 'local_data_id',
		'poller_output_realtime' => 'local_data_id',

		'settings_tree'        => 'graph_tree_item_id',
		'snmp_query_graph_rrd' => 'data_template_rrd_id'
	);

	$known_columns['graph_id'] = 'graph_id';
	$known_columns['data_id']  = 'data_id';

	foreach($tables as $table => $columns) {
		$columns = explode(',', $columns);

		$sql = 'ALTER TABLE ' . database_quote_identifier($table);
		$i = 0;
		foreach($columns as $c) {
			$c = trim($c);

			$attribs = database_get_column_attribs($table, $c);

			if (cacti_sizeof($attribs)) {
				$clause = database_mediumint_column_clause($c, $attribs);
				if ($clause === false) {
					debug("Skipping non-MEDIUMINT column $c in Table $table (type {$attribs['Type']}).");
					if (stripos($attribs['Type'], 'int(10) unsigned') !== false && $c != 'id') {
						$known_columns[$c] = $c;
					}
					continue;
				}

				if ($c != 'id') {
					$known_columns[$c] = $c;
				}

				$sql .= ($i == 0 ? '' : ', ') . $clause;
				$i++;
			} else {
				debug("ERROR: Attributes missing for $table and column $c.");
			}
		}

		if ($i > 0) {
			debug("Updating Table $table.");
			if ($dry_run) {
				print 'DRY RUN: ' . $sql . PHP_EOL;
				$total++;
			} elseif (db_execute($sql) === false) {
				$failures++;
				debug("ERROR: Failed to update Table $table.");
			} else {
				$total++;
			}
		}
	}

	$other_tables = db_fetch_assoc('SHOW TABLES');

	foreach($other_tables as $t) {
		$table   = $t['Tables_in_' . $database_default];
		$columns = array();

		//print "Checking $table" . PHP_EOL;

		if (!array_key_exists($table, $tables)) {
			$sql = 'ALTER TABLE ' . database_quote_identifier($table);
			$i = 0;
			$columns = array_rekey(
				db_fetch_assoc("SHOW COLUMNS FROM " . database_quote_identifier($table)),
					'Field', array('Type', 'Null', 'Key', 'Default', 'Extra')
			);

			foreach($columns as $field => $attribs) {
				if (array_key_exists($field, $known_columns)) {
					$clause = database_mediumint_column_clause($field, $attribs);
					if ($clause === false) {
						continue;
					}

					$sql .= ($i == 0 ? '' : ', ') . $clause;
					$i++;
				}
			}

			if ($i > 0) {
				debug("Updating Table $table.");
				if ($dry_run) {
					print 'DRY RUN: ' . $sql . PHP_EOL;
					$total++;
				} elseif (db_execute($sql) === false) {
					$failures++;
					debug("ERROR: Failed to update Table $table.");
				} else {
					$total++;
				}
			}
		}
	}

	return $total;
}

function database_mediumint_column_clause($column, $attribs) {
	if (stripos($attribs['Type'], 'mediumint') === false) {
		return false;
	}

	$column = database_quote_identifier($column);
	if (strtolower($attribs['Extra']) == 'auto_increment') {
		return ' MODIFY COLUMN ' . $column . ' int(10) unsigned NOT NULL AUTO_INCREMENT';
	}

	$default = $attribs['Default'];
	$nullability = $attribs['Null'] == 'NO' ? ' NOT NULL' : ' NULL';
	if ($default !== null && $default !== '') {
		$default = is_numeric($default) ? (string) $default : db_qstr($default);
		return ' MODIFY COLUMN ' . $column . ' int(10) unsigned' . $nullability . ' DEFAULT ' . $default;
	}

	if ($attribs['Null'] == 'NO') {
		return ' MODIFY COLUMN ' . $column . ' int(10) unsigned NOT NULL';
	}

	return ' MODIFY COLUMN ' . $column . ' int(10) unsigned NULL DEFAULT NULL';
}

function database_quote_identifier($identifier) {
	return '`' . str_replace('`', '``', $identifier) . '`';
}

function database_get_column_attribs($table, $column) {
	return db_fetch_row("SHOW COLUMNS FROM $table LIKE '$column'");
}

function debug($string) {
	global $debug;

	if ($debug) {
		print 'DEBUG: ' . trim($string) . PHP_EOL;
	}
}

function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Fix Database Range Issue, Version $version, " . COPYRIGHT_YEARS . "\n";
}

/*	display_help - displays the usage of the function */
function display_help () {
	display_version();
	print 'usage: fix_mediumint.php [--debug] [--dry-run]' . PHP_EOL . PHP_EOL;
	print 'Options:' . PHP_EOL;
	print '--debug    - Display verbose output during execution' . PHP_EOL;
	print '--local    - Perform the action on the Remote Data Collector if run from there' . PHP_EOL . PHP_EOL;
	print '--dry-run  - Print proposed ALTER statements without executing them' . PHP_EOL . PHP_EOL;
	print 'This utility is used to increase the size of key Cacti columns to accomodate' . PHP_EOL;
	print 'systems with over a million graphs and that have been in service for years.' . PHP_EOL;
	print 'After some long amount of time, Cacti can run out of auto_increment fields.' . PHP_EOL;
}
