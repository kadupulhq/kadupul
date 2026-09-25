#!/usr/bin/env php
<?php
/**
 * add_perms.php
 *
 * Grants a user access to selected Cacti objects.
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

if (cacti_sizeof($parms) == 0) {
	display_help();

	exit(1);
} else {
	$userId    = 0;

	/* TODO replace magic numbers by global constants, treat user_admin as well */
	$itemTypes = array('graph' => 1, 'tree' => 2, 'host' => 3, 'graph_template' => 4);

	$itemType = 0;
	$itemTypeName = '';
	$itemId   = 0;
	$hostId   = 0;

	$quietMode				= false;
	$displayGroups			= false;
	$displayUsers			= false;
	$displayTrees			= false;
	$displayHosts			= false;
	$displayGraphs			= false;
	$displayGraphTemplates 	= false;

	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter, 2);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
		case '--user-id':
			$userId = $value;

			break;
		case '--item-type':
			/* TODO replace magic numbers by global constants, treat user_admin as well */
			if ( ($value == 'graph') || ($value == 'tree') || ($value == 'host') || ($value == 'graph_template')) {
				$itemType = $itemTypes[$value];
				$itemTypeName = $value;
			} else {
				print "ERROR: Invalid Item Type: ($value)\n\n";
				display_help();
				exit(1);
			}

			break;
		case '--item-id':
			$itemId = $value;

			break;
		case '--host-id':
			$hostId = $value;

			break;
		case '--list-groups':
			$displayGroups = true;

			break;
		case '--list-users':
			$displayUsers = true;

			break;
		case '--list-trees':
			$displayTrees = true;

			break;
		case '--list-hosts':
			$displayHosts = true;

			break;
		case '--list-graphs':
			$displayGraphs = true;

			break;
		case '--list-graph-templates':
			$displayGraphTemplates = true;

			break;
		case '--quiet':
			$quietMode = true;

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
			print "ERROR: Invalid Argument: ($arg)\n\n";
			display_help();
			exit(1);
		}
	}

	if ($displayGroups) {
		displayGroups();
		exit(2);
	}

	if ($displayUsers) {
		displayUsers($quietMode);
		exit(0);
	}

	if ($displayTrees) {
		displayTrees($quietMode);
		exit(0);
	}

	if ($displayHosts) {
		$hosts = getHosts();
		displayHosts($hosts, $quietMode);
		exit(0);
	}

	if ($displayGraphs) {
		if (!ctype_digit((string) $hostId) || (int) $hostId < 1 || !db_fetch_cell_prepared('SELECT id FROM host WHERE id = ?', array((int) $hostId))) {
			print "ERROR: You must supply a valid host_id before you can list its graphs\n";
			print "Try --list-hosts\n";
			display_help();
			exit(1);
		} else {
			displayHostGraphs($hostId, $quietMode);
			exit(0);
		}
	}

	if ($displayGraphTemplates) {
		$graphTemplates = getGraphTemplates();
		displayGraphTemplates($graphTemplates, $quietMode);
		exit(0);
	}

	/* Verify the target user before changing object permissions. */
	if (!ctype_digit((string) $userId) || (int) $userId < 1 || !db_fetch_cell_prepared('SELECT id FROM user_auth WHERE id = ?', array((int) $userId))) {
		print "ERROR: A valid --user-id is required.\n\n";
		display_help();
		exit(1);
	}
	$userId = (int) $userId;

	/* verify --item-id */
	if ($itemType == 0) {
		print "ERROR: --item-type missing. Please specify.\n\n";
		display_help();
		exit(1);
	}

	if (!ctype_digit((string) $itemId) || (int) $itemId < 1) {
		print "ERROR: --item-id missing. Please specify.\n\n";
		display_help();
		exit(1);
	}
	$itemId = (int) $itemId;

	/* TODO replace magic numbers by global constants, treat user_admin as well */
	switch ($itemType) {
		case 1: /* graph */
			if (!db_fetch_cell_prepared('SELECT local_graph_id FROM graph_templates_graph WHERE local_graph_id = ?', array($itemId))) {
				print "ERROR: Invalid Graph item id: ($itemId)\n\n";
				display_help();
				exit(1);
			}
			break;
		case 2: /* tree */
			if (!db_fetch_cell_prepared('SELECT id FROM graph_tree WHERE id = ?', array($itemId))) {
				print "ERROR: Invalid Tree item id: ($itemId)\n\n";
				display_help();
				exit(1);
			}
			break;
		case 3: /* host */
			if (!db_fetch_cell_prepared('SELECT id FROM host WHERE id = ?', array($itemId))) {
				print "ERROR: Invalid Host item id: ($itemId)\n\n";
				display_help();
				exit(1);
			}
			break;
		case 4: /* graph_template */
			if (!db_fetch_cell_prepared('SELECT id FROM graph_templates WHERE id = ?', array($itemId))) {
				print "ERROR: Invalid Graph Template item id: ($itemId)\n\n";
				display_help();
				exit(1);
			}
			break;
	}
	/* verified item-id */

	if (db_execute_prepared('REPLACE INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, ?)', array($userId, $itemId, $itemType)) === false) {
		fwrite(STDERR, "ERROR: Failed to update permissions.\n");
		exit(1);
	}

	if (!$quietMode) {
		print "Permission granted for user $userId on $itemTypeName item $itemId.\n";
	}
}

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Add Permissions Utility, Version $version, " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	print "\nusage: add_perms.php --user-id=ID --item-type=TYPE --item-id=ID [--quiet]\n";
	print "    --item-type=[graph|tree|host|graph_template]\n";
	print "    --item-id=ID [--quiet]\n\n";
	print "Where item-id is the id of the object of type item-type\n\n";
	print "List Options:\n";
	print "    --list-users\n";
	print "    --list-trees\n";
	print "    --list-graph-templates\n";
	print "    --list-graphs --host-id=[ID]\n";
}

function displayGroups() {
    /**
     * Todo implement
     */
	print 'This option has not yet been implemented';
}
