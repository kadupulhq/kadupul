#!/usr/bin/env php
<?php
/**
 * add_graph_template.php
 *
 * Associates a graph template with a device.
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

/* switch to main database for cli's */
if ($config['poller_id'] > 1) {
	db_switch_remote_to_main();
}

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

if (cacti_sizeof($parms)) {
	$displayHosts 			= false;
	$displayGraphTemplates 	= false;
	$quietMode				= false;
	unset($host_id);
	unset($graph_template_id);

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
		case '--graph-template-id':
			$graph_template_id = $value;
			if (!ctype_digit($graph_template_id) || (int) $graph_template_id <= 0) {
				fwrite(STDERR, "ERROR: Supply a positive integer graph template ID.\n");
				exit(1);
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
		case '--list-graph-templates':
			$displayGraphTemplates = true;
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

	/* list options, recognizing $quiteMode */
	if ($displayHosts) {
		$hosts = getHosts();
		if (!displayHosts($hosts, $quietMode)) {
			fwrite(STDERR, "ERROR: Unable to list records because the database query failed.\n");
			exit(1);
		}
		exit(0);
	}

	if ($displayGraphTemplates) {
		$graphTemplates = getGraphTemplates();
		if (!displayGraphTemplates($graphTemplates, $quietMode)) {
			fwrite(STDERR, "ERROR: Unable to list records because the database query failed.\n");
			exit(1);
		}
		exit(0);
	}

	/*
	 * verify required parameters
	 * for update / insert options
	 */
	if (!isset($host_id)) {
		print "ERROR: You must supply a valid host-id for all hosts!\n";
		exit(1);
	}

	if (!isset($graph_template_id)) {
		print "ERROR: You must supply a valid data-query-id for all hosts!\n";
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
	 * verify valid graph template and get a name for it
	 */
	$graph_template_name = db_fetch_cell_prepared('SELECT name FROM graph_templates WHERE id = ?', array((int) $graph_template_id));
	if ($graph_template_name === false || $graph_template_name === null) {
		print "ERROR: Unknown Graph Template Id ($graph_template_id)\n";
		exit(1);
	}

	/* check, if graph template was already associated */
	$exists_already = add_graph_template_association_exists((int) $host_id, (int) $graph_template_id);
	if ($exists_already === null) {
		fwrite(STDERR, "ERROR: Could not verify whether the graph template is already associated.\n");
		exit(1);
	}

	if ($exists_already) {
		print "ERROR: Graph Template is already associated for host: ($host_id: $host_name) - graph-template: ($graph_template_id: $graph_template_name)\n";
		exit(1);
	} else {
		if (!db_execute_prepared('REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)', array((int) $host_id, (int) $graph_template_id))) {
			fwrite(STDERR, "ERROR: Failed to associate the graph template with the host.\n");
			exit(1);
		}

		automation_hook_graph_template($host_id, $graph_template_id);

		api_plugin_hook_function('add_graph_template_to_host', array("host_id" => $host_id, "graph_template_id" => $graph_template_id));
	}

	if (is_error_message()) {
		print "ERROR: Failed to add this graph template for host: ($host_id: $host_name) - graph-template: ($graph_template_id: $graph_template_name)\n";
		exit(1);
	} else {
		print "Success: Graph Template associated for host: ($host_id: $host_name) - graph-template: ($graph_template_id: $graph_template_name)\n";
		exit(0);
	}
} else {
	display_help();
	exit(0);
}

function add_graph_template_association_exists($host_id, $graph_template_id) {
	$count = db_fetch_cell_prepared('SELECT COUNT(*) FROM host_graph WHERE graph_template_id = ? AND host_id = ?', array($graph_template_id, $host_id));

	if ($count === false || !is_numeric($count)) {
		return null;
	}

	return (int) $count > 0;
}

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Add Graph Template Utility, Version $version, " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	print "\nusage: add_graph_template.php --host-id=[ID] --graph-template-id=[ID]\n";
	print "    [--quiet]\n\n";
	print "Required:\n";
	print "    --host-id             the numerical ID of the host\n";
	print "    --graph-template-id   the numerical ID of the graph template to be added\n\n";
	print "List Options:\n";
	print "    --list-hosts\n";
	print "    --list-graph-templates\n";
	print "    --quiet - batch mode value return\n\n";
}
