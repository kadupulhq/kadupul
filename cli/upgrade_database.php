#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require(__DIR__ . '/../include/cli_check.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/install/functions.php');

ini_set('max_execution_time', '0');

/* make sure installer knows we are installing */
define('IN_CACTI_INSTALL', 1);

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $cli_upgrade, $database_upgrade_status, $cacti_upgrade_version;

$debug       = false;
$cli_upgrade = true;
$local       = false;
$session     = array();
$forcever    = '';
$check_rrd_storage = false;
$migrate_poller_queue = false;

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter, 2);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '--check-rrd-storage':
				$check_rrd_storage = true;
				break;
			case '--migrate-poller-queue':
				$migrate_poller_queue = true;
				break;
			case '--local':
				$local = true;
				break;
			case '--forcever':
				$forcever = $value;
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
				print 'ERROR: Invalid Parameter ' . $parameter . PHP_EOL . PHP_EOL;
				display_help();
				exit(1);
		}
	}
}

if ($check_rrd_storage && $migrate_poller_queue) {
	fwrite(STDERR, "ERROR: Do not combine --check-rrd-storage and --migrate-poller-queue; run them separately.\n");
	exit(1);
}

require_once __DIR__ . '/../lib/rrd_maintenance.php';
// Collectors hand samples to the main poller unless forced to write local RRD files.
$storage_error = ($migrate_poller_queue || (!$check_rrd_storage && (int) ($config['poller_id'] ?? 1) > 1
	&& ($config['force_storage_location_local'] ?? false) !== true))
	? '' : rrd_maintenance_configuration_error();
if ($storage_error !== '') {
	fwrite(STDERR, $storage_error . PHP_EOL);
	exit(1);
}

// Online remote producers write to the primary database; offline/recovery
// producers use their own database. --local explicitly selects the latter.
$queue_connection = !$local && (int) ($config['poller_id'] ?? 1) > 1
	&& ($config['connection'] ?? 'online') === 'online' ? $remote_db_cnn_id : false;
if (!$migrate_poller_queue && !$check_rrd_storage
	&& ((int) ($config['poller_id'] ?? 1) === 1 || ($config['connection'] ?? 'online') === 'online')) {
	$queue_error = rrd_maintenance_queue_configuration_error($queue_connection);
	if ($queue_error !== '') {
		fwrite(STDERR, $queue_error . PHP_EOL);
		exit(1);
	}
}

if ($check_rrd_storage || $migrate_poller_queue) {
	print 'NOTE: Targeting ' . ($queue_connection === false ? 'Local' : 'Main') . ' Poller Queue' . PHP_EOL;
} elseif ($queue_connection !== false) {
	/* Only an online collector upgrades the primary; offline/recovery stay local. */
	db_switch_remote_to_main();
	print 'NOTE: Targeting Main Database' . PHP_EOL;
} else {
	print 'NOTE: Targeting Local Database' . PHP_EOL;
}

if ($migrate_poller_queue) {
    $queue_engine = db_fetch_cell_prepared(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        array('poller_output'), '', true, $queue_connection
    );
    if (!is_string($queue_engine) || $queue_engine === '') {
        fwrite(STDERR, "Cannot inspect the selected poller queue; no migration was attempted.\n");
        exit(1);
    }
    if (strtolower($queue_engine) === 'innodb') {
        print "Selected poller queue already uses InnoDB; no migration was needed.\n";
        exit(0);
    }
    if (!db_execute_prepared('ALTER TABLE poller_output ENGINE=InnoDB ROW_FORMAT=Dynamic', array(), true, $queue_connection)) {
        fwrite(STDERR, "Poller queue migration failed; collectors must remain stopped.\n");
        exit(1);
    }
    print "Poller queue converted to InnoDB; retained samples preserved.\n";
    exit(0);
}

if ($check_rrd_storage) {
    $queue_error = rrd_maintenance_queue_configuration_error($queue_connection);
    if ($queue_error !== '') {
        fwrite(STDERR, $queue_error . PHP_EOL);
        exit(1);
    }
    printf("RRD storage and durable queue checks passed for UID %s, GID %s. No upgrade was performed.\n", function_exists('posix_geteuid') ? posix_geteuid() : 'Windows', function_exists('posix_getegid') ? posix_getegid() : 'Windows');
    exit(0);
}

/* we need to rerun the upgrade, force the current version */
if ($forcever == '') {
	$old_cacti_version = get_cacti_version();
} else {
	$old_cacti_version = $forcever;
}

/* try to find current (old) version in the array */
$old_version_index = (array_key_exists($old_cacti_version, $cacti_version_codes) ? $old_cacti_version : '');

/* do a version check */
if ($old_cacti_version == CACTI_VERSION) {
	print 'Your Kadupul is already up to date (v' . CACTI_VERSION . ' vs v' . $old_cacti_version . ')' . PHP_EOL;
	exit;
} elseif ($old_cacti_version < 0.7) {
	print 'You are attempting to install cacti ' . CACTI_VERSION . ' onto a 0.6.x database.' . PHP_EOL . "To continue, you must create a new database, import 'cacti.sql' into it," . PHP_EOL . "and\tupdate 'include/config.php' to point to the new database." . PHP_EOL;
	exit;
} elseif (empty($old_cacti_version)) {
	print "You have created a new database, but have not yet imported the 'cacti.sql' file." . PHP_EOL;
	exit;
} elseif ($old_version_index == '') {
	print "Invalid Kadupul version $old_cacti_version, cannot upgrade to " . CACTI_VERSION . PHP_EOL;
	exit;
}

print 'Upgrading from v' . $old_cacti_version . PHP_EOL;

$prev_cacti_version = $old_cacti_version;
$orig_cacti_version = get_cacti_version();

// loop through versions from old version to the current, performing updates for each version in the chain
foreach ($cacti_version_codes as $cacti_upgrade_version => $hash_code)  {
	// skip versions old than the database version
	if (cacti_version_compare($old_cacti_version, $cacti_upgrade_version, '>=')) {
		continue;
	}

	// construct version upgrade include path
	$upgrade_file = $config['base_path'] . '/install/upgrades/' . str_replace('.', '_', $cacti_upgrade_version) . '.php';
	$upgrade_function = 'upgrade_to_' . str_replace('.', '_', $cacti_upgrade_version);

	// check for upgrade version file, then include, check for function and execute
	if (file_exists($upgrade_file)) {
		print 'Upgrading from v' . $prev_cacti_version .' (DB ' . $orig_cacti_version . ') to v' . $cacti_upgrade_version . PHP_EOL;

		include($upgrade_file);

		if (function_exists($upgrade_function)) {
			call_user_func($upgrade_function);
			$status = db_install_errors($cacti_upgrade_version);
		} else {
			$status = DB_STATUS_ERROR;
			print 'Error: upgrade function (' . $upgrade_function . ') not found' . PHP_EOL;
		}

		if ($status == DB_STATUS_ERROR) {
			break;
		}

		if (cacti_version_compare($orig_cacti_version, $cacti_upgrade_version, '<')) {
			db_execute_prepared("UPDATE version SET cacti = ?", array($cacti_upgrade_version));

			$orig_cacti_version = $cacti_upgrade_version;
		}

		$prev_cacti_version = $cacti_upgrade_version;
	}

	db_execute_prepared("UPDATE version SET cacti = ?", array($cacti_upgrade_version));

	if (CACTI_VERSION == $cacti_upgrade_version) {
		break;
	}
}

print PHP_EOL;

function db_install_errors($cacti_version) {
	global $database_upgrade_status, $debug, $database_statuses;

	$error_status = DB_STATUS_SKIPPED;

	if (!isset($database_upgrade_status)) {
		$database_upgrade_status = array();
	}

	if (cacti_sizeof($database_upgrade_status)) {
		if (isset($database_upgrade_status[$cacti_version])) {
			foreach ($database_upgrade_status[$cacti_version] as $cache_item) {
				$status = $cache_item['status'];
				$error  = empty($cache_item['error']) ? '<no error>' : $cache_item['error'];
				$sql    = $cache_item['sql'];

				if ($error_status > $status) {
					$error_status = $status;
				}

				if ($debug || $status < DB_STATUS_SUCCESS) {
					$db_status = "[Unknown]";
					if (isset($database_statuses[$status])) {
						$db_status = $database_statuses[$status];
					}

					$sep1 = '################################';
					$sep2 = '+------------------------------+';
					printf("%s%s%s%-10s   -   %s%s%s%s%s%s%s%s", PHP_EOL, $sep1, PHP_EOL, $db_status, $error, PHP_EOL, $sep2, PHP_EOL, clean_up_lines($sql), PHP_EOL, $sep1, PHP_EOL);
				}
			}
		}
	}

	return $error_status;
}

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Kadupul Database Upgrade Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/*  display_help - displays the usage of the function */
function display_help () {
	display_version();

	print PHP_EOL . 'usage: upgrade_database.php [--debug] [--forcever=VERSION]' . PHP_EOL . PHP_EOL;
	print 'A command line version of the Kadupul database upgrade tool.  You must execute' . PHP_EOL;
	print 'this command as a super user, or someone who can write a PHP session file.' . PHP_EOL;
	print 'Typically, this user account will be apache, www-run, or root.' . PHP_EOL . PHP_EOL;
	print 'If you are running a beta or alpha version of Kadupul and need to rerun' . PHP_EOL;
	print 'the upgrade script, simply set the forcever to the previous release.' . PHP_EOL . PHP_EOL;
	print '--check-rrd-storage - Check storage and queue access as this service account without upgrading' . PHP_EOL;
	print '--migrate-poller-queue - Convert the selected queue to InnoDB; use --local on remote collectors' . PHP_EOL;
	print '--forcever - Force the starting version, say ' . CACTI_VERSION . PHP_EOL;
	print '--local    - Perform the action on the Remote Data Collector if run from there' . PHP_EOL;
	print '--debug    - Display verbose output during execution' . PHP_EOL . PHP_EOL;
}
