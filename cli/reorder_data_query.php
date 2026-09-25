#!/usr/bin/env php
<?php
/**
 * reorder_data_query.php
 *
 * Reorders the fields returned by a data query.
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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

require(__DIR__ . '/../include/cli_check.php');
require_once($config['base_path'] . '/lib/snmp.php');
require_once($config['base_path'] . '/lib/data_query.php');

ini_set('max_execution_time', '0');

if ($config['poller_id'] > 1) {
	print "FATAL: This utility is designed for the main Data Collector only" . PHP_EOL;
	exit(1);
}

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$debug		= false;
$host_id	= 'all';
$query_id	= 'all';
$scope_given = false;
$host_descr	= '';

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter, 2);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-id':
			case '--id':
			case '--host-id':
				$host_id = $value;
				$scope_given = true;
				break;
			case '-qid':
			case '--qid':
				$query_id = $value;
				$scope_given = true;
				break;
			case '-d':
			case '--debug':
				$debug = true;
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
				print 'ERROR: Invalid Parameter ' . $parameter . "\n\n";
				display_help();
				exit(1);
		}
	}
} else {
	print "ERROR: You must supply input parameters\n\n";
	display_help();
	exit(1);
}

if (!$scope_given) {
	print "ERROR: Specify --host-id, --qid, or both to select the records to reorder.\n";
	display_help();
	exit(1);
}


$sql_where = "WHERE data_input_fields.type_code='output_type'";
$query_params = array();

/* determine the hosts to reindex */
if (strtolower($host_id) == 'all') {
	/* NOP */
} elseif (ctype_digit((string) $host_id) && (int) $host_id > 0) {
	$sql_where .= ' AND data_local.host_id = ?';
	$query_params[] = (int) $host_id;
} else {
	print "ERROR: You must specify either a host_id or 'all' to proceed.\n";
	display_help();
	exit(1);
}

/* determine data queries to rerun */
if (strtolower($query_id) == 'all') {
	/* NOP */
} elseif (ctype_digit((string) $query_id) && (int) $query_id > 0) {
	$sql_where .= ' AND data_local.snmp_query_id = ?';
	$query_params[] = (int) $query_id;
} else {
	print "ERROR: You must specify either a query_id or 'all' to proceed.\n";
	display_help();
	exit(1);
}

/* get all object that have to be scanned */
$data_queries = db_fetch_assoc_prepared("SELECT data_local.host_id, data_local.snmp_query_id,
	data_local.snmp_index, data_template_data.local_data_id, data_template_data.data_input_id,
	data_input_data.data_template_data_id, data_input_data.data_input_field_id, data_input_data.value
	FROM data_local
	LEFT JOIN data_template_data
	ON data_local.id=data_template_data.local_data_id
	LEFT JOIN data_input_fields
	ON data_template_data.data_input_id = data_input_fields.data_input_id
	LEFT JOIN data_input_data
	ON data_template_data.id = data_input_data.data_template_data_id
	AND data_input_fields.id = data_input_data.data_input_field_id
	$sql_where", $query_params);

if ($data_queries === false) {
	fwrite(STDERR, "ERROR: Unable to query data query index items.\n");
	exit(1);
}

/* issue warnings and start message if applicable */
print "WARNING: Do not interrupt this script.  Reordering can take quite some time\n";
debug("There are '" . cacti_sizeof($data_queries) . "' data query index items to run");

$i = 1;
$failed = false;
if (cacti_sizeof($data_queries)) {
	foreach ($data_queries as $data_query) {
		if (!$debug) print '.';
		/* fetch current index_order from data_query XML definition and put it into host_snmp_query */
		if (update_data_query_sort_cache($data_query['host_id'], $data_query['snmp_query_id']) === false) {
			fwrite(STDERR, "ERROR: Unable to determine sort field for host {$data_query['host_id']} query {$data_query['snmp_query_id']}\n");
			$failed = true;
			continue;
		}
		/* build array required for function call */
		$data_query['snmp_index_on'] = get_best_data_query_index_type($data_query['host_id'], $data_query['snmp_query_id']);
		if ($data_query['snmp_index_on'] === false || $data_query['snmp_index_on'] === '') {
			fwrite(STDERR, "ERROR: Unable to determine index field for host {$data_query['host_id']} query {$data_query['snmp_query_id']}\n");
			$failed = true;
			continue;
		}
		/* as we request the output_type, 'value' gives the snmp_query_graph_id */
		$data_query['snmp_query_graph_id'] = $data_query['value'];
		debug("Data Query #'" . $i . "' host: '" . $data_query['host_id'] .
			"' SNMP Query Id: '" . $data_query['snmp_query_id'] .
			"' Index: " . $data_query['snmp_index'] .
			"' Index On: " . $data_query['snmp_index_on']
			);
		if (!update_snmp_index_order($data_query)) {
			fwrite(STDERR, "ERROR: Unable to update index data for host {$data_query['host_id']} query {$data_query['snmp_query_id']} index {$data_query['snmp_index']}\n");
			$failed = true;
		}
		$i++;
	}
}

exit($failed ? 1 : 0);

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Reorder Data Query Utility, Version $version, " . COPYRIGHT_YEARS . "\n";
}

/*	display_help - displays the usage of the function */
function display_help () {
	display_version();

	print "\nusage: reorder_data_query.php --host-id=[id|all] | --qid=[query_id|all] [--debug|-d]\n\n";
	print "A utility to Re-order Cacti Data Queries for a Device or system in batch mode.\n\n";
	print "Required:\n";
	print "    --host-id=N    - The Device id to be reindexed; 'all' selects every Device.\n\n";
	print "Optional:\n";
	print "    --qid=query_id - Only index on a specific data query id; 'all' selects every query.\n";
	print "    --debug | -d   - Display verbose output during execution\n\n";
}

function debug($message) {
	global $debug;

	if ($debug) {
		print "DEBUG: " . trim($message) . "\n";
	}
}
