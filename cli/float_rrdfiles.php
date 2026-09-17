#!/usr/bin/env php
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
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

if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
} else {
	declare(ticks = 100);
}

ini_set('output_buffering', 'Off');

require(__DIR__ . '/../include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/rrd.php');
require_once($config['base_path'] . '/lib/maintenance_cli.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

/* system controlled parameters */
$type              = 'rmaster';
$thread_id         = 0;
$rrd_rewrite_lock  = false; /* held by sig_handler() if a child is interrupted mid-fetch */

/* mandatory parameters */
$start_time        = false;
$end_time          = false;

/* optional parameters for RRDfile selection */
$host_id           = false;
$host_template_id  = false;
$graph_template_id = false;
$local_graph_ids   = array();
$step              = false;

/* optional for threading and verbose display */
$threads           = 1;
$seebug            = false;

/* optional for force handing and resume */
$resume            = false;
$forcerun          = false;

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter, 2);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
		case '--force':
			$forcerun = true;

			break;
		case '--debug':
			$seebug = true;

			break;
		case '--resume':
			$resume = true;

			break;
		case '--start':
			$start_time = $value;

			break;
		case '--end':
			$end_time = $value;

			break;
		case '--type':
			$type = $value;

			break;
		case '--threads':
			$threads = $value;

			break;
		case '--child':
			$thread_id = $value;

			break;
		case '--host-id':
			$host_id = $value;

			break;
		case '--host-template_id':
			$host_template_id = $value;

			break;
		case '--graph-template-id':
			$graph_template_id = $value;

			break;
		case '--graph-ids':
			$local_graph_ids = explode(',', $value);

			break;
		case '--step':
			$step = $value;

			break;
		case '--version':
		case '-v':
		case '-V':
			display_version();
			exit(0);
		case '--help':
		case '-h':
		case '-H':
			display_help();
			exit(0);
		default:
			print 'ERROR: Invalid Parameter ' . $parameter . PHP_EOL . PHP_EOL;
			display_help();
			exit(1);
		}
	}
}

require_once __DIR__ . '/../lib/rrd_maintenance.php';
rrd_maintenance_cli_preflight();

/**
 * Types include
 *
 * rmaster  - the main process launched from the Cacti main poller and will launch child processes
 * child    - a child of the master process from the 'rmaster'
 *
 */

/* install signal handlers for UNIX only */
if (function_exists('pcntl_signal')) {
	pcntl_signal(SIGTERM, 'sig_handler', false);
	pcntl_signal(SIGINT, 'sig_handler', false);
}

if ($start_time == false || $end_time == false) {
	printf('ERROR: Both --start=TS and --end=TS are required and can be a timestamp or in date/time format' . PHP_EOL);
	exit(1);
}

/* validate the start time */
if (!is_numeric($start_time)) {
	$ost = $start_time;
	$start_time = strtotime($start_time);

	if ($start_time === false) {
		printf('ERROR: The Start Time \'%s\' is not a valid date/time' . PHP_EOL, $ost);
		exit(1);
	}
}

/* validate the end time */
if (!is_numeric($end_time)) {
	$oet = $end_time;

	$end_time = strtotime($end_time);

	if ($end_time === false) {
		printf('ERROR: The End Time \'%s\' is not a valid date/time' . PHP_EOL, $oet);
		exit(1);
	}
}

/* validate the start and end times are sane */
if ($start_time >= $end_time) {
	printf('ERROR: The Start Time \'%s\' is equal or grater to the End Time \'%s\' is not a valid date/time' . PHP_EOL, date('Y-m-d H:i:s', $start_time), date('Y-m-d H:i:s', $end_time));
	exit(1);
}

/* perform some validation for host-id */
if ($host_id !== false && ($host_id < 0 || !is_numeric($host_id))) {
	printf('ERROR: The value of %s for --host-id is invalid!' . PHP_EOL, $host_id);
	exit(1);
}

/* perform some validation for host-template-id */
if ($host_template_id !== false && ($host_template_id < 0 || !is_numeric($host_template_id))) {
	printf('ERROR: The value of %s for --host-template-id is invalid!' . PHP_EOL, $host_template_id);
	exit(1);
}

/* perform some validation for graph-template-id */
if ($graph_template_id !== false && ($graph_template_id < 0 || !is_numeric($graph_template_id))) {
	printf('ERROR: The value of %s for --graph-template-id is invalid!' . PHP_EOL, $graph_template_id);
	exit(1);
}

/* perform some validation for step value */
if ($step !== false && ($step < 0 || !is_numeric($step))) {
	printf('ERROR: The value of %s for --step is invalid!' . PHP_EOL, $step);
	exit(1);
}

/* perform some validation for step value */
if (cacti_sizeof($local_graph_ids)) {
	foreach($local_graph_ids as $index => $value) {
		$value = trim($value);
		$local_graph_ids[$index] = $value;

		if (!is_numeric($value) || empty($value) || $value <= 0) {
			printf('ERROR: One of the values of %s for --graph-ids is invalid!' . PHP_EOL, $value);
			exit(1);
		}
	}

	printf('NOTE: The value of threads is being switched from %s to 1 due to -graph-ids option' . PHP_EOL, $threads);

	$threads = 1;
}

/* take time and log performance data */
$start = microtime(true);

/* let's give this script lot of time to run for ever */
ini_set('max_execution_time', '0');
ini_set('memory_limit', '-1');

/* send a gentle message to the log and stdout */
float_debug('Polling Starting');

/* silently end if the registered process is still running */
if (!$forcerun) {
	if (!register_process_start('rfloat', $type, $thread_id, 86400)) {
		exit(0);
	}
}

/* Collect data as determined by the type */
$exit_status = 0;
switch ($type) {
	case 'rmaster':
		$exit_status = float_master_handler($forcerun, $resume, $host_id, $host_template_id, $graph_template_id, $local_graph_ids, $threads, $step, $start_time, $end_time) ? 0 : 1;

		unregister_process('rfloat', 'rmaster', 0);

		break;
	case 'child':  /* Launched by the rmaster process */
		try {
			$rrdfiles = db_fetch_assoc_prepared('SELECT *
				FROM poller_float_rrdfiles_not_done
				WHERE process = ?',
				array($thread_id));

			$child_start = microtime(true);

			if (cacti_sizeof($rrdfiles)) {
				cacti_log(sprintf('Child Started Process %s with %d RRDfiles', $thread_id, cacti_sizeof($rrdfiles)), true, 'RFLOAT');
			} else {
				cacti_log(sprintf('Child Started Process %s with No RRDfiles', $thread_id), true, 'RFLOAT');
			}

			foreach($rrdfiles as $data) {
				print '.';

				/* Flush before the exclusive lease: Boost itself needs a shared lease. */
				$fetched = rrdtool_function_fetch($data['local_data_id'], time()-120, time());
				if (empty($fetched)) {
					throw new RuntimeException('Unable to fetch RRD data before floating.');
				}

				/* A float that fails keeps its queue row so --resume retries it. The
				 * temporary XML file is created exclusively, so one left by a killed
				 * child is refused rather than overwritten, and deleting the row
				 * anyway would drop that RRD from the queue unfloated. */
				$rrd_rewrite_lock = rrd_maintenance_acquire(true, true, 5);
				if ($rrd_rewrite_lock === false) {
					fwrite(STDERR, "FATAL: RRD storage is busy or its maintenance lock is unavailable.\n");
					$exit_status = 1;
					break; // Leave the row queued, then unregister this child below.
				}
				try {
					if (float_rrdfile($data['rrd_path'], $data['local_data_id'], $step, $start_time, $end_time)) {
						db_execute_prepared('DELETE FROM poller_float_rrdfiles_not_done
							WHERE local_data_id = ?',
							array($data['local_data_id']));
					} else {
						$exit_status = 1;
					}
				} finally {
					rrd_maintenance_release($rrd_rewrite_lock);
				}
			}

			$total_time = microtime(true) - $child_start;
		} catch (Throwable $error) {
			cacti_log('ERROR: Float worker failed: ' . $error->getMessage(), true, 'RFLOAT');
			$exit_status = 1;
		} finally {
			unregister_process('rfloat', 'child', $thread_id);
		}

		break;
}

float_debug('Polling Ending');

exit($exit_status);

/**
 * float_rrdfile - Takes the last known data for a data range
 *   and uses it to float a range.  It is sensitive to daily and other
 *   RRA's and will float around those ranges to ensure that there are
 *   no spikes.
 *
 * @param  (string) The RRDfile to update
 * @param  (int)    The local data id of the data source
 * @param  (int)    Any step size smaller than this will be skipped
 * @param  (int)    The float range start time as a unix timestamp
 * @param  (int)    The float range end time as a unix timestamp
 *
 * @return (bool)   True if successful otherwise false
 */
function float_rrdfile($rrd_path, $local_data_id, $step, $start_time, $end_time) {
	global $seebug;

	static $rrdtool_bin = false;
	static $tmp_dir     = false;

	if ($rrdtool_bin === false) {
		$rrdtool_bin = (string) read_config_option('path_rrdtool');

		if ($rrdtool_bin === '') {
			$rrdtool_bin = 'rrdtool';
		}
	}

	if ($tmp_dir === false) {
		$tmp_dir = sys_get_temp_dir();
	}

	$delta_time = $end_time - $start_time;
	$return     = 0;
	$output     = array();
	$command    = cacti_escapeshellarg($rrdtool_bin) . ' dump ' . cacti_escapeshellarg($rrd_path);
	$db_prefix  = '                       ';

	if (file_exists($rrd_path)) {
		if (is_writable($rrd_path)) {
			$response = exec($command, $output, $return);

			if ($return != 0) {
				cacti_log(sprintf('ERROR: Unable to dump file %s to XML', $rrd_path), false, 'RFLOAT');
				return false;
			}

			/* 1.2.31 names in the shared temporary directory, created exclusively
			 * so a planted symlink or leftover file is refused, not followed */
			$tmp_file = $tmp_dir . '/' . $local_data_id . '.xml';
			$fp       = cacti_cli_create_file($tmp_file);

			if (!is_resource($fp)) {
				cacti_log('WARNING: ' . $fp, false, 'RFLOAT');
				return false;
			}

			$lf         = false;
			$file_debug = false;

			/* A refused log turns debug output off for this file only; later
			 * files open the log again, as 1.2.31 did for each file. */
			if ($seebug) {
				$lf = cacti_cli_open_log('/tmp/clearer.log');

				if (!is_resource($lf)) {
					cacti_log('WARNING: ' . $lf . '.  Debug output is disabled for this file.', false, 'RFLOAT');
				}

				$file_debug = is_resource($lf);
			}

			if (is_resource($fp)) {
				$in_database = false;
				$in_range    = false;
				$prev_data   = '';
				$curstep     = 0;

				foreach($output as $line) {
					if (strpos($line, '<pdp_per_row>') !== false) {
						/**
						 * <pdp_per_row>24</pdp_per_row> <!-- 7200 seconds -->
						 * split the database record into pieces
						 */
						$parts = preg_split('/[\s]+/', trim($line));

						/**
						 * We use this just in case we need to update
						 * only one line in the RRA.
						 */
						$granularity = $parts[2];
						$gdelta      = $granularity / 2;
						$curstep     = $granularity;

						$line .= PHP_EOL;
					} elseif (strpos($line, '<database>') !== false) {
						$in_database = true;
						$line .= PHP_EOL;
					} elseif (strpos($line, '</database>') !== false) {
						$in_database = false;
						$line .= PHP_EOL;
					} elseif ($in_database) {
						/* split the database record into pieces */
						$parts  = preg_split('/[\s]+/', trim($line));

						$timestamp = $parts[5];

						if ($timestamp <= $start_time && ($timestamp + $gdelta) < $end_time) {
							$in_range = false;
							$line .= PHP_EOL;

							$prev_data = $parts[7];
							$prev_line = $line;
						} elseif ($timestamp >= $end_time && ($timestamp - $gdelta) > $start_time) {
							$in_range = false;
							$line .= PHP_EOL;
						} elseif ($prev_data != '') {
							if ($file_debug) {
								if ($step !== false) {
									fwrite($lf, sprintf("In Range: CurDate:%s, StartDate:%s, EndDate:%s, Granularity:%s, Delta:%s, Step:%s" . PHP_EOL, date('Y-m-d H:i:s', $timestamp), date('Y-m-d H:i:s', $start_time), date('Y-m-d H:i:s', $end_time), $granularity, $delta_time, $step));
								} else {
									fwrite($lf, sprintf("In Range: CurDate:%s, StartDate:%s, EndDate:%s, Granularity:%s, Delta:%s" . PHP_EOL, date('Y-m-d H:i:s', $timestamp), date('Y-m-d H:i:s', $start_time), date('Y-m-d H:i:s', $end_time), $granularity, $delta_time));
								}
							}

							if ($step !== false && $step <= $curstep) {
								$in_range = true;

								unset($parts[7]);

								$nline = $db_prefix . implode(' ', $parts) . ' ' .  $prev_data . PHP_EOL;

								if ($file_debug) {
									fwrite($lf, sprintf("Pruning: CurDate:%s, StartDate:%s, EndDate:%s, Granularity:%s, Delta:%s" . PHP_EOL, date('Y-m-d H:i:s', $timestamp), date('Y-m-d H:i:s', $start_time), date('Y-m-d H:i:s', $end_time), $granularity, $delta_time));
									fwrite($lf, sprintf("PreLine: %s\nOldLine: %s\nNewLine: %s\n\n", trim($prev_line), trim($line), trim($nline)));
								}

								$line = $nline;
							} else {
								if ($file_debug) {
									fwrite($lf, sprintf("Not Pruning: CurDate:%s, StartDate:%s, EndDate:%s, Granularity:%s, Delta:%s" . PHP_EOL, date('Y-m-d H:i:s', $timestamp), date('Y-m-d H:i:s', $start_time), date('Y-m-d H:i:s', $end_time), $granularity, $delta_time));
									fwrite($lf, sprintf("PreLine: %s\nOldLine: %s\n\n", trim($prev_line), trim($line)));
								}

								$in_range = false;
								$line .= PHP_EOL;

								$prev_data = $parts[7];
								$prev_line = $line;
							}
						} else {
							$line .= PHP_EOL;
						}
					} else {
						$line .= PHP_EOL;
					}

					/* fwrite() can return a short count, or fflush() below can fail,
					 * on a full disk. Either one leaves a truncated file on disk
					 * that rrdtool restore below would read as if it were complete. */
					$bytes_written = fwrite($fp, $line);

					if ($bytes_written !== strlen($line)) {
						cacti_log(sprintf('WARNING: Refusing to restore %s because the XML file was not written completely', $tmp_file), false, 'RFLOAT');
						cacti_cli_remove_file($fp, $tmp_file);

						if ($file_debug) {
							fclose($lf);
						}

						return false;
					}
				}

				if (!fflush($fp)) {
					cacti_log(sprintf('WARNING: Refusing to restore %s because the XML file was not written completely', $tmp_file), false, 'RFLOAT');
					cacti_cli_remove_file($fp, $tmp_file);

					if ($file_debug) {
						fclose($lf);
					}

					return false;
				}

				/* restore the file */
				$return  = 0;
				$output  = array();
				$command = cacti_escapeshellarg($rrdtool_bin) . ' restore -f ' . cacti_escapeshellarg($tmp_file) . ' ' . cacti_escapeshellarg($rrd_path);

				/* rrdtool restore opens the file by name, so the name must still be
				 * the file written above */
				if (!cacti_cli_path_is_handle($fp, $tmp_file)) {
					cacti_log(sprintf('WARNING: Refusing to restore %s because it changed after it was written', $tmp_file), false, 'RFLOAT');
					cacti_cli_remove_file($fp, $tmp_file);

					if ($file_debug) {
						fclose($lf);
					}

					return false;
				}

				$response = exec($command, $output, $return);

				if ($return == 0) {
					cacti_log(sprintf('NOTE: Range floated for RRDfile %s', $rrd_path), false, 'RFLOAT');
					cacti_cli_remove_file($fp, $tmp_file);

					if ($file_debug) {
						fclose($lf);
					}

					return true;
				} else {
					cacti_log(sprintf('WARNING: Range float FAILED for RRDfile %s.  Message is %s', $rrd_path, $response), false, 'RFLOAT');
					cacti_cli_remove_file($fp, $tmp_file);

					if ($file_debug) {
						fclose($lf);
					}

					return false;
				}
			} else {
				cacti_log(sprintf('WARNING: Unable to open file %s for writing', $tmp_file), false, 'RFLOAT');
				return false;
			}
		} else {
			cacti_log(sprintf('WARNING: Unable to write to RRDfile %s', $rrd_path), false, 'RFLOAT');
			return false;
		}
	} else {
		cacti_log(sprintf('WARNING: RRDfile does not exist %s', $rrd_path), false, 'RFLOAT');
		return false;
	}
}

function float_master_handler($forcerun, $resume, $host_id, $host_template_id, $graph_template_id, $local_graph_ids, $threads, $step, $start_time, $end_time) {
	global $type;

	if (float_reap_dead_children() === false) {
		return false;
	}

	/* Create table if first time use */
	if (!db_table_exists('poller_float_rrdfiles_not_done')) {
		db_execute("CREATE TABLE `poller_float_rrdfiles_not_done` (
			`process` int(10) unsigned NOT NULL DEFAULT 0,
			`local_data_id` int(10) unsigned NOT NULL DEFAULT 0,
			`rrd_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
			PRIMARY KEY (`rrd_path`),
			KEY `local_data_id` (`local_data_id`),
			KEY `process` (`process`))
			ENGINE=InnoDB
			COMMENT='Temp Storage for processed RRDfiles'");
	}

	$sql_where  = '';
	$sql_params = array();

	if ($host_id !== false) {
		$sql_where  .= 'WHERE h.id = ?';
		$sql_params[] = $host_id;
	}

	if ($host_template_id !== false) {
		$sql_where  .= ($sql_where != '' ? 'AND ':'WHERE ') . 'h.host_template_id = ?';
		$sql_params[] = $host_template_id;
	}

	if ($graph_template_id !== false) {
		$sql_where  .= ($sql_where != '' ? 'AND ':'WHERE ') . 'gti.graph_template_id = ?';
		$sql_params[] = $graph_template_id;
	}

	if (cacti_sizeof($local_graph_ids)) {
		$sql_where  .= ($sql_where != '' ? 'AND ':'WHERE ') . '(';
		$inner_where = '';

		foreach($local_graph_ids as $id) {
			$inner_where .= ($inner_where != '' ? ' OR ':'') . 'gti.local_graph_id = ' . $id;
		}

		$sql_where .= $inner_where . ')';
	}

	/* Find out if there are unprocessed records */
	if ($resume) {
		$rows = db_fetch_cell('SELECT COUNT(*) FROM poller_float_rrdfiles_not_done');

		/* If there are no unprocessed records, prime the collector */
		if ($rows > 0) {
			db_execute('UPDATE poller_float_rrdfiles_not_done SET process = 0');
		} else {
			printf('ERROR: There are no outstanding RRDfiles to process!');
			return false;
		}
	} else {
		db_execute('TRUNCATE TABLE poller_float_rrdfiles_not_done');

		db_execute_prepared("INSERT INTO poller_float_rrdfiles_not_done
			(local_data_id, rrd_path)
			SELECT DISTINCT pi.local_data_id, pi.rrd_path
			FROM poller_item AS pi
			INNER JOIN data_local AS dl
			ON pi.local_data_id = dl.id
			INNER JOIN host AS h
			ON dl.host_id = h.id
			INNER JOIN data_template_rrd AS dtr
			ON dl.id = dtr.local_data_id
			INNER JOIN graph_templates_item AS gti
			ON gti.task_item_id = dtr.id
			$sql_where", $sql_params);

		$rows = db_fetch_cell('SELECT COUNT(*) FROM poller_float_rrdfiles_not_done');
	}

	if ($rows == 0) {
		print "WARNING: There are no RRDfiles to process";
		return false;
	}

	// Every rewrite takes one directory-wide exclusive lease; siblings cannot run concurrently.
	$threads = 1;
	$rrdfiles_per_process = ceil(db_fetch_cell_prepared('SELECT COUNT(*)/? FROM poller_float_rrdfiles_not_done', array($threads)));

	print "There are $threads and $rrdfiles_per_process RRDfiles to process per thread" . PHP_EOL;

	$children = array();
	$child_failed = false;
	for($thread_id = 1; $thread_id <= $threads; $thread_id++) {
		db_execute_prepared("UPDATE poller_float_rrdfiles_not_done
			SET process = ?
			WHERE process = 0
			LIMIT $rrdfiles_per_process",
			array($thread_id));

		float_debug("Launching Process ID $thread_id");

		$process = float_launch_child($thread_id, $step, $start_time, $end_time);
		if (is_resource($process)) {
			$children[$thread_id] = $process;
		} else {
			$child_failed = true;
		}
	}

	// Own the child handles: a crash before registration cannot leave us
	// waiting forever on stale (or missing) database process rows.
	while ($children) {
		foreach ($children as $thread_id => $process) {
			$status = proc_get_status($process);
			if (!$status['running']) {
				$child_failed = $child_failed || $status['exitcode'] !== 0;
				proc_close($process);
				unregister_process('rfloat', 'child', $thread_id, $status['pid']);
				unset($children[$thread_id]);
			}
		}
		if ($children) {
			usleep(100000);
		}
	}
	$rrds = db_fetch_cell('SELECT COUNT(*) FROM poller_float_rrdfiles_not_done');

	if ($child_failed || !is_numeric($rrds) || (int) $rrds !== 0) {
		cacti_log('ERROR: RRD floating left unprocessed files; use --resume after correcting the failure.', true, 'RFLOAT');
		return false;
	}

	return true;
}

/**
 * flaot_launch_child - this function will launch collector children based upon
 *   the maximum number of threads and the process type
 *
 * @param $thread_id  (int)    The Thread id to launch
 * @param $start_time (int)    The float window start time as a timestamp
 * @param $end_time   (int)    The float window end time as a timestamp
 *
 * @return resource|false Child handle owned by the master.
 */
function float_launch_child($thread_id, $step, $start_time, $end_time) {
	global $config, $seebug;

	$php_binary = (string) read_config_option('path_php_binary');

	if ($php_binary === '') {
		$php_binary = PHP_BINARY;
	}

	float_debug(sprintf('Launching Float Data Process Number %s for Type %s', $thread_id, 'child'));

	cacti_log(sprintf('NOTE: Launching Float Data Number %s for Type %s', $thread_id, 'child'), false, 'RFLOAT', POLLER_VERBOSITY_MEDIUM);

	$args = array($php_binary, $config['base_path'] . '/cli/float_rrdfiles.php', '--type=child', '--child=' . $thread_id, '--start=' . $start_time, '--end=' . $end_time);
	if ($step !== false) { $args[] = '--step=' . $step; }
	if ($seebug) { $args[] = '--debug'; }
	return proc_open($args, array(0 => STDIN, 1 => STDOUT, 2 => STDERR), $pipes);
}

/**
 * float_processes_running - given a type, determine the number
 *   of sub-type or children that are currently running
 *
 * @return - (int) The number of running processes
 */
function float_processes_running() {
	$running = db_fetch_cell('SELECT COUNT(*)
		FROM processes
		WHERE tasktype = "rfloat"
		AND taskname = "child"');

	if (!is_numeric($running)) { return false; }
	if ((int) $running === 0) {
		return 0;
	}

	return $running;
}

/**
 * float_reap_dead_children - removes the process table rows of any child that
 *   exited without unregistering itself, so the rmaster's wait loop stops
 *   counting a worker that is already gone
 *
 * @return - (int) The number of children that were reaped
 */
function float_reap_dead_children() {
	$reaped = 0;

	$children = db_fetch_assoc_prepared('SELECT *
		FROM processes
		WHERE tasktype = ?
		AND taskname = ?',
		array('rfloat', 'child'));

	if ($children === false) {
		cacti_log('ERROR: Unable to inspect float workers; queue retained without modification.', false, 'RFLOAT');
		return false;
	}

	foreach($children as $c) {
		if (cacti_process_still_running($c['pid'])) {
			continue;
		}

		cacti_log(sprintf('WARNING: Float Data Process Number %s with PID %s exited without unregistering itself.  Its unprocessed RRDfiles remain queued for --resume.', $c['taskid'], cacti_process_pid_for_log($c['pid'])), false, 'RFLOAT');

		unregister_process($c['tasktype'], $c['taskname'], $c['taskid'], $c['pid']);

		$reaped++;
	}

	return $reaped;
}

/**
 * float_debug - this simple routine prints a standard message to the console
 *   when running in debug mode.
 *
 * @param $message - (string) The message to display
 *
 * @return - NULL
 */
function float_debug($message) {
	global $seebug;

	if ($seebug) {
		print 'RFLOAT: ' . $message . PHP_EOL;
	}
}

/**
 * display_version - displays version information
 */
function display_version() {
	$version = get_cacti_version();
	print "Cacti RRDfile Data Float Tool, Version $version " . COPYRIGHT_YEARS . PHP_EOL;
}

/**
 * display_help - generic help screen for utilities
 */
function display_help () {
	display_version();

	print PHP_EOL . 'usage: float_rrdfiles.php --start=TS --end=TS [--threads=N --host-id=N --host-template-id=N --graph-template-id=N] [--debug]' . PHP_EOL . PHP_EOL;

	print 'Cacti\'s RRDfile Data Float Tool.  This CLI script will float a' . PHP_EOL;
	print 'range in select Cacti Graphs using the RRDtool dump/import utility.' . PHP_EOL . PHP_EOL;
	print 'This utility serializes rewrites under the exclusive storage lease,' . PHP_EOL;
	print 'except in the case when you have specified specific --graph-ids as' . PHP_EOL;
	print 'show with the optional settings below.' . PHP_EOL . PHP_EOL;

	print 'Required:' . PHP_EOL;
	print '    --start=TS  - The float range start time timestamp or date.' . PHP_EOL;
	print '    --end=TS    - The float range end time timestamp or date.' . PHP_EOL . PHP_EOL;

	print 'Optional:' . PHP_EOL;
	print '    --threads             - Accepted for compatibility; rewrites use one worker' . PHP_EOL;
	print '    --resume              - False, Resume a canceled float process' . PHP_EOL;
	print '    --host-id=N           - N/A, Update a specific devices RRDfiles' . PHP_EOL;
	print '    --host-template-id=N  - N/A, Update a specific Device Templates RRDfiles' . PHP_EOL;
	print '    --graph-template-id=N - N/A, Update a specific Graph Template RRDfiles' . PHP_EOL;
	print '    --graph-ids=N,N,...   - N/A, A comma delimited list of graph ids to fix' . PHP_EOL;
	print '    --step=N              - N/A, Apply only to step sizes >= this value.' . PHP_EOL;
	print '    --debug               - Display verbose output during execution' . PHP_EOL . PHP_EOL;

	print 'System Controlled:' . PHP_EOL;
	print '    --type      - The type and subtype of the float process' . PHP_EOL;
	print '    --child     - The thread id of the child process' . PHP_EOL . PHP_EOL;
}

/**
 * sig_handler - provides a generic means to catch exceptions to the Cacti log.
 *
 * @param $signo - (int) the signal that was thrown by the interface.
 *
 * @return - null
 */
function sig_handler($signo) {
	global $type, $thread_id, $rrd_rewrite_lock;

	switch ($signo) {
		case SIGTERM:
		case SIGINT:
			cacti_log('WARNING: RRDfile Data Float Tool terminated by user', false, 'RFLOAT');

			if (strpos($type, 'rmaster') !== false) {
				float_kill_running_processes();
			}

			/* a child interrupted mid-fetch still owns the row it registered under
			 * its own type/thread id; unregistering as 'rmaster' would miss it and
			 * leave float_processes_running() counting a process that is gone */
			unregister_process('rfloat', $type, $thread_id, getmypid());

			rrd_maintenance_release($rrd_rewrite_lock);


			exit(1);
			break;
		default:
			/* ignore all other signals */
	}
}

/**
 * float_kill_running_processes - this function is part of an interrupt
 *   handler to kill children processes when the parent is killed
 *
 * @return - NULL
 */
function float_kill_running_processes() {
	global $type;

	$processes = db_fetch_assoc_prepared('SELECT *
		FROM processes
		WHERE tasktype = "rfloat"
		AND taskname IN ("child")
		AND pid != ?',
		array(getmypid()));

	if (cacti_sizeof($processes)) {
		foreach($processes as $p) {
			if (cacti_process_still_running($p['pid'])) {
				cacti_log(sprintf('WARNING: Killing Cleanup %s PID %d due to another due to signal or overrun.', ucfirst($p['taskname']), $p['pid']), false, 'RFLOAT');
				if (!cacti_process_kill($p['pid'], SIGTERM, 'RFLOAT') && cacti_process_kill_denied($p['pid'])) {
					cacti_log(sprintf('WARNING: Unable to kill Cleanup %s PID %d owned by another user, leaving it registered.', ucfirst($p['taskname']), $p['pid']), false, 'RFLOAT');

					continue;
				}
			}

			unregister_process($p['tasktype'], $p['taskname'], $p['taskid'], $p['pid']);
		}
	}
}
