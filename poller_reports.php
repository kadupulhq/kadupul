#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
} else {
	declare(ticks = 100);
}

ini_set('output_buffering', 'Off');

require(__DIR__ . '/include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/rrd.php');
require_once($config['base_path'] . '/lib/reports.php');

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_version();
	print "Kadupul Reporting Poller, Version $version, " . COPYRIGHT_YEARS . "\n";
}

/** display_help - generic help screen for utilities
 * @return		 - null */
function display_help () {
	display_version();

	print "\nusage: poller_reports.php [--force] [--debug]\n\n";
	print "Kadupul's graphical reporting poller.  This poller will create and distribute\n";
	print "email reports to recipients based upon the schedules for those reports.\n\n";
	print "Optional:\n";
	print "    --force     - Force all Reports to be sent\n";
	print "    --debug     - Display verbose output during execution\n\n";
}

/** sig_handler - provides a generic means to catch exceptions to the Kadupul log.
 * @arg $signo 	- (int) the signal that was thrown by the interface.
 * @return 		- null */
function sig_handler($signo) {
	switch ($signo) {
		case SIGTERM:
		case SIGINT:
			reports_log('WARNING: Reports Poller terminated by user', false, 'REPORTS TRACE', POLLER_VERBOSITY_LOW);

			exit(1);
			break;
		default:
			/* ignore all other signals */
	}
}

global $current_user;

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$debug = false;
$force = false;

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-f':
			case '--force':
				$force = true;
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
}

/* install signal handlers for UNIX only */
if (function_exists('pcntl_signal')) {
	pcntl_signal(SIGTERM, 'sig_handler');
	pcntl_signal(SIGINT, 'sig_handler');
}

/* take time and log performance data */
$start = microtime(true);

/* let's give this script lot of time to run for ever */
ini_set('max_execution_time', '0');

$t = time();
$number_sent = 0;
$number_attempted = 0;
$number_failed = 0;
$number_skipped = 0;
$run_failed = false;

if (!$force) {
	/* silently end if the registered process is still running, or process table missing */
	if (!register_process_start('reports', 'master', 0, read_config_option('reports_timeout'))) {
		exit(0);
	}
}

/* cacti upgrading */
if (!db_table_exists('reports')) {
	if (!$force) {
		unregister_process('reports', 'master', 0);
	}
	exit(0);
}

/* fetch all enabled reports that have a start time in the past */
if (!$force) {
	$reports = db_fetch_assoc_prepared('SELECT * FROM reports WHERE mailtime < ? AND enabled="on"', array($t));
} else {
	$reports = db_fetch_assoc("SELECT * FROM reports WHERE enabled='on'");
}
if ($reports === false) {
	reports_log('ERROR: Unable to read scheduled reports.', true, 'REPORTS', POLLER_VERBOSITY_LOW);
	$run_failed = true;
	$reports = array();
} else {
	reports_log('Kadupul Reports reports found: ' . cacti_sizeof($reports), true, 'REPORTS', POLLER_VERBOSITY_MEDIUM);
}

/* execute each of those reports */
if (cacti_sizeof($reports)) {
	foreach ($reports as $report) {
		$number_attempted++;
		reports_log('Reports processing report: ' . $report['name'], true, 'REPORTS', POLLER_VERBOSITY_MEDIUM);
		$current_user = db_fetch_row_prepared('SELECT * FROM user_auth WHERE id = ?', array($report['user_id']));
		if ($current_user === false) {
			$number_failed++;
			reports_log('ERROR: Unable to read the owner of report ' . $report['id'] . '.', true, 'REPORTS', POLLER_VERBOSITY_LOW);
			continue;
		}
		if (isset($report['email'])) {
			if (generate_report($report, false) === true) {
				$number_sent++;
			} else {
				$number_failed++;
				reports_log('ERROR: Report ' . $report['id'] . ' could not be sent.', true, 'REPORTS', POLLER_VERBOSITY_LOW);
			}
		} else {
			$number_skipped++;
		}
	}
}

/* record statistics even when no report is due */
$end = microtime(true);
$reports_stats = sprintf('Time:%01.4f Reports:%d Attempted:%d Failed:%d Skipped:%d', $end - $start, $number_sent, $number_attempted, $number_failed, $number_skipped);
reports_log('REPORTS STATS: ' . $reports_stats, true, 'REPORTS', POLLER_VERBOSITY_LOW);
if (!db_execute_prepared('REPLACE INTO settings (name, value) VALUES ("stats_reports", ?)', array($reports_stats))) {
	$run_failed = true;
	reports_log('ERROR: Unable to save report poller statistics.', true, 'REPORTS', POLLER_VERBOSITY_LOW);
}

if (!$force) {
	unregister_process('reports', 'master', 0);
}

exit($run_failed || $number_failed > 0 ? 1 : 0);
