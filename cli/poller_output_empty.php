#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require(__DIR__ . '/../include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/dsstats.php');
require_once($config['base_path'] . '/lib/dsdebug.php');
require_once($config['base_path'] . '/lib/boost.php');
require_once($config['base_path'] . '/lib/rrd.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter, 2);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
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

/* record the start time */
$start = microtime(true);

/* open a pipe to rrdtool for writing */
$rrdtool_pipe = rrd_init(true, false, true);
if ($rrdtool_pipe === false) {
	fwrite(STDERR, "ERROR: RRD initialization failed; queued samples retained for retry.\n");
	exit(1);
}

$rrds_processed = 0;

while (true) {
	$pending = db_fetch_cell('SELECT count(*) FROM poller_output');
	if (!is_numeric($pending)) {
		fwrite(STDERR, "ERROR: Poller output count is unavailable; samples retained for retry.\n");
		rrd_close($rrdtool_pipe);
		exit(1);
	}
	if ((int) $pending === 0) {
		break;
	}
	$updated = process_poller_output($rrdtool_pipe, false);
	$remaining = db_fetch_cell('SELECT count(*) FROM poller_output');
	if ($updated === false || !is_numeric($remaining) || $remaining >= $pending) {
		fwrite(STDERR, "ERROR: Poller output made no progress; remaining samples retained for retry.\n");
		rrd_close($rrdtool_pipe);
		exit(1);
	}
	$rrds_processed += $updated;
}

print "There were $rrds_processed RRD updates made this pass\n";

rrd_close($rrdtool_pipe);

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Kadupul Process Poller Output Utility, Version $version, " . COPYRIGHT_YEARS . "\n";
}

/*	display_help - displays the usage of the function */
function display_help () {
	display_version();

	print "\nusage: poller_output_empty.php\n\n";
	print "A utility to process the poller output table.  This tool is deprecated but should work.\n\n";
}
