#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require(__DIR__ . '/include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/rrd.php');

/* force Kadupul to store realtime data locally */
$config['force_storage_location_local'] = true;

/* initialize some additional variables */
$force     = false;
$debug     = false;
$graph_id  = false;
$interval  = false;
$poller_id = '';

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-d':
			case '--debug':
				$debug = true;

				break;
			case '--force':
				$force = true;
				break;
			case '--graph':
				$graph_id = (int)$value;
				break;
			case '--interval':
				$interval = (int)$value;
				break;
			case '--poller_id':
				/* Web caller passes a hex session hash; reject anything else to keep shell-safe */
				$poller_id = preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $value) ? $value : '';
				break;
			case '--version':
			case '-V':
			case '-v':
				display_version();
				exit;
			case '--help':
			case '-H':
			case '-h':
				display_help();
				exit;
			default:
				print "ERROR: Invalid Argument: ($arg)\n\n";
				display_help();
				exit(1);
		}
	}
}

if ($graph_id === false || $graph_id < 0) {
	print "ERROR: No --graph=ID specified\n\n";
	display_help();
	exit(1);
}

if ($interval === false || $interval < 0) {
	print "ERROR: No --interval=SEC specified\n\n";
	display_help();
	exit(1);
}

/* record the start time */
$poller_start         = microtime(true);

/* get number of polling items from the database */
$poller_interval = 1;

/* retrieve the last time the poller ran */
$poller_lastrun = read_config_option('poller_lastrun');

/* get the current cron interval from the database */
$cron_interval = read_config_option('cron_interval');

if ($cron_interval != 60) {
	$cron_interval = 300;
}

/* assume a scheduled task of either 60 or 300 seconds */
define('MAX_POLLER_RUNTIME', 298);

/* let PHP only run 1 second longer than the max runtime, plus the poller needs lots of memory */
ini_set('max_execution_time', MAX_POLLER_RUNTIME + 1);

/* initialize file creation flags */
$change_files = false;

/* obtain some defaults from the database */
$max_threads = read_config_option('max_threads');

/* Determine if Realtime will work or not */
$cache_dir = read_config_option('realtime_cache_path');
if (!is_dir($cache_dir)) {
	cacti_log("FATAL: Realtime Cache Directory '$cache_dir' Does Not Exist!");
	exit(1);
} elseif (!is_writable($cache_dir)) {
	cacti_log("FATAL: Realtime Cache Directory '$cache_dir' is Not Writable!");
	exit(2);
}

/* Wait for the worker before consuming its samples; do not leave it behind on a parent-only timeout. */
$worker_output = array();
$worker_status = cacti_exec(read_config_option('path_php_binary'), array(
	'-q', $config['base_path'] . '/cmd_realtime.php', $poller_id, (string) $graph_id, (string) $interval
), $worker_output, null);
if ($worker_status !== 0) {
	cacti_log('ERROR: Realtime worker failed with exit status ' . (int) $worker_status);
	db_close();
	exit(1);
}

/* open a pipe to rrdtool for writing */
$rrdtool_pipe = rrd_init(true, false, true);
if ($rrdtool_pipe === false) {
	cacti_log('ERROR: RRD initialization failed; realtime samples were retained.');
	db_close();
	exit(1);
}

/* process poller output */
if (process_poller_output_rt($rrdtool_pipe, $poller_id, $interval) === false) {
	rrd_close($rrdtool_pipe);
	db_close();
	exit(1);
}

/* close rrd */
rrd_close($rrdtool_pipe);

/* close db */
db_close();

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_version();
	print "Kadupul Realtime Poller, Version $version, " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	print "\nusage: poller_realtime.php --graph=ID [--interval=SEC] [--force] [--debug]\n\n";
	print "Kadupul's Realtime graphing poller.  This poller behaves very similarly\n";
	print "to Kadupul's main poller with the exception that it only polls data source\n";
	print "that are specific to the graph being rendered in the Kadupul UI.\n\n";
	print "Required:\n";
	print "    --graph=ID     Specify the graph id to convert (realtime)\n\n";
	print "Optional:\n";
	print "    --interval=SEC Specify the graph interval (realtime)\n";
	print "    --force        Override poller overrun detection and force a poller run\n";
	print "    --debug|-d     Output debug information.  Similar to cacti's DEBUG logging level.\n\n";
}

/* process_poller_output REAL TIME MODIFIED */
function process_poller_output_rt($rrdtool_pipe, $poller_id, $interval) {
	global $config;

	if ($rrdtool_pipe === false) {
		cacti_log('ERROR: RRD initialization failed; pending realtime samples were retained.');

		return false;
	}

	include_once($config['library_path'] . '/rrd.php');

	/* let's count the number of rrd files we processed */
	$rrds_processed = 0;

	/* create/update the rrd files */
	$results = db_fetch_assoc_prepared('SELECT port.output, port.time, port.local_data_id,
		pi.rrd_path, pi.rrd_name, pi.rrd_num, dl.data_template_id
		FROM poller_output_realtime AS port
		INNER JOIN poller_item AS pi
		ON port.local_data_id = pi.local_data_id
		AND port.rrd_name = pi.rrd_name
		INNER JOIN data_local AS dl
		ON dl.id = port.local_data_id
		WHERE port.poller_id = ?',
		array($poller_id));
	if ($results === false) {
		return false;
	}

	if (cacti_sizeof($results)) {
		/* create an array keyed off of each .rrd file */
		foreach ($results as $item) {
			$rt_graph_path    = read_config_option('realtime_cache_path') . '/user_' . $poller_id . '_' . $item['local_data_id'] . '.rrd';
			$data_source_path = get_data_source_path($item['local_data_id'], true);

			/* create rt rrd */
			if (!file_exists($rt_graph_path)) {
				/* get the syntax */
				$command = @rrdtool_function_create($item['local_data_id'], true);

				/* replace path */
				$command = str_replace($data_source_path, $rt_graph_path, $command);

				/* minimum refresh interval */
				$step = read_config_option('realtime_interval');

				/* replace step */
				$command = preg_replace('/--step\s(\d+)/', '--step ' . $step, $command);

				/* WIN32: before sending this command off to rrdtool, get rid
				of all of the '\' characters. Unix does not care; win32 does.
				Also make sure to replace all of the fancy "\"s at the end of the line,
				but make sure not to get rid of the "\n"s that are supposed to be
				in there (text format) */
				$command = str_replace("\\\n", " ", $command);

				/* create the rrdfile */
				shell_exec($command);

				/* change permissions so that the poller can clear */
				@chmod($rt_graph_path, 0644);
			} else {
				/* change permissions so that the poller can clear */
				@chmod($rt_graph_path, 0644);
			}

			/* now, let's update the path to keep the RRDs updated */
			$item['rrd_path'] = $rt_graph_path;

			/* cleanup the value */
			$value     = trim($item['output']);
			$unix_time = strtotime($item['time']);

			$rrd_update_array[$item['rrd_path']]['local_data_id'] = $item['local_data_id'];

			/* single one value output */
			if ((is_numeric($value)) || ($value == 'U')) {
				$rrd_update_array[$item['rrd_path']]['times'][$unix_time][$item['rrd_name']] = $value;
			} else {
				/* multiple value output */
				$values = preg_split('/\s+/', $value);

				$field_rows = db_fetch_assoc_prepared('SELECT DISTINCT dtr.data_source_name, dif.data_name
						FROM graph_templates_item AS gti
						INNER JOIN data_template_rrd AS dtr
						ON gti.task_item_id = dtr.id
						INNER JOIN data_input_fields AS dif
						ON dtr.data_input_field_id = dif.id
						AND dtr.local_data_id = ?',
						array($item['local_data_id']));
				if ($field_rows === false) {
					cacti_log('ERROR: Unable to read realtime field mapping; pending samples retained.', false, 'POLLER');
					return false;
				}
				$rrd_field_names = array_rekey($field_rows, 'data_name', 'data_source_name');

				if (cacti_sizeof($values)) {
					foreach($values as $value) {
						$matches = explode(':', $value);

						if (isset($rrd_field_names[$matches[0]])) {
							$rrd_update_array[$item['rrd_path']]['times'][$unix_time][$rrd_field_names[$matches[0]]] = $matches[1];
						}
					}
				}
			}

			/* fallback values */
			if ((!isset($rrd_update_array[$item['rrd_path']]['times'][$unix_time])) && ($item['rrd_name'] != '')) {
				$rrd_update_array[$item['rrd_path']]['times'][$unix_time][$item['rrd_name']] = 'U';
			}else if ((!isset($rrd_update_array[$item['rrd_path']]['times'][$unix_time])) && ($item['rrd_name'] == '')) {
				unset($rrd_update_array[$item['rrd_path']]);
			}
		}

		$rrds_processed = rrdtool_function_update($rrd_update_array, $rrdtool_pipe, $completed);

		/* make sure each .rrd file has complete data */
		foreach ($results as $item) {
			$path = read_config_option('realtime_cache_path') . '/user_' . $poller_id . '_' . $item['local_data_id'] . '.rrd';
			if (!isset($completed[$path][strtotime($item['time'])])) {
				continue;
			}
			if (db_execute_prepared('DELETE FROM poller_output_realtime
				WHERE local_data_id = ?
				AND rrd_name = ?
				AND time = ?
				AND poller_id = ?
				AND CAST(CONVERT(output USING utf8mb4) AS BINARY) = CAST(CONVERT(? USING utf8mb4) AS BINARY)',
				array($item['local_data_id'], $item['rrd_name'], $item['time'], $poller_id, $item['output'])) === false) { return false; }
		}


	}

	return $rrds_processed;
}
