#!/usr/bin/env php
<?php
/**
 * add_data_query.php
 *
 * Associates a data query with a Cacti device and runs the initial reindex.
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
require_once($config['base_path'] . '/lib/api_automation_tools.php');
require_once($config['base_path'] . '/lib/api_automation.php');
require_once($config['base_path'] . '/lib/api_data_source.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/snmp.php');
require_once($config['base_path'] . '/lib/sort.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/utility.php');

/* switch to main database for cli's */
if ($config['poller_id'] > 1) {
	db_switch_remote_to_main();
}

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

if (cacti_sizeof($parms)) {
	$displayHosts 		= false;
	$displayDataQueries = false;
	$quietMode			= false;

	unset($host_id);
	unset($data_query_id);
	unset($reindex_method);

	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter, 2);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-d':
				$debug = true;

				break;
			case '--host-id':
				$host_id = trim($value);
				if (!ctype_digit($host_id) || (int) $host_id <= 0) {
					fwrite(STDERR, "ERROR: Supply a positive integer host ID.\n");
					exit(1);
				}

				break;
			case '--data-query-id':
				$data_query_id = $value;
				if (!ctype_digit($data_query_id) || (int) $data_query_id <= 0) {
					fwrite(STDERR, "ERROR: Supply a positive integer data query ID.\n");
					exit(1);
				}

				break;
			case '--reindex-method':
				if (ctype_digit($value) &&
					(int) $value >= DATA_QUERY_AUTOINDEX_NONE &&
					($value <= DATA_QUERY_AUTOINDEX_FIELD_VERIFICATION)) {
					$reindex_method = (int) $value;
				} else {
					switch (strtolower($value)) {
						case 'none':
							$reindex_method = DATA_QUERY_AUTOINDEX_NONE;
							break;
						case 'uptime':
							$reindex_method = DATA_QUERY_AUTOINDEX_BACKWARDS_UPTIME;
							break;
						case 'index':
							$reindex_method = DATA_QUERY_AUTOINDEX_INDEX_NUM_CHANGE;
							break;
						case 'fields':
							$reindex_method = DATA_QUERY_AUTOINDEX_FIELD_VERIFICATION;
							break;
						default:
							print "ERROR: You must supply a valid reindex method for all hosts!\n";
							exit(1);
					}
				}
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
			case '--list-hosts':
				$displayHosts = true;
				break;
			case '--list-data-queries':
				$displayDataQueries = true;
				break;
			case '--quiet':
				$quietMode = true;
				break;
			default:
				print "ERROR: Invalid Argument: ($arg)\n\n";
				display_help();
				exit(1);
		}
	}

	/* list options, recognizing $quietMode */
	if ($displayHosts) {
		$hosts = getHosts();
		displayHosts($hosts, $quietMode);
		exit;
	}
	if ($displayDataQueries) {
		$data_queries = getSNMPQueries();
		displaySNMPQueries($data_queries, $quietMode);
		exit;
	}

	/*
	 * verify required parameters
	 * for update / insert options
	 */
	if (!isset($host_id)) {
		print "ERROR: You must supply a valid host-id for all hosts!\n";
		exit(1);
	}

	if (!isset($data_query_id)) {
		print "ERROR: You must supply a valid data-query-id for all hosts!\n";
		exit(1);
	}

	if (!isset($reindex_method)) {
		print "ERROR: You must supply a valid reindex-method for all hosts!\n";
		exit(1);
	}


	/*
	 * verify valid host id and get a name for it
	 */
	$host_name = db_fetch_cell_prepared('SELECT hostname FROM host WHERE id = ?', array((int) $host_id));
	if ($host_name === false || $host_name === null) {
		print "ERROR: Unknown Host Id ($host_id)\n";
		exit(1);
	}

	/*
	 * verify valid data query and get a name for it
	 */
	$data_query_name = db_fetch_cell_prepared('SELECT name FROM snmp_query WHERE id = ?', array((int) $data_query_id));
	if ($data_query_name === false || $data_query_name === null) {
		print "ERROR: Unknown Data Query Id ($data_query_id)\n";
		exit(1);
	}

	/*
	 * Now, add the data query and run it once to get the cache filled
	 */
	$exists_already = db_fetch_cell_prepared('SELECT host_id FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ? AND reindex_method = ?', array((int) $host_id, (int) $data_query_id, (int) $reindex_method));
	if ((isset($exists_already)) &&
		($exists_already > 0)) {
		print "ERROR: Data Query is already associated for host: ($host_id: $host_name) data query ($data_query_id: $data_query_name) reindex method ($reindex_method: " . $reindex_types[$reindex_method] . ")\n";
		exit(1);
	} else {
		if (!db_execute_prepared('REPLACE INTO host_snmp_query
			(host_id, snmp_query_id, reindex_method)
			VALUES (?, ?, ?)', array((int) $host_id, (int) $data_query_id, (int) $reindex_method))) {
			fwrite(STDERR, "ERROR: Failed to associate the data query.\n");
			exit(1);
		}

		/* recache snmp data */
		if (!run_data_query((int) $host_id, (int) $data_query_id)) {
			fwrite(STDERR, "ERROR: Data query association was saved, but its initial reindex failed.\n");
			exit(1);
		}
	}

	if (is_error_message()) {
		print "ERROR: Failed to add this data query for host ($host_id: $host_name) data query ($data_query_id: $data_query_name) reindex method ($reindex_method: " . $reindex_types[$reindex_method] . ")\n";
		exit(1);
	} else {
		print "Success - Host ($host_id: $host_name) data query ($data_query_id: $data_query_name) reindex method ($reindex_method: " . $reindex_types[$reindex_method] . ")\n";
		exit;
	}
} else {
	display_help();
	exit;
}

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Add Data Query Utility, Version $version, " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	print "\nusage: add_data_query.php --host-id=[ID] --data-query-id=[dq_id] --reindex-method=[method] [--quiet]\n\n";
	print "Required Options:\n";
	print "    --host-id         the numerical ID of the host\n";
	print "    --data-query-id   the numerical ID of the data_query to be added\n";
	print "    --reindex-method  the reindex method to be used for that data query\n";
	print "                      0|None   = no reindexing\n";
	print "                      1|Uptime = Uptime goes Backwards\n";
	print "                      2|Index  = Index Count Changed\n";
	print "                      3|Fields = Verify all Fields\n\n";
	print "List Options:\n";
	print "    --list-hosts\n";
	print "    --list-data-queries\n";
	print "    --quiet - batch mode value return\n\n";
	print "If the data query was already associated, it will be reindexed.\n\n";
}
