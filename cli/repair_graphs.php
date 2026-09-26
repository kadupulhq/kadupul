#!/usr/bin/env php
<?php
/**
 * repair_graphs.php
 *
 * Repairs graph item references to a selected data template.
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

/* Original script is located here https://forums.cacti.net/viewtopic.php?t=35816, but this one was modified quite a lot */

require(__DIR__ . '/../include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/lib/template.php');

/* switch to main database for cli's */
if ($config['poller_id'] > 1) {
	db_switch_remote_to_main();
}

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

$execute  = false;
$show_sql = false;

unset($host_id);
unset($graph_template_id);
unset($data_template_id);

foreach($parms as $parameter) {
	if (strpos($parameter, '=')) {
		list($arg, $value) = explode('=', $parameter, 2);
	} else {
		$arg = $parameter;
		$value = '';
	}

	switch ($arg) {
		case "--execute":
			$execute = true;
			break;
		case "--show-sql":
			$show_sql = true;
			break;
		case "--host-id":
			$host_id = trim($value);
			if (!ctype_digit($host_id) || (int) $host_id <= 0) {
				print "ERROR: You must supply a positive integer host-id to run this script!\n";
				exit(1);
			}
			break;
		case "--graph-template-id":
			$graph_template_id = $value;
			if (!ctype_digit($graph_template_id) || (int) $graph_template_id <= 0) {
				print "ERROR: You must supply a positive integer graph-template-id!\n";
				exit(1);
			}
			break;
		case "--data-template-id":
			$data_template_id = $value;
			if (!ctype_digit($data_template_id) || (int) $data_template_id <= 0) {
				print "ERROR: You must supply a positive integer data-template-id!\n";
				exit(1);
			}
			break;
		case "--version":
		case "-v":
		case "-V":
			display_version();
			exit(0);
		case "--help":
		case "-h":
		case "-H":
			display_help();
			exit(0);
		default:
			print "ERROR: Invalid Parameter " . $parameter . "\n\n";
			display_help();
			exit(1);
	}
}

if (!$show_sql && !$execute) {
	display_help();
	exit(1);
}

if (!isset($data_template_id)) {
	print "ERROR: You must supply a valid data-template-id!\n";
	exit(1);
}

if (!isset($graph_template_id)) {
	print "ERROR: You must supply a valid graph-template-id!\n";
	exit(1);
}

if ($execute) {
	print "NOTE: Repairing Graphs\n";
} else {
	print "NOTE: Performing Check of Graphs\n";
}

// Get all graphs for supplied graph template
$graph_sql = 'SELECT * FROM graph_local WHERE graph_template_id = ?';
$graph_params = array((int) $graph_template_id);

if (isset($host_id)) {
	$graph_sql .= ' AND host_id = ?';
	$graph_params[] = (int) $host_id;
}

$graph = db_fetch_assoc_prepared($graph_sql, $graph_params);
$repair_failed = false;

if ($graph === false) {
	fwrite(STDERR, "ERROR: Unable to query graphs for repair.\n");
	exit(1);
}

if (cacti_sizeof($graph)) {
	if (!$show_sql) {
		print "\nCorrupted graphs:\n";
	}

	foreach($graph as $g) {
		// Get datasource for supplied data template for current host
		$ds = db_fetch_assoc_prepared('SELECT * FROM data_local WHERE host_id = ? AND data_template_id = ?', array((int) $g['host_id'], (int) $data_template_id));
		if ($ds === false) {
			fwrite(STDERR, "ERROR: Unable to query datasource for graph {$g['id']}.\n");
			$repair_failed = true;
			continue;
		}
		if (!cacti_sizeof($ds)) {
			continue;
		}
		$ds = $ds[0];

		// Get rrd for found datasource
		$rrd_data = db_fetch_assoc_prepared('SELECT * FROM data_template_rrd WHERE local_data_id = ?', array((int) $ds['id']));
		if ($rrd_data === false) {
			fwrite(STDERR, "ERROR: Unable to query RRD data for datasource {$ds['id']}.\n");
			$repair_failed = true;
			continue;
		}
		if (!cacti_sizeof($rrd_data)) {
			print "Could not get correct rrd id for datasource=" . $ds["id"] . "\n";
			continue;
		}

		/*
		// Here we will find graph items that should point to our data template
		// Get templated rrd id for given data template
		select id from data_template_rrd where local_data_template_rrd_id=0 and local_data_id=0 and data_template_id=520
		// Get templated graph->rrd association
		select id from graph_templates_item where local_graph_template_item_id=0 and local_graph_id=0 and task_item_id=
		// Get graph associations which corresponds to supplied data template
		select id from graph_templates_item where local_graph_id=$g["id"] and local_graph_template_item_id in
		// But I'm too lazy to write such a lot of code, so let's better make one long query below
		*/

		$graph_templates_items_wrong = db_fetch_assoc_prepared('SELECT id, task_item_id FROM graph_templates_item
			WHERE task_item_id != ? AND graph_template_id = ? AND local_graph_id = ?
			AND local_graph_template_item_id IN (
				SELECT id FROM graph_templates_item
				WHERE local_graph_template_item_id = 0 AND local_graph_id = 0
				AND task_item_id = (
					SELECT id FROM data_template_rrd
					WHERE local_data_template_rrd_id = 0 AND local_data_id = 0 AND data_template_id = ?
				)
			)', array((int) $rrd_data[0]['id'], (int) $graph_template_id, (int) $g['id'], (int) $data_template_id));
		if ($graph_templates_items_wrong === false) {
			fwrite(STDERR, "ERROR: Unable to query graph items for graph {$g['id']}.\n");
			$repair_failed = true;
			continue;
		}
		if (!cacti_sizeof($graph_templates_items_wrong)) {
			// Everything correct here.
			continue;
		} else {
		$graph_templates_item = array();
		$task_item_id = array();

			foreach($graph_templates_items_wrong as $graph_templates_item_wrong) {
				// Here is a list of graph_templates_item ids to be fixed and their wrong task_item_id
				$graph_templates_item[] = $graph_templates_item_wrong["id"];
				$task_item_id[] = $graph_templates_item_wrong["task_item_id"];
			}
		}

		print "Host " . $g["host_id"] . ", graph " . $g["id"] . ", graph item " . implode(",",$graph_templates_item) . ", task_item_id " . implode(",",$task_item_id) . "->" . $rrd_data[0]["id"] . "\n";

		$id_placeholders = implode(',', array_fill(0, cacti_sizeof($graph_templates_item), '?'));
		$query = 'UPDATE graph_templates_item SET task_item_id = ?
			WHERE task_item_id != ? AND graph_template_id = ? AND local_graph_id = ?
			AND id IN (' . $id_placeholders . ')';
		$query_params = array_merge(array((int) $rrd_data[0]['id'], (int) $rrd_data[0]['id'], (int) $graph_template_id, (int) $g['id']), array_map('intval', $graph_templates_item));

		if ($show_sql) {
			print $query . ";\n";
		}
		if ($execute) {
			if (db_execute_prepared($query, $query_params) === false) {
				fwrite(STDERR, "ERROR: Failed to repair graph {$g['id']} on host {$g['host_id']}.\n");
				$repair_failed = true;
			}
		}
		unset($graph_templates_item);
		unset($task_item_id);
	}
}

if ($repair_failed) {
	exit(1);
}

function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Graph Repair Tool, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/* display_help - displays the usage of the function */
function display_help() {
	print "usage: repair_graphs.php [--host-id=ID] --data-template-id=[ID]\n";
	print "	--graph-template-id=[ID] [--show-sql] [--execute]\n\n";
	print "Cacti utility for repairing graph<->datasource relationship via a command line interface.\n\n";
	print "--execute - Perform the repair\n";
	print "--show-sql - Show SQL lines for the repair (optional)\n";
	print "--host-id=id - The host_id to repair or leave empty to process all hosts\n";
	print "--data-template-id=id - The numerical ID of the data template to be fixed\n";
	print "--graph-template-id=id - The numerical ID of the graph template to be fixed\n";
}
