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

/* setup constants */
define('SPIKE_METHOD_STDDEV',   1);
define('SPIKE_METHOD_VARIANCE', 2);
define('SPIKE_METHOD_FILL',     4);
define('SPIKE_METHOD_FLOAT',    3);

class spikekill {
	/* setup defaults */
	private $std_kills = false;
	private $var_kills = false;
	private $out_kills = false;
	private $username  = '';
	private $user      = '';
	private $user_info = array();

	// Required variables
	var $rrdfile   = '';

	var $method    = '';
	var $avgnan    = '';
	var $stddev    = '';

	var $out_start = 0;
	var $out_end   = 0;
	var $outliers  = '';
	var $percent   = '';
	var $numspike  = '';

	// Overridable
	var $html      = true;
	var $backup    = false;
	var $debug     = false;
	var $dryrun    = false;

	// Defaults from cacti settings
	private $dmethod   = 1;
	private $dnumspike = 10;
	private $dstddev   = 10;
	private $dpercent  = 500;
	private $doutliers = 5;
	private $davgnan   = 'last';

	// Internal globals
	private $tempdir         = '';
	private $canonical_dirs  = array();
	private $rrdfile_stat    = false;
	private $seed            = '';
	private $strout          = '';
	private $ds_min          = '';
	private $ds_max          = '';
	private $total_kills     = 0;

	private $rra_cf      = array();
	private $ds_name     = array();
	private $rra_pdp     = array();
	private $step        = 0;

	// For error handling
	private $errors = array();

	public function __construct($rrdfile = '', $method = '', $avgnan = '', $stddev = '',
		$out_start = '', $out_end = '', $outliers = '', $percent = '', $numspike = '') {

		$this->username  = 'OsUser:' . get_current_user();
		$this->user_info = array();

		if (isset($_SESSION['sess_user_id'])) {
			$this->user = $_SESSION['sess_user_id'];

			/* confirm the user id is accurate */
			$this->user_info = db_fetch_row_prepared('SELECT id, username
				FROM user_auth
				WHERE id = ?',
				array($this->user));

			if (cacti_sizeof($this->user_info)) {
				$this->username = 'CactiUser:' . $this->user_info['username'];
			}
		}

		if ($rrdfile != '') {
			$this->rrdfile = $rrdfile;
		}

		if ($method != '') {
			$this->method = $method;
		}

		if ($avgnan != '') {
			$this->avgnan = $avgnan;
		}

		if ($stddev != '') {
			$this->stddev = $stddev;
		}

		if ($out_start != '') {
			if (!is_numeric($out_start)) {
				$this->out_start = strtotime($out_start);
			} else {
				$this->out_start = $out_start;
			}
		}

		if ($out_end != '') {
			if (!is_numeric($out_end)) {
				$this->out_end = strtotime($out_end);
			} else {
				$this->out_end = $out_end;
			}
		}

		if ($outliers != '') {
			$this->outliers = $outliers;
		}

		if ($percent != '') {
			$this->percent = $percent;
		}

		if ($numspike != '') {
			$this->numspike = $numspike;
		}

		$this->dmethod   = read_config_option('spikekill_method', true);
		$this->dnumspike = read_config_option('spikekill_number', true);
		$this->dstddev   = read_config_option('spikekill_deviations', true);
		$this->dpercent  = read_config_option('spikekill_percent', true);
		$this->doutliers = read_config_option('spikekill_outliers', true);
		$this->davgnan   = read_config_option('spikekill_avgnan', true);
	}

	public function __destruct() {
	}

	private function set_error($string) {
		$this->errors[] = $string;
	}

	private function is_error_set() {
		return cacti_sizeof($this->errors);
	}

	public function get_errors() {
		$output = '';

		if (cacti_sizeof($this->errors)) {
			foreach($this->errors as $error) {
				$output .= ($output != '' ? ($this->html ? '<br>':"\n"):'') . $error;
			}
		}

		return $output;
	}

	public function get_output($html = true) {
		return $this->strout;
	}

	private function initialize_spikekill() {
		/* additional error check */
		if ($this->rrdfile == '') {
			$this->set_error(__("FATAL: You must specify an RRDfile!"));
		}

		if (!file_exists($this->rrdfile)) {
			$this->set_error(__esc("FATAL: File '%s' does not exist.", $this->rrdfile));
		} else {
			/* file_exists() and is_writable() both follow a symlink; refuse
			   one here so the dump, backup and restore below never open a
			   path this call does not actually own.  PHP caches the last
			   stat of a path for the whole run, so the file_exists() call
			   just above would otherwise answer for this lstat() too. */
			clearstatcache(true, $this->rrdfile);

			$rrdfile_lstat = @lstat($this->rrdfile);

			if ($rrdfile_lstat === false || is_link($this->rrdfile) || !is_file($this->rrdfile)) {
				$this->set_error(__esc("FATAL: File '%s' is not a regular file.", $this->rrdfile));
			} elseif (!is_writable($this->rrdfile)) {
				$this->set_error(__esc("FATAL: File '%s' is not writable by '%s'.", $this->rrdfile, get_execution_user()));
			} else {
				/* captured once here so backupRRDFile() and
				   createRRDFileFromXML() can each re-check the name still
				   refers to this same file right before they open it */
				$this->rrdfile_stat = $rrdfile_lstat;
			}
		}

		$umethod   = read_user_setting('spikekill_method', $this->dmethod, true);
		$unumspike = read_user_setting('spikekill_number', $this->dnumspike, true);
		$ustddev   = read_user_setting('spikekill_deviations', $this->dstddev, true);
		$upercent  = read_user_setting('spikekill_percent', $this->dpercent, true);
		$uoutliers = read_user_setting('spikekill_outliers', $this->doutliers, true);
		$uavgnan   = read_user_setting('spikekill_avgnan', $this->davgnan, true);

		/* set the correct value */
		if ($this->avgnan == '') {
			if (!empty($uavgnan)) {
				$this->avgnan = $uavgnan;
			} else {
				$this->avgnan = $this->davgnan;
			}
		}

		if ($this->method == '') {
			if (!empty($umethod)) {
				$this->method = $umethod;
			} else {
				$this->method = $this->dmethod;
			}
		}

		if ($this->numspike == '') {
			if (!empty($unumspike)) {
				$this->numspike = $unumspike;
			} else {
				$this->numspike = $this->dnumspike;
			}
		}

		if ($this->stddev == '') {
			if (!empty($ustddev)) {
				$this->stddev = $ustddev;
			} else {
				$this->stddev = $this->dstddev;
			}
		}

		if ($this->percent == '') {
			if (!empty($upercent)) {
				$this->percent = $upercent;
			} else {
				$this->percent = $this->dpercent;
			}
		}

		if ($this->outliers == '') {
			if (!empty($uoutliers)) {
				$this->outliers = $uoutliers;
			} else {
				$this->outliers = $this->doutliers;
			}
		}

		if (!is_numeric($this->stddev) || ($this->stddev < 1)) {
			$this->set_error(__("FATAL: Standard Deviation must be a positive integer."));
		}

		switch($this->method) {
			/* the order of the following case statements reflects the order in the spikekill menu in the GUI. */
			case 'stddev':
				$this->method = SPIKE_METHOD_STDDEV;

				$dispmethod = __('StdDev');

				break;
			case 'variance':
				$this->method = SPIKE_METHOD_VARIANCE;

				$dispmethod = __('Variance');

				break;
			case 'fill':
				$this->method = SPIKE_METHOD_FILL;

				$dispmethod = __('Gap Fill');

				break;
			case 'float':
				$this->method = SPIKE_METHOD_FLOAT;

				$dispmethod = __('Float Range');

				break;
			default:
				$dispmethod = __('Unknown');

				$this->set_error(__("FATAL: You must specify either 'stddev', 'variance', 'float', or 'fill' as methods."));

				break;
		}

		if ($this->method == 'float' || $this->method == 'fill') {
			if (!is_numeric($this->out_start)) {
				$this->out_start = strtotime($this->out_start);
			}

			if (!is_numeric($this->out_end)) {
				$this->out_end = strtotime($this->out_end);
			}

			if ($this->out_start === false || $this->out_end === false) {
				$this->set_error(__("FATAL: The outlier-start and outlier-end arguments must be in the format of YYYY-MM-DD HH:MM or a unix timestamp."));
			}

			if (!is_numeric($this->outliers) || ($this->outliers < 1)) {
				$this->set_error(__("FATAL: The number of outliers to exclude must be a positive integer."));
			}
		}

		if ($this->percent != '') {
			if (is_numeric($this->percent) && $this->percent > 0) {
				$this->percent = $this->percent/100;
			} else {
				$this->set_error(__("FATAL: Percent deviation must be a positive floating point number."));
			}
		}

		if (!$this->numspike != '') {
			if (!is_numeric($this->numspike) || ($this->numspike < 1)) {
				$this->set_error(__("FATAL: Number of spikes to remove must be a positive integer"));
			}
		}

		if ((!empty($this->out_start) && empty($this->out_end)) || (!empty($this->out_end) && empty($this->out_start))) {
			$this->set_error(__("FATAL: Outlier time range requires outlier-start and outlier-end to be specified."));
		}

		// Check a bad range of the window start and end
		if (!empty($this->out_start)) {
			if ($this->out_start >= $this->out_end) {
				$this->set_error(__("FATAL: Outlier time range requires outlier-start to be less than outlier-end."));
			}
		}

		if ($this->method == SPIKE_METHOD_FLOAT && empty($this->out_start)) {
			$this->set_error(__("FATAL: The 'float' removal method requires the specification of a start and end date."));
		}

		if ($this->method == SPIKE_METHOD_FILL && empty($this->out_start)) {
			$this->set_error(__("FATAL: The 'gapfill' removal method requires the specification of a start and end date."));
		}

		switch($this->avgnan) {
			case 'avg':
			case 'last':
			case 'nan':
				break;
			default:
				$this->set_error(__("FATAL: You must specify either 'last', 'avg' or 'nan' as a replacement method."));
		}

		$this->strout .= ($this->html ? "<h3 class='spikekillNote'>":'') . __('Spike Kill Settings Used for Analysis/Correction') . ($this->html ? '</h3><hr>': PHP_EOL);

		if (!$this->html) {
			$this->strout .= '------------------------------------------------' . PHP_EOL;
		}

		$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __esc('Method:        %s', $dispmethod) . ($this->html ? '</p>': PHP_EOL);
		$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __esc('RRDfile:       %s', $this->rrdfile) . ($this->html ? '</p>': PHP_EOL);
		$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __esc('Repair Type:   %s', ucfirst($this->avgnan)) . ($this->html ? '</p>': PHP_EOL);

		if ($this->method == SPIKE_METHOD_STDDEV || $this->method == SPIKE_METHOD_VARIANCE) {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __esc('Num Outliers:  %s', $this->outliers) . ($this->html ? '</p>': PHP_EOL);
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __esc('Max Kills:     %s', $this->numspike) . ($this->html ? '</p>': PHP_EOL);
		} else {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __('Max Kills:     Unlimited') . ($this->html ? '</p>': PHP_EOL);
		}

		if ($this->method == SPIKE_METHOD_STDDEV) {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __esc('Standard Devs: %s', $this->stddev) . ($this->html ? '</p>': PHP_EOL);
		} elseif ($this->method == SPIKE_METHOD_VARIANCE) {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __esc('Variance %%%:    %s %%%', number_format_i18n($this->percent * 100, 2)) . ($this->html ? '</p>': PHP_EOL);
		}

		if ($this->out_start > 0) {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __esc('Window Start:  %s (%s)' . PHP_EOL, $this->out_start, date('Y-m-d H:i', $this->out_start)) . ($this->html ? '</p>': PHP_EOL);
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __esc('Window End:    %s (%s)', $this->out_end, date('Y-m-d H:i', $this->out_end)) . ($this->html ? '</p>': PHP_EOL . PHP_EOL);
		} else {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . __('Window Range:  All') .  ($this->html ? '</p>': PHP_EOL . PHP_EOL);
		}

		if ($this->html) {
			$this->strout .= '<hr>';
		}

		return false;
	}

	public function remove_spikes() {
		global $config;

		$this->strout = '';

		$this->initialize_spikekill();

		if ($this->is_error_set()) {
			return false;
		}

		/* determine the temporary file name */
		$this->seed = mt_rand();

		if ($config['cacti_server_os'] == 'win32') {
			$this->tempdir = $this->normalizeDir(read_config_option('spikekill_backupdir'));
			$bakfile = $this->tempdir . '/' . str_replace('.rrd', '', basename($this->rrdfile)) . '.backup.' . $this->seed . '.rrd';
		} else {
			$this->tempdir = $this->normalizeDir(read_config_option('spikekill_backupdir'));
			$bakfile = $this->tempdir . '/' . str_replace('.rrd', '', basename($this->rrdfile)) . '.backup.' . $this->seed . '.rrd';
		}

		$bakfile_stat = false;

		if (!empty($this->out_start) && !$this->dryrun) {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . "NOTE: Removing Outliers in Range and Replacing with Last" . ($this->html ? "</p>\n":"\n");
		}

		if ($this->method == SPIKE_METHOD_VARIANCE) {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>" : '') . sprintf("NOTE: Variance Calculation removes top and bottom %s samples due to Outliers setting", $this->outliers) . ($this->html ? "</p>\n" : "\n");
		}

		/* create the temporary XML dump file exclusively; this runs as root
		   from cli/removespikes.php and batchgapfix, into a directory the
		   poller-writable web user controls, so the name must be
		   unpredictable and the file kept open under our own handle rather
		   than reopened by name later, the same rationale copyFileSafely()
		   documents for the RRD backup */
		$xmlfile_info = $this->createXmlFileExclusively($this->tempdir);

		if ($xmlfile_info === false) {
			$this->set_error(__esc("FATAL: Unable to safely create a temporary XML file in '%s'!", $this->tempdir));
			return false;
		}

		$xmlfile        = $xmlfile_info['path'];
		$xmlfile_handle = $xmlfile_info['handle'];
		$xmlfile_stat   = $xmlfile_info['stat'];

		/* execute the dump command */
		$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . "NOTE: Creating XML file '$xmlfile' from '$this->rrdfile'" . ($this->html ? "</p>\n":"\n");

		if (!$this->dryrun) {
			switch ($this->method) {
			case SPIKE_METHOD_STDDEV:
				$mm  = 'StdDev';
				$mes = "$this->username, File:" . basename($this->rrdfile) . ", Method:$mm, StdDevs:$this->stddev, AvgNan:$this->avgnan, Kills:$this->numspike, Outliers:$this->outliers";
				break;
			case SPIKE_METHOD_VARIANCE:
				$mm  = 'Variance';
				$mes = "$this->username, File:" . basename($this->rrdfile) . ", Method:$mm, AvgNan:$this->avgnan, Kills:$this->numspike, Outliers:$this->outliers, Percent:" . round($this->percent*100,2) . "%";
				break;
			case SPIKE_METHOD_FLOAT:
				$mm  = 'RangeFloat';
				$mes = "$this->username, File:" . basename($this->rrdfile) . ", Method:$mm, OutStart:$this->out_start, OutEnd:$this->out_end, AvgNan:$this->avgnan";
				break;
			case SPIKE_METHOD_FILL:
				$mm  = 'GapFill';
				$mes = "$this->username, File:" . basename($this->rrdfile) . ", Method:$mm, OutStart:$this->out_start, OutEnd:$this->out_end, AvgNan:$this->avgnan";
				break;
			default:
				$mm  = 'Undefined';
				$mes = "$this->username, File:" . basename($this->rrdfile) . ", Method:$mm";
			}

			cacti_log($mes, false, 'SPIKEKILL');
		}

		/* dump straight into the held handle instead of a shell '>'
		   redirection, so there is never a by-name reopen of $xmlfile for
		   the RRDtool child process to be redirected away from */
		if (!$this->runRRDDump($this->rrdfile, $xmlfile_handle)) {
			fclose($xmlfile_handle);
			$this->unlinkOwnedFile($xmlfile, $xmlfile_stat);

			$this->set_error(__("FATAL: RRDtool Command Failed.  Please verify that the RRDtool path is valid in Settings->Paths!"));
			return false;
		}

		/* read the dumped XML back through the same handle */
		rewind($xmlfile_handle);

		$output = array();

		while (($line = fgets($xmlfile_handle)) !== false) {
			$output[] = $line;
		}

		/* backup the rrdfile if requested */
		if ($this->backup && !$this->dryrun) {
			$backup_result = $this->copyFileSafely($this->rrdfile, $bakfile, $this->tempdir);

			if ($backup_result !== false) {
				$bakfile      = $backup_result['path'];
				$bakfile_stat = $backup_result['stat'];
				$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . "NOTE: RRDfile '$this->rrdfile' backed up to '$bakfile'" . ($this->html ? "</p>\n":"\n");
			} else {
				$this->set_error(__esc("FATAL: RRDfile Backup of '%s' to '%s' FAILED!", $this->rrdfile, $bakfile));

				fclose($xmlfile_handle);
				$this->unlinkOwnedFile($xmlfile, $xmlfile_stat);

				return false;
			}
		}

		if ($this->is_error_set()) {
			fclose($xmlfile_handle);
			$this->unlinkOwnedFile($xmlfile, $xmlfile_stat);

			return false;
		}

		/* process the xml file and remove all comments */
		$output = $this->removeComments($output);

		/**
		 * Read all the rra's ds values and obtain the following pieces of information from each
		 *  rra archive.
		 *
		 *  - numsamples   - The number of 'valid' non-nan samples
		 *  - sumofsamples - The sum of all 'valid' samples.
		 *  - average      - The average of all samples
		 *  - stddev       - The standard deviation of all samples
		 *  - max_value    - The maximum value of all samples
		 *  - min_value    - The minimum value of all samples
		 *  - max_cutoff   - Any value above this value will be set to the average.
		 *  - min_cutoff   - Any value lower than this value will be set to the average.
		 *
		 * This will end up being a n-dimensional array as follows:
		 *
		 * rra[x][ds#]['totalsamples'];
		 * rra[x][ds#]['numsamples'];
		 * rra[x][ds#]['sumofsamples'];
		 * rra[x][ds#]['average'];
		 * rra[x][ds#]['stddev'];
		 * rra[x][ds#]['max_value'];
		 * rra[x][ds#]['min_value'];
		 * rra[x][ds#]['max_cutoff'];
		 * rra[x][ds#]['min_cutoff'];
		 *
		 * There will also be a secondary array created with the actual samples.  This
		 * array will be used to calculate the standard deviation of the sample set.
		 * samples[rra_num][ds_num][timestamp];
		 *
		 * Also track the min and max value for each ds and store it into the two
		 * arrays: ds_min[ds#], ds_max[ds#].
		 *
		 * The we don't need to know the type of rra, only it's number for this analysis
		 * the same applies for the ds' as well.
		 */
		$rra     = array();
		$this->rra_cf  = array();
		$this->rra_pdp = array();

		$rra_num = 0;
		$ds_num  = 0;

		$this->total_kills = 0;

		$in_rra  = false;
		$in_db   = false;

		$this->ds_min  = array();
		$this->ds_max  = array();

		$this->ds_name = array();

		/**
		 * perform a first pass on the array and do the following:
		 *
		 * 1) Get the number of good samples per ds
		 * 2) Get the sum of the samples per ds
		 * 3) Get the max and min values for all samples
		 * 4) Build both the rra and sample arrays
		 * 5) Get each ds' min and max values
		 *
		 */
		if (cacti_sizeof($output)) {
			foreach($output as $line) {
				if (substr_count($line, '<v>')) {
					$linearray = explode('<v>', $line);

					/* get the timestamp */
					$timestamp_part = $linearray[0];
					if (strpos($timestamp_part, '<timestamp>') !== false) {
						$timestamp_part = str_replace('<row><timestamp>', '', $timestamp_part);
						$timestamp_part = str_replace('</timestamp>', '', $timestamp_part);
						$timestamp      = intval(trim($timestamp_part));
					} else {
						$timestamp = 0;
					}

					/* discard the first piece of the exploded line */
					array_shift($linearray);
					$ds_num = 0;

					foreach($linearray as $dsvalue) {
						/* peel off garbage */
						$dsvalue = trim(str_replace('</row>', '', str_replace('</v>', '', $dsvalue)));

						/* check for outlier territory */
						if ($timestamp > 0) {
							if ($this->method == SPIKE_METHOD_FILL || $this->method == SPIKE_METHOD_FLOAT) {
								if ($timestamp < $this->out_start) {
									if (is_numeric($dsvalue)) {
										$rra[$rra_num][$ds_num]['last'] = $dsvalue;
									}

									$process = true;
								} elseif ($timestamp >= $this->out_start && $timestamp <= $this->out_end) {
									if ($this->method == SPIKE_METHOD_FILL) {
										if (!is_numeric($dsvalue)) {
											$this->debug(sprintf('Fill Found, RRA:%s, DSNum:%s, Date:%s, CurVal:%s', $rra_num, $ds_num, date('Y-m-d H:i:s', $timestamp), $dsvalue));
										}
									} else {
										$this->debug(sprintf('Float Found, RRA:%s, DSNum:%s, Date:%s, CurVal:%s', $rra_num, $ds_num, date('Y-m-d H:i:s', $timestamp), $dsvalue));
									}

									$process = false;
								} else {
									$process = true;
								}
							} else {
								$process = true;
							}
						} else {
							$this->debug('WARNING: Illegal Timestamp Found');

							$process = true;
						}

						if (is_numeric($dsvalue) && $process) {
							if (!isset($rra[$rra_num][$ds_num]['numsamples'])) {
								$rra[$rra_num][$ds_num]['numsamples'] = 1;
							} else {
								$rra[$rra_num][$ds_num]['numsamples']++;
							}

							if (!isset($rra[$rra_num][$ds_num]['sumofsamples'])) {
								$rra[$rra_num][$ds_num]['sumofsamples'] = $dsvalue;
							} elseif (is_numeric($dsvalue)) {
								$rra[$rra_num][$ds_num]['sumofsamples'] += $dsvalue;
							}

							if (!isset($rra[$rra_num][$ds_num]['max_value'])) {
								$rra[$rra_num][$ds_num]['max_value'] = $dsvalue;
							} elseif ($dsvalue > $rra[$rra_num][$ds_num]['max_value']) {
								$rra[$rra_num][$ds_num]['max_value'] = $dsvalue;
							}

							if (!isset($rra[$rra_num][$ds_num]['min_value'])) {
								$rra[$rra_num][$ds_num]['min_value'] = $dsvalue;
							} elseif ($dsvalue < $rra[$rra_num][$ds_num]['min_value']) {
								$rra[$rra_num][$ds_num]['min_value'] = $dsvalue;
							}
						}

						/* store the sample for standard deviation calculation */
						if ($timestamp == 0) {
							$samples[$rra_num][$ds_num][] = $dsvalue;
						} else {
							$samples[$rra_num][$ds_num][$timestamp] = $dsvalue;
						}

						if (!isset($rra[$rra_num][$ds_num]['totalsamples'])) {
							$rra[$rra_num][$ds_num]['totalsamples'] = 1;
						} else {
							$rra[$rra_num][$ds_num]['totalsamples']++;
						}

						$ds_num++;
					}
				} elseif (substr_count($line, '<rra>')) {
					$in_rra = true;
				} elseif (substr_count($line, '<min>')) {
					$this->ds_min[] = trim(str_replace('<min>', '', str_replace('</min>', '', trim($line))));
				} elseif (substr_count($line, '<max>')) {
					$this->ds_max[] = trim(str_replace('<max>', '', str_replace('</max>', '', trim($line))));
				} elseif (substr_count($line, '<name>')) {
					$this->ds_name[] = trim(str_replace('<name>', '', str_replace('</name>', '', trim($line))));
				} elseif (substr_count($line, '<cf>')) {
					$this->rra_cf[] = trim(str_replace('<cf>', '', str_replace('</cf>', '', trim($line))));
				} elseif (substr_count($line, '<pdp_per_row>')) {
					$this->rra_pdp[] = trim(str_replace('<pdp_per_row>', '', str_replace('</pdp_per_row>', '', trim($line))));
				} elseif (substr_count($line, '</rra>')) {
					$in_rra = false;
					$rra_num++;
				} elseif (substr_count($line, '<step>')) {
					$this->step = trim(str_replace('<step>', '', str_replace('</step>', '', trim($line))));
				}
			}
		}

		cacti_log("DEBUG: number of RRAs: {$rra_num}", false, 'SPIKE', POLLER_VERBOSITY_DEBUG);
		cacti_log("DEBUG: number of DSes: {$ds_num}", false, 'SPIKE', POLLER_VERBOSITY_DEBUG);

		/* For all the samples determine the average with the outliers removed */
		$this->calculateVarianceAverages($rra, $samples);

		/**
		 * Now scan the rra array and the samples array and calculate the following
		 *
		 * 1) The standard deviation of all samples
		 * 2) The average of all samples per ds
		 * 3) The max and min cutoffs of all samples
		 * 4) The number of kills in each ds based upon the thresholds
		 *
		 */
		if (empty($this->out_start)) {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
				__esc("NOTE: Searching for Spikes in XML file '%s'", $xmlfile) . ($this->html ? "</p>\n":"\n");
		} else {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
				__esc("NOTE: Limited to Time Window: %s through %s", date('M j, Y H:i:s',$this->out_start), date('M j, Y H:i:s',$this->out_end)) . ($this->html ? "</p>\n":"\n");

			cacti_log("DEBUG: Limited to Time Window: " . date('M j, Y H:i:s',$this->out_start) . " thru " . date('M j, Y H:i:s',$this->out_end), false, 'SPIKE', POLLER_VERBOSITY_DEBUG);
		}

		if ($this->dryrun) {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
				__("NOTE: Dryrun requested.  No updates performed") . ($this->html ? "</p><br>\n":"\n");
		}

		$this->calculateOverallStatistics($rra, $samples);

		/* debugging and/or status report */
		if ($this->debug || $this->dryrun) {
			if ($this->html) {
				$this->strout .= "<div style='overflow-x:auto;'><table style='width:100%' class='spikekillData' id='spikekillData'>";
			}

			$this->outputStatistics($rra);

			if ($this->html) {
				$this->strout .= '</table></div><br>';
			}
		}

		$new_output = '';
		$continue   = false;

		/* create an output array */
		if ($this->std_kills || $this->out_kills || $this->var_kills) {
			$this->debug('Either std_kills or out_kills found');

			if (!$this->dryrun) {
				$new_output = $this->updateXML($output, $rra);
				$output   = true;
				$continue = true;
			} else {
				$new_output = $this->updateXML($output, $rra);
				$output     = false;
				$continue   = false;
			}
		} elseif ($this->out_start > 0) {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
				__esc("NOTE: No Window Spikes found in '%s'", $this->rrdfile) . ($this->html ? "</p>\n":"\n");
		} else {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
				__esc("NOTE: No Spikes found in '%s'", $this->rrdfile) . ($this->html ? "</p>\n":"\n");
		}

		/* finally update the file XML file and Reprocess the RRDfile */
		$restored = true;

		if (!$this->dryrun) {
			if ($continue) {
				if ($output == true && $new_output != '') {
					if ($this->writeXMLFile($new_output, $xmlfile_handle)) {
						if ($this->backupRRDFile($this->rrdfile)) {
							if ($this->createRRDFileFromXML($xmlfile, $this->rrdfile, $xmlfile_stat)) {
								$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
									__('NOTE: Spikes Found and Remediated.  Total Spikes %s', $this->total_kills) . ($this->html ? "</p>\n":"\n");
							} else {
								$restored = false;

								$message = __esc("FATAL: Unable to restore '%s' from '%s'", $this->rrdfile, $xmlfile);

								$this->set_error($message);

								$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
									$message . ($this->html ? "</p>\n":"\n");
							}
						} else {
							$restored = false;

							$message = __esc("FATAL: Unable to backup '%s'", $this->rrdfile);

							$this->set_error($message);

							$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
								$message . ($this->html ? "</p>\n":"\n");
						}
					} else {
						$restored = false;

						$message = __esc("FATAL: Unable to write XML file '%s'", $xmlfile);

						$this->set_error($message);

						$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
							$message . ($this->html ? "</p>\n":"\n");
					}
				} else {
					$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
						__("NOTE: No Spikes Found.") . ($this->html ? "</p>\n":"\n");
				}
			}
		}

		$this->strout .= ($this->html ? "</table>":'');

		if ($this->total_kills > 0) {
			cacti_log("WARNING: Removed '$this->total_kills' Spikes from '$this->rrdfile', Method:'$this->method'", false, 'WEBUI');
		} elseif($this->debug) {
			cacti_log("NOTE: Removed '$this->total_kills' Spikes from '$this->rrdfile', Method:'$this->method'", false, 'WEBUI');
		}

		fclose($xmlfile_handle);
		$this->unlinkOwnedFile($xmlfile, $xmlfile_stat);

		$this->unlinkOwnedFile($bakfile, $bakfile_stat);

		return $restored;
	}

	/* All Functions */
	private function createRRDFileFromXML($xmlfile, $rrdfile, $stat) {
		/* rrdtool restore has to read the XML by path.  Re-check right
		   before running it that the name still refers to the file this
		   call wrote through: nothing stops the name from being swapped
		   between the write above and rrdtool's own open() here, so this
		   narrows but does not close the window.  Accepted residual for
		   1.2. */
		/* PHP caches the last lstat() of a path for the whole run, so an
		   earlier lookup of this name would otherwise answer for it */
		clearstatcache(true, $xmlfile);

		$lstat = @lstat($xmlfile);

		if ($lstat === false || $lstat['dev'] !== $stat['dev'] || $lstat['ino'] !== $stat['ino']) {
			return false;
		}

		/* likewise for the restore destination: re-check it against the
		   identity initialize_spikekill() captured, right before rrdtool
		   restore opens it by name */
		clearstatcache(true, $rrdfile);

		$rrdfile_lstat = @lstat($rrdfile);

		if ($rrdfile_lstat === false || $this->rrdfile_stat === false
			|| $rrdfile_lstat['dev'] !== $this->rrdfile_stat['dev']
			|| $rrdfile_lstat['ino'] !== $this->rrdfile_stat['ino']) {

			return false;
		}

		/* execute the restore command */
		$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
			__esc("NOTE: Re-Importing '%s' to '%s'", $xmlfile, $rrdfile) . ($this->html ? "</p>\n":"\n");

		/* argv array through runRRDCommand(), the same as runRRDDump(),
		   instead of a shell string whose exit status went unchecked */
		$argv   = array(read_config_option('path_rrdtool'), 'restore', '-f', '-r', $xmlfile, $rrdfile);
		$result = $this->runRRDCommand($argv, null, $this->commandTimeout());

		$response = trim($result['stdout'] . $result['stderr']);

		if ($response != '') {
			$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') . $response . ($this->html ? "</p>\n":"\n");
		}

		return $result['exit'] === 0;
	}

	private function writeXMLFile($output, $handle) {
		if (!is_resource($handle)) {
			return false;
		}

		$data = is_array($output) ? implode('', $output) : $output;

		if (!ftruncate($handle, 0) || !rewind($handle)) {
			return false;
		}

		$written = fwrite($handle, $data);

		if ($written === false || $written !== strlen($data)) {
			return false;
		}

		return fflush($handle);
	}

	private function backupRRDFile($rrdfile) {
		/* re-check the source identity initialize_spikekill() captured:
		   nothing stops the name from being swapped for a symlink between
		   that check and this copy */
		clearstatcache(true, $rrdfile);

		$rrdfile_lstat = @lstat($rrdfile);

		if ($rrdfile_lstat === false || $this->rrdfile_stat === false
			|| $rrdfile_lstat['dev'] !== $this->rrdfile_stat['dev']
			|| $rrdfile_lstat['ino'] !== $this->rrdfile_stat['ino']) {

			return false;
		}

		$backupdir = $this->normalizeDir(read_config_option('spikekill_backupdir'));

		if ($backupdir == '') {
			$backupdir = $this->tempdir;
		}

		$backup_result = $this->copyFileSafely($rrdfile, $backupdir . '/' . basename($rrdfile), $backupdir);

		if ($backup_result === false) {
			return false;
		}

		$this->strout .= ($this->html ? "<p class='spikekillNote'>":'') .
			__esc("NOTE: Backing Up '%s' to '%s'", $rrdfile, $backup_result['path']) . ($this->html ? "</p>\n":"\n");

		return true;
	}

	/**
	 * copyFileSafely - copy $source to $desired_path without ever following or
	 * clobbering whatever already sits at that name.  removespikes and
	 * batchgapfix run this as root, and $desired_path lives in a directory the
	 * web user's poller can write to, so a symlink planted there ahead of time
	 * must not be followed: the target gets created exclusively, and if the
	 * name is already taken (by a symlink or a real file) a unique sibling
	 * name is used instead.  The exclusive open only protects the final
	 * name; a symlink planted at the directory itself, or at an ancestor
	 * resolved earlier and swapped since, would still redirect the write, so
	 * the directory is re-checked here: refused outright if it is a symlink,
	 * and refused if its current realpath() no longer matches $configured_dir's
	 * canonical path (resolved once and cached, so a later swap is caught
	 * against the trusted value rather than against itself).
	 *
	 * @param  (string)      $source
	 * @param  (string)      $desired_path
	 * @param  (string|null) $configured_dir - the admin-configured directory
	 *                        $desired_path is expected to live in; null skips
	 *                        the canonical-path check (used by tests that
	 *                        exercise the exclusive-open behavior directly)
	 *
	 * @return (array|false) - array('path' => ..., 'stat' => ...) for the
	 *                         path actually written and its fstat() at
	 *                         creation time, or false on failure
	 */
	private function copyFileSafely($source, $desired_path, $configured_dir = null) {
		$dir      = dirname($desired_path);
		$basename = basename($desired_path);

		/* PHP caches stat results and resolved paths for the whole run.
		   The whole realpath cache is dropped, not just $dir's entry,
		   because realpath() below resolves $dir through cached ancestor
		   entries that a swapped parent directory would leave stale */
		clearstatcache(true);

		if (is_link($dir)) {
			return false;
		}

		if ($configured_dir !== null) {
			$canonical_dir = $this->canonicalDir($configured_dir);

			if ($canonical_dir === false || realpath($dir) !== $canonical_dir) {
				return false;
			}
		}

		if (is_link($desired_path) || file_exists($desired_path)) {
			$handle = false;
		} else {
			$old_umask = umask(0177);
			$handle    = @fopen($desired_path, 'xb');
			umask($old_umask);
		}

		if ($handle === false) {
			/* name already taken (or lost a race creating it); create a
			   unique sibling exclusively in the same directory instead of
			   following or overwriting it.  'xb' refuses any existing
			   name, including a symlink, so the file is born 0600 under
			   the umask with no separate by-name permission or reopen
			   step afterward. */
			for ($i = 0; $i < 10; $i++) {
				$candidate = $dir . '/' . $basename . '.' . bin2hex(random_bytes(8));

				$old_umask = umask(0177);
				$handle    = @fopen($candidate, 'xb');
				umask($old_umask);

				if ($handle !== false) {
					$desired_path = $candidate;
					break;
				}
			}

			if ($handle === false) {
				return false;
			}
		}

		$source_handle = fopen($source, 'rb');

		if ($source_handle === false) {
			/* capture the identity before closing: Windows refuses to
			   delete a file while a handle to it is still open, and the
			   identity check below has to run against the stat taken at
			   creation, not a fresh one, so a symlink swapped in after
			   the close is never followed */
			$fstat = fstat($handle);
			fclose($handle);

			$this->unlinkOwnedFile($desired_path, $fstat);

			return false;
		}

		$source_size = fstat($source_handle)['size'];

		$copied = stream_copy_to_stream($source_handle, $handle);

		fclose($source_handle);

		$fstat = fstat($handle);
		fclose($handle);

		/* stream_copy_to_stream() returns the byte count it wrote, not a
		   pass/fail flag, so a short write (a full disk, a quota) still
		   returns a truthy int and must be caught by comparing against
		   the source size rather than testing for false alone */
		if ($copied === false || $copied !== $source_size) {
			$this->unlinkOwnedFile($desired_path, $fstat);

			return false;
		}

		return array('path' => $desired_path, 'stat' => $fstat);
	}

	/**
	 * createXmlFileExclusively - create an empty file with a random name in
	 * $tempdir, exclusively and under a restrictive umask, for the RRDtool
	 * dump/restore round trip in remove_spikes().  This runs as root from
	 * cli/removespikes.php and batchgapfix into a directory the poller
	 * user's web process can write to, so the name must not be guessable
	 * and $tempdir itself is refused if it is a symlink or resolves away
	 * from its canonical path, the same checks copyFileSafely() applies to
	 * the RRD backup directory.
	 *
	 * @param  (string) $tempdir
	 *
	 * @return (array|false) - array('path' => ..., 'handle' => ..., 'stat' => ...)
	 *                         opened read/write, or false on failure
	 */
	private function createXmlFileExclusively($tempdir) {
		/* the whole cache, for the same reason as in copyFileSafely() */
		clearstatcache(true);

		if ($tempdir == '' || is_link($tempdir) || !is_dir($tempdir)) {
			return false;
		}

		$canonical_dir = $this->canonicalDir($tempdir);

		if ($canonical_dir === false || realpath($tempdir) !== $canonical_dir) {
			return false;
		}

		for ($i = 0; $i < 10; $i++) {
			$candidate = $tempdir . '/spikekill.' . bin2hex(random_bytes(8)) . '.xml';

			$old_umask = umask(0177);
			$handle    = @fopen($candidate, 'xb+');
			umask($old_umask);

			if ($handle !== false) {
				return array('path' => $candidate, 'handle' => $handle, 'stat' => fstat($handle));
			}
		}

		return false;
	}

	/**
	 * runRRDCommand - run an rrdtool subcommand via proc_open, with argv
	 * reaching execve() as literal arguments the same as cacti_exec() in
	 * lib/functions.php, and drain its pipes with stream_select() instead
	 * of a blocking read of one before the other: reading stdout to
	 * completion before ever touching stderr (or vice versa) can deadlock
	 * if rrdtool fills the unread pipe and blocks writing to it while this
	 * process is still blocked reading the other one.
	 *
	 * @param  (array)         $argv
	 * @param  (resource|null) $stdout_handle - when given, stdout is
	 *                          written straight into this already-open
	 *                          handle instead of being captured, so a dump
	 *                          is never reopened by name to redirect it;
	 *                          the returned 'stdout' is then always ''
	 * @param  (int)           $timeout - seconds to wait for the command
	 *
	 * @return (array) array('exit' => int|false, 'stdout' => string, 'stderr' => string)
	 */
	private function runRRDCommand(array $argv, $stdout_handle, $timeout = 30) {
		$capture_stdout = ($stdout_handle === null);

		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => $capture_stdout ? array('pipe', 'w') : $stdout_handle,
			2 => array('pipe', 'w'),
		);

		$process = @proc_open($argv, $descriptors, $pipes);

		if (!is_resource($process)) {
			return array('exit' => false, 'stdout' => '', 'stderr' => '');
		}

		fclose($pipes[0]);

		if ($capture_stdout) {
			stream_set_blocking($pipes[1], false);
		}

		stream_set_blocking($pipes[2], false);

		$stdout    = '';
		$stderr    = '';
		$remaining = (int) $timeout * 1000000;
		$exit      = null;

		while ($remaining > 0) {
			$start  = microtime(true);
			$read   = $capture_stdout ? array($pipes[1], $pipes[2]) : array($pipes[2]);
			$write  = array();
			$except = array();
			stream_select($read, $write, $except, 0, $remaining);

			usleep(50000);

			$status = proc_get_status($process);

			if ($capture_stdout) {
				$stdout .= stream_get_contents($pipes[1]);
			}

			$stderr .= stream_get_contents($pipes[2]);

			/* proc_get_status() returns false on a dead handle. Preserve a
			   valid exitcode while it is observable because a later status
			   read or proc_close() can return -1 after the child has
			   already been reaped. */
			if (!is_array($status) || empty($status['running'])) {
				if (is_array($status) && isset($status['exitcode']) && $status['exitcode'] >= 0) {
					$exit = (int) $status['exitcode'];
				}

				break;
			}

			$remaining -= (int) ((microtime(true) - $start) * 1000000);
		}

		if ($capture_stdout) {
			fclose($pipes[1]);
		}

		fclose($pipes[2]);

		$status = proc_get_status($process);

		if (is_array($status) && !empty($status['running'])) {
			if (isset($status['pid']) && function_exists('posix_kill')) {
				posix_kill($status['pid'], 9);
			}

			proc_terminate($process, 9);
			proc_close($process);

			return array('exit' => false, 'stdout' => $stdout, 'stderr' => $stderr);
		}

		if ($exit === null && is_array($status) && isset($status['exitcode']) && $status['exitcode'] >= 0) {
			$exit = (int) $status['exitcode'];
		}

		$close_exit = proc_close($process);

		if ($exit === null) {
			$exit = $close_exit;
		}

		return array('exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr);
	}

	/**
	 * runRRDDump - run 'rrdtool dump' with its stdout going straight into
	 * an already-open file handle via runRRDCommand(), instead of a shell
	 * '>' redirection that would reopen the destination by name.
	 *
	 * @param  (string)   $rrdfile
	 * @param  (resource) $handle
	 *
	 * @return (bool)
	 */
	private function runRRDDump($rrdfile, $handle) {
		$argv = array(read_config_option('path_rrdtool'), 'dump', $rrdfile);

		$result = $this->runRRDCommand($argv, $handle, $this->commandTimeout());

		if ($result['exit'] !== 0) {
			if (trim($result['stderr']) != '') {
				cacti_log("ERROR: rrdtool dump failed for '$rrdfile': " . trim($result['stderr']), false, 'SPIKEKILL');
			}

			return false;
		}

		return true;
	}

	/**
	 * commandTimeout - the ceiling runRRDCommand() waits for the rrdtool
	 * dump/restore round trip in remove_spikes().  1.2.31 ran both through
	 * the shell_exec builtin with no deadline of its own; runRRDCommand()'s
	 * stream_select() loop needs some bound to avoid stalling forever on a
	 * wedged rrdtool, so it uses the same 'spikekill_timeout' setting that
	 * already bounds the whole spikekill run (poller_spikekill.php), up to
	 * 8 hours, instead of the 30 second default meant for a caller that
	 * doesn't pass one.
	 *
	 * @return (int)
	 */
	private function commandTimeout() {
		$configured = (int) read_config_option('spikekill_timeout');

		return $configured > 0 ? $configured : 3600;
	}

	/**
	 * normalizeDir - strip a trailing directory separator from a configured
	 * or derived directory before it is passed to is_link(), canonicalDir()
	 * or used to build a path.  spikekill_backupdir defaults to a path with
	 * a trailing slash (include/global_settings.php), and is_link('dir/')
	 * follows the final symlink to stat what it points at instead of the
	 * link itself, so a symlinked backup directory would otherwise pass the
	 * is_link() check it is meant to fail.  Delegates to
	 * cacti_trim_dir_separator() (lib/functions.php), the same helper
	 * poller_spikekill.php's purge_spike_backups() uses, so both trim the
	 * same way on Windows too.
	 *
	 * @param  (string) $dir
	 *
	 * @return (string)
	 */
	private function normalizeDir($dir) {
		return cacti_trim_dir_separator($dir, DIRECTORY_SEPARATOR);
	}

	/**
	 * canonicalDir - resolve a configured directory's real path once and
	 * cache it, so every copyFileSafely() call for that directory compares
	 * against the same trusted value instead of re-resolving a path whose
	 * ancestor a symlink could redirect between calls in the same run.
	 *
	 * @param  (string) $configured_dir
	 *
	 * @return (string|false)
	 */
	private function canonicalDir($configured_dir) {
		if (!array_key_exists($configured_dir, $this->canonical_dirs)) {
			$this->canonical_dirs[$configured_dir] = realpath($configured_dir);
		}

		return $this->canonical_dirs[$configured_dir];
	}

	/**
	 * unlinkOwnedFile - remove a file this call created, but only after
	 * confirming the name still refers to that same file.  Comparing the
	 * device/inode captured when we created it against a fresh lstat() means
	 * a symlink swapped in after creation is never followed or removed.
	 *
	 * @param  (string)      $path
	 * @param  (array|false) $fstat - fstat() of the handle at creation time
	 *
	 * @return (void)
	 */
	private function unlinkOwnedFile($path, $fstat) {
		/* PHP caches the last lstat() of a path for the whole run, so an
		   earlier lookup of this name would otherwise answer for it */
		clearstatcache(true, $path);

		if (is_link($path) || $fstat === false) {
			return;
		}

		$lstat = @lstat($path);

		if ($lstat === false || $lstat['dev'] !== $fstat['dev'] || $lstat['ino'] !== $fstat['ino']) {
			return;
		}

		@unlink($path);
	}

	private function calculateVarianceAverages(&$rra, &$samples) {
		if (cacti_sizeof($samples)) {
			foreach($samples as $rra_num => $dses) {
				if (cacti_sizeof($dses)) {
					foreach($dses as $ds_num => $ds) {
						if (cacti_sizeof($ds) < $this->outliers * 3) {
							$rra[$rra_num][$ds_num]['variance_avg'] = 'NAN';
						} else {
							$myds = $ds;

							/* remove NaN entries from the data set */
							if (cacti_sizeof($myds)) {
								foreach($myds as $timestamp => $value) {
									if (!is_numeric($value)) {
										unset($myds[$timestamp]);
									}
								}
							}

							/* remove high outliers */
							rsort($myds, SORT_NUMERIC);
							$myds = array_slice($myds, $this->outliers);

							/* remove low outliers */
							sort($myds, SORT_NUMERIC);
							$myds = array_slice($myds, $this->outliers);

							if (cacti_sizeof($myds)) {
								$rra[$rra_num][$ds_num]['variance_avg'] = array_sum($myds) / cacti_sizeof($myds);
							} else {
								$rra[$rra_num][$ds_num]['variance_avg'] = 'NAN';
							}
						}
					}
				}
			}
		}
	}

	private function calculateOverallStatistics(&$rra, &$samples) {
		$rra_num = 0;

		if (cacti_sizeof($rra)) {
			foreach($rra as $dses) {
				$ds_num = 0;

				if (cacti_sizeof($dses)) {
					foreach($dses as $ds) {
						if (isset($samples[$rra_num][$ds_num])) {
							$rra[$rra_num][$ds_num]['stddev'] = $this->processStandardDeviationCalculation($samples[$rra_num][$ds_num]);
							if ($rra[$rra_num][$ds_num]['stddev'] == 'NAN') {
								$rra[$rra_num][$ds_num]['stddev'] = 0;
							}

							if (isset($rra[$rra_num][$ds_num]['sumofsamples']) && isset($rra[$rra_num][$ds_num]['numsamples'])) {
								if ($rra[$rra_num][$ds_num]['numsamples'] > 0) {
									$rra[$rra_num][$ds_num]['average'] = $rra[$rra_num][$ds_num]['sumofsamples'] / $rra[$rra_num][$ds_num]['numsamples'];
								} else {
									$rra[$rra_num][$ds_num]['average'] = 0;
								}
							} else {
								$rra[$rra_num][$ds_num]['average'] = 0;
							}

							$rra[$rra_num][$ds_num]['min_cutoff'] = $rra[$rra_num][$ds_num]['average'] - ($this->stddev * $rra[$rra_num][$ds_num]['stddev']);
							if ($rra[$rra_num][$ds_num]['min_cutoff'] < $this->ds_min[$ds_num]) {
								$rra[$rra_num][$ds_num]['min_cutoff'] = $this->ds_min[$ds_num];
							}

							$rra[$rra_num][$ds_num]['max_cutoff'] = $rra[$rra_num][$ds_num]['average'] + ($this->stddev * $rra[$rra_num][$ds_num]['stddev']);
							if ($rra[$rra_num][$ds_num]['max_cutoff'] > $this->ds_max[$ds_num]) {
								$rra[$rra_num][$ds_num]['max_cutoff'] = $this->ds_max[$ds_num];
							}

							$rra[$rra_num][$ds_num]['numnksamples'] = 0;
							$rra[$rra_num][$ds_num]['sumnksamples'] = 0;
							$rra[$rra_num][$ds_num]['avgnksamples'] = 0;

							/* go through values and find cutoffs */
							$rra[$rra_num][$ds_num]['stddev_killed']   = 0;
							$rra[$rra_num][$ds_num]['variance_killed'] = 0;
							$rra[$rra_num][$ds_num]['outwind_samples'] = 0;
							$rra[$rra_num][$ds_num]['outwind_killed']  = 0;

							/* count the number of kills required */
							if (cacti_sizeof($samples[$rra_num][$ds_num])) {
								foreach($samples[$rra_num][$ds_num] as $timestamp => $sample) {
									if ($this->method == SPIKE_METHOD_FLOAT || $this->method == SPIKE_METHOD_FILL) {
										if ($timestamp >= $this->out_start && $timestamp <= $this->out_end) {
											$rra[$rra_num][$ds_num]['outwind_samples']++;

											if ($this->method == SPIKE_METHOD_FLOAT) {
												$this->debug(sprintf("Window Float Found, Date:%s, Value:%s", date('Y-m-d H:i', $timestamp), $sample));

												$rra[$rra_num][$ds_num]['outwind_killed']++;

												$this->out_kills = true;
											} elseif ($this->method == SPIKE_METHOD_FILL) {
												if (!is_numeric($sample) || $sample == 0) {
													$this->debug(sprintf("Window GapFill Found, Date:%s, Value:%s", date('Y-m-d H:i', $timestamp), $sample));

													$rra[$rra_num][$ds_num]['outwind_killed']++;

													$this->out_kills = true;
												}
											}
										}
									} elseif ($this->method == SPIKE_METHOD_STDDEV) {
										if ($this->out_start == 0 || ($timestamp >= $this->out_start && $timestamp <= $this->out_end)) {
											if ($this->out_start > 0) {
												$rra[$rra_num][$ds_num]['outwind_samples']++;
											}

											if (($sample > $rra[$rra_num][$ds_num]['max_cutoff']) || ($sample < $rra[$rra_num][$ds_num]['min_cutoff'])) {
												$this->debug(sprintf("StdDev Found, Date:%s, Value:%.2e, StandardDev:%.2e, StdDevLimit:%.2e", date('Y-m-d H:i', $timestamp), $sample, $rra[$rra_num][$ds_num]['stddev'], ($rra[$rra_num][$ds_num]['max_cutoff'] * (1+$this->percent))));

												$rra[$rra_num][$ds_num]['stddev_killed']++;

												if ($this->out_start > 0) {
													$rra[$rra_num][$ds_num]['outwind_killed']++;
												}

												$this->std_kills = true;
											} elseif (is_numeric($sample)) {
												$rra[$rra_num][$ds_num]['numnksamples']++;
												$rra[$rra_num][$ds_num]['sumnksamples'] += $sample;
											}
										} elseif (is_numeric($sample)) {
											$rra[$rra_num][$ds_num]['numnksamples']++;
											$rra[$rra_num][$ds_num]['sumnksamples'] += $sample;
										}
									} elseif ($this->method == SPIKE_METHOD_VARIANCE) {
										if ($this->out_start == 0 || ($timestamp >= $this->out_start && $timestamp <= $this->out_end)) {
											if ($this->out_start > 0) {
												$rra[$rra_num][$ds_num]['outwind_samples']++;
											}

											if ($sample > ($rra[$rra_num][$ds_num]['variance_avg'] * (1+$this->percent))) {
												$this->debug(sprintf("Variance Found, Date:%s, Value:%.2e, VarianceDev:%.2e, VarianceLimit:%.2e", date('Y-m-d H:i', $timestamp), $sample, $rra[$rra_num][$ds_num]['variance_avg'], ($rra[$rra_num][$ds_num]['variance_avg'] * (1 + $this->percent))));

												$rra[$rra_num][$ds_num]['variance_killed']++;

												if ($this->out_start > 0) {
													$rra[$rra_num][$ds_num]['outwind_killed']++;
												}

												$this->var_kills = true;
											} elseif (is_numeric($sample)) {
												$rra[$rra_num][$ds_num]['numnksamples']++;
												$rra[$rra_num][$ds_num]['sumnksamples'] += $sample;
											}
										} elseif (is_numeric($sample)) {
											$rra[$rra_num][$ds_num]['numnksamples']++;
											$rra[$rra_num][$ds_num]['sumnksamples'] += $sample;
										}
									} elseif (is_numeric($sample)) {
										$rra[$rra_num][$ds_num]['numnksamples']++;
										$rra[$rra_num][$ds_num]['sumnksamples'] += $sample;
									}
								}
							}

							if ($rra[$rra_num][$ds_num]['numnksamples'] > 0) {
								$rra[$rra_num][$ds_num]['avgnksamples'] = $rra[$rra_num][$ds_num]['sumnksamples'] / $rra[$rra_num][$ds_num]['numnksamples'];
							}
						} else {
							$rra[$rra_num][$ds_num]['stddev']          = 'N/A';
							$rra[$rra_num][$ds_num]['average']         = 'N/A';
							$rra[$rra_num][$ds_num]['min_value']       = 'N/A';
							$rra[$rra_num][$ds_num]['max_value']       = 'N/A';
							$rra[$rra_num][$ds_num]['min_cutoff']      = 'N/A';
							$rra[$rra_num][$ds_num]['max_cutoff']      = 'N/A';
							$rra[$rra_num][$ds_num]['numnksamples']    = 'N/A';
							$rra[$rra_num][$ds_num]['sumnksamples']    = 'N/A';
							$rra[$rra_num][$ds_num]['avgnksamples']    = 'N/A';
							$rra[$rra_num][$ds_num]['stddev_killed']   = 'N/A';
							$rra[$rra_num][$ds_num]['variance_killed'] = 'N/A';
							$rra[$rra_num][$ds_num]['outwind_samples'] = 'N/A';
							$rra[$rra_num][$ds_num]['outwind_killed']  = 'N/A';
						}

						$ds_num++;
					}
				}

				$rra_num++;
			}
		}
	}

	private function outputStatistics($rra) {
		if (cacti_sizeof($rra)) {
			if (!$this->html) {
				$this->strout .= "\n";

				$this->strout .= sprintf("%10s %16s %10s %7s %7s %10s %10s %10s %10s %10s %10s %10s %10s %10s %12s %10s\n",
					'Size', 'DataSource', 'CF', 'Samples', 'NonNan', 'Avg', 'StdDev', 'Variance',
					'MaxValue', 'MinValue', 'MaxStdDev', 'MinStdDev', 'StdKilled', 'VarKilled', 'WindSamples', 'WindKilled');

				$this->strout .= sprintf("%10s %16s %10s %7s %7s %10s %10s %10s %10s %10s %10s %10s %10s %10s %12s %10s\n",
					'----------', '---------------', '----------', '-------', '-------', '----------', '----------', '----------',
					'----------', '----------', '----------', '----------', '----------', '----------', '------------',
					'----------');

				foreach($rra as $rra_key => $dses) {
					if (cacti_sizeof($dses)) {
						foreach($dses as $dskey => $ds) {
							$this->strout .= sprintf('%10s %16s %10s %7s %7s ' .
								($ds['average']    < 1E6 ? '%10s ' : ' %10.2e ') .
								($ds['stddev']     < 1E6 ? '%10s ' : ' %10.2e ') .
								($ds['max_value']  < 1E6 ? '%10s ' : ' %10.2e ') .
								($ds['min_value']  < 1E6 ? '%10s ' : ' %10.2e ') .
								($ds['max_cutoff'] < 1E6 ? '%10s ' : ' %10.2e ') .
								($ds['min_cutoff'] < 1E6 ? '%10s ' : ' %10.2e ') .
								'%10s %10s %10s %12s %10s' . PHP_EOL,
								$this->displayTime($this->rra_pdp[$rra_key]),
								$this->ds_name[$dskey],
								$this->rra_cf[$rra_key],
								number_format_i18n($ds['totalsamples']),
								(isset($ds['numsamples']) ? number_format_i18n($ds['numsamples']) : '0'),
								($ds['average']         != 'N/A' ? round($ds['average'], 2)       : 'N/A'),
								($ds['stddev']          != 'N/A' ? round($ds['stddev'], 2)        : 'N/A')
								($ds['variance_avg']    != 'N/A' ? round($ds['variance_avg'], 2)  : 'N/A'),
								($ds['max_value']       != 'N/A' ? round($ds['max_value'], 2)     : 'N/A'),
								($ds['min_value']       != 'N/A' ? round($ds['min_value'], 2)     : 'N/A'),
								($ds['max_cutoff']      != 'N/A' ? round($ds['max_cutoff'], 2)    : 'N/A'),
								($ds['min_cutoff']      != 'N/A' ? round($ds['min_cutoff'], 2)    : 'N/A'),
								($ds['stddev_killed']   != 'N/A' ? number_format_i18n($ds['stddev_killed'])   : 'N/A'),
								($ds['variance_killed'] != 'N/A' ? number_format_i18n($ds['variance_killed']) : 'N/A'),
								number_format_i18n($ds['outwind_samples']),
								number_format_i18n($ds['outwind_killed']));
						}
					}
				}

				$this->strout .= "\n";
			} else {
				$this->strout .= sprintf("<tr class='tableHeader'><th class='nowrap' style='width:10%%;'>%s</th><th>%s</th><th>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th><th class='right'>%s</th></tr>\n",
					__('Size'), __('DS'), __('CF'), __('Samples'), __('NonNan'), __('Avg'), __('StdDev'), __('Variance'),
					__('MaxValue'), __('MinValue'), __('MaxStdDev'), __('MinStdDev'), __('StdKilled'), __('VarKilled'), __('WindSamples'), __('WindKilled'));

				foreach($rra as $rra_key => $dses) {
					if (cacti_sizeof($dses)) {
						foreach($dses as $dskey => $ds) {
							$this->strout .= sprintf('<tr>' .
								'<td class="nowrap">%s</td>' .
								'<td>%s</td>' .
								'<td class="right">%s</td>' .
								'<td class="right">%s</td>' .
								'<td class="right">%s</td>' .
								($ds['average']      < 1000000 ? '<td class="right">%s</td>' : '<td class="right">%.2e</td>') .
								($ds['stddev']       < 1000000 ? '<td class="right">%s</td>' : '<td class="right">%.2e</td>') .
								($ds['variance_avg'] < 1000000 ? '<td class="right">%s</td>' : '<td class="right">%.2e</td>') .
								($ds['max_value']    < 1000000 ? '<td class="right">%s</td>' : '<td class="right">%.2e</td>') .
								($ds['min_value']    < 1000000 ? '<td class="right">%s</td>' : '<td class="right">%.2e</td>') .
								($ds['max_cutoff']   < 1000000 ? '<td class="right">%s</td>' : '<td class="right">%.2e</td>') .
								($ds['min_cutoff']   < 1000000 ? '<td class="right">%s</td>' : '<td class="right">%.2e</td>') .
								'<td class="right">%s</td>' .
								'<td class="right">%s</td>' .
								'<td class="right">%s</td>' .
								'<td class="right">%s</td>' .
								"</tr>\n\n",
								$this->displayTime($this->rra_pdp[$rra_key]),
								$this->ds_name[$dskey],
								$this->rra_cf[$rra_key],
								($ds['totalsamples']    != 'N/A' ? number_format_i18n($ds['totalsamples']) : '0'),
								($ds['numsamples']      != 'N/A' ? number_format_i18n($ds['numsamples'])   : '0'),
								($ds['average']         != 'N/A' ? round($ds['average'], 2)       : __('N/A')),
								($ds['stddev']          != 'N/A' ? round($ds['stddev'], 2)        : __('N/A')),
								($ds['variance_avg']    != 'N/A' ? round($ds['variance_avg'], 2)  : __('N/A')),
								($ds['max_value']       != 'N/A' ? round($ds['max_value'], 2)     : __('N/A')),
								($ds['min_value']       != 'N/A' ? round($ds['min_value'], 2)     : __('N/A')),
								($ds['max_cutoff']      != 'N/A' ? round($ds['max_cutoff'], 2)    : __('N/A')),
								($ds['min_cutoff']      != 'N/A' ? round($ds['min_cutoff'], 2)    : __('N/A')),
								($ds['stddev_killed']   != 'N/A' ? number_format_i18n($ds['stddev_killed'])   : __('N/A')),
								($ds['variance_killed'] != 'N/A' ? number_format_i18n($ds['variance_killed']) : __('N/A')),
								($ds['outwind_samples'] != 'N/A' ? number_format_i18n($ds['outwind_samples']) : __('N/A')),
								($ds['outwind_killed']  != 'N/A' ? number_format_i18n($ds['outwind_killed'])  : __('N/A')));
						}
					}
				}
			}
		}
	}

	private function updateXML(&$output, &$rra) {
		$rra_num   = 0;
		$ds_num    = 0;
		$last_num  = array();
		$new_array = array();

		if (cacti_sizeof($output)) {
			foreach($output as $line) {
				if (substr_count($line, '<v>')) {
					$linearray = explode('<v>', $line);

					/* get the timestamp */
					$timestamp_part = $linearray[0];
					if (strpos($timestamp_part, '<timestamp>') !== false) {
						$timestamp_part = str_replace('<row><timestamp>', '', $timestamp_part);
						$timestamp_part = str_replace('</timestamp>', '', $timestamp_part);
						$timestamp = trim($timestamp_part);
					} else {
						$timestamp = 0;
					}

					/* discard the first piece of the exploded line */
					array_shift($linearray);

					/* initialize variables */
					$ds_num         = 0;
					$out_row        = '<row>';
					$kills          = 0;

					foreach($linearray as $dsvalue) {
						/* peel off garbage */
						$dsvalue = trim(str_replace('</row>', '', str_replace('</v>', '', $dsvalue)));

						switch($this->method) {
							case SPIKE_METHOD_FLOAT:
								if ($timestamp >= $this->out_start && $timestamp <= $this->out_end) {
									if ($this->avgnan == 'avg') {
										$message = sprintf('Replacing dsvalue %s with average %s', $dsvalue, $rra[$rra_num][$ds_num]['variance_avg']);

										if ($this->debug) {
											cacti_log("DEBUG: $message", false, 'SPIKEKILL');
										}

										$this->debug($message);

										$dsvalue = sprintf('%1.10e', $rra[$rra_num][$ds_num]['variance_avg']);
										$kills++;
										$this->total_kills++;
									} elseif ($this->avgnan == 'last' && isset($rra[$rra_num][$ds_num]['last'])) {
										$message = sprintf('Replacing dsvalue %s with last value %s', $dsvalue, $rra[$rra_num][$ds_num]['last']);

										if ($this->debug) {
											cacti_log("DEBUG: $message", false, 'SPIKEKILL');
										}

										$this->debug($message);

										$dsvalue = $rra[$rra_num][$ds_num]['last'];
										$kills++;
										$this->total_kills++;
									}
								} elseif ($this->debug) {
									cacti_log("DEBUG: ignoring dsvalue {$dsvalue} as we are outside of the time range!", false, 'SPIKEKILL');
								}

								break;
							case SPIKE_METHOD_FILL:
								if ($timestamp >= $this->out_start && $timestamp <= $this->out_end) {
									if ($this->avgnan == 'avg') {
										if (!is_numeric($dsvalue) || $dsvalue == 0) {
											$message = sprintf('Replacing dsvalue %s with average %s', $dsvalue, $rra[$rra_num][$ds_num]['variance_avg']);

											if ($this->debug) {
												cacti_log("DEBUG: $message", false, 'SPIKEKILL');
											}

											$this->debug($message);

											$dsvalue = sprintf('%1.10e', $rra[$rra_num][$ds_num]['variance_avg']);
											$kills++;
											$this->total_kills++;
										}
									} elseif ($this->avgnan == 'last' && isset($rra[$rra_num][$ds_num]['last'])) {
										if (!is_numeric($dsvalue) || $dsvalue == 0) {
											$message = sprintf('Replacing dsvalue %s with last value %s', $dsvalue, $rra[$rra_num][$ds_num]['last']);

											if ($this->debug) {
												cacti_log("DEBUG: $message", false, 'SPIKEKILL');
											}

											$this->debug($message);

											$dsvalue = $rra[$rra_num][$ds_num]['last'];
											$kills++;
											$this->total_kills++;
										}
									}
								} elseif ($this->debug) {
									cacti_log("DEBUG: ignoring dsvalue {$dsvalue} as we are outside of the time range!", false, 'SPIKEKILL');
								}

								break;
							case SPIKE_METHOD_VARIANCE:
								if ($this->out_start == 0 || ($timestamp >= $this->out_start && $timestamp <= $this->out_end)) {
									if ($dsvalue > (1 + $this->percent) * (float) $rra[$rra_num][$ds_num]['variance_avg']) {
										if ($kills < $this->numspike) {
											if ($this->avgnan == 'avg') {
												if ($this->debug) {
													cacti_log("DEBUG: replacing dsvalue {$dsvalue} with average {$rra[$rra_num][$ds_num]['variance_avg']}", false, 'SPIKEKILL');
												}

												$dsvalue = sprintf('%1.10e', $rra[$rra_num][$ds_num]['variance_avg']);
												$this->total_kills++;
												$kills++;
											} elseif ($this->avgnan == 'last' && isset($last_num[$ds_num])) {
												if ($this->debug) {
													cacti_log("DEBUG: replacing dsvalue {$dsvalue} with last value {$last_num[$ds_num]}", false, 'SPIKEKILL');
												}

												$dsvalue = $last_num[$ds_num];
												$this->total_kills++;
												$kills++;
											} elseif ($this->avgnan == 'nan') {
												if ($this->debug) {
													cacti_log("DEBUG: replacing dsvalue {$dsvalue} with NaN", false, 'SPIKEKILL');
												}

												$dsvalue = 'NaN';
											}
										}
									}
								} elseif (is_numeric($dsvalue) && $dsvalue != 0) {
									$last_num[$ds_num] = $dsvalue;
								}

								break;
							case SPIKE_METHOD_STDDEV:
								if ($this->out_start == 0 || ($timestamp >= $this->out_start && $timestamp <= $this->out_end)) {
									if (($dsvalue > $rra[$rra_num][$ds_num]['max_cutoff']) ||
										($dsvalue < $rra[$rra_num][$ds_num]['min_cutoff'])) {
										if ($kills < $this->numspike) {
											$rra[$rra_num][$ds_num]['outwind_killed']++;

											if ($this->avgnan == 'avg') {
												if ($this->debug) {
													cacti_log("DEBUG: replacing dsvalue {$dsvalue} with average {$rra[$rra_num][$ds_num]['average']}", false, 'SPIKEKILL');
												}

												$dsvalue = sprintf('%1.10e', $rra[$rra_num][$ds_num]['average']);

												$this->total_kills++;
												$kills++;
											} elseif ($this->avgnan == 'last' && isset($last_num[$ds_num])) {
												if ($this->debug) {
													cacti_log("DEBUG: replacing dsvalue {$dsvalue} with last value {$last_num[$ds_num]}", false, 'SPIKEKILL');
												}

												$dsvalue = $last_num[$ds_num];
												$this->total_kills++;
												$kills++;
											} elseif ($this->avgnan == 'nan') {
												if ($this->debug) {
													cacti_log("DEBUG: replacing dsvalue {$dsvalue} with NaN", false, 'SPIKEKILL');
												}

												$dsvalue = 'NaN';
											}
										}
									}
								} elseif (is_numeric($dsvalue) && $dsvalue != 0) {
									$last_num[$ds_num] = $dsvalue;
								}

								break;
						}

						$out_row .= '<v> ' . $dsvalue . '</v>';
						$ds_num++;
					}

					$out_row .= "</row>\n";

					$new_array[] = $out_row;
				} else {
					if (substr_count($line, '</rra>')) {
						$ds_minmax = array();
						$rra_num++;

						$kills    = 0;
						$last_num = array();
					} elseif (substr_count($line, '</database>')) {
						$ds_num++;

						$kills    = 0;
						$last_num = array();
					}

					$new_array[] = $line;
				}
			}
		}

		return $new_array;
	}

	private function removeComments(&$output) {
		$new_array = [];

		if (cacti_sizeof($output)) {
			foreach($output as $line) {
				$line = trim($line);
				if ($line == '') {
					continue;
				} else {
					/* is there a comment, remove it */
					$oline = $line;

					$comment_start = strpos($line, '<!--');
					if ($comment_start === false) {
						/* do nothing no line */
					} else {
						$comment_end = strpos($line, '-->');
						if ($comment_start == 0) {
							$line = trim(substr($line, $comment_end+3));
						} else {
							$line = trim(substr($line,0,$comment_start-1) . substr($line,$comment_end+3));
						}

						if (strpos($line, '<row>') !== false) {
							/* capture the timestamp */
							$stamp     = trim(substr($oline, $comment_start+4, $comment_end-4));
							$stamp     = explode('/', $stamp);
							$timestamp = trim($stamp[1]);
							$line = str_replace('<row><v>', "<row><timestamp> $timestamp </timestamp><v>", $line);
						}
					}

					if ($line != '') {
						$new_array[] = $line;
					}
				}
			}

			/* transfer the new array back to the original array */
			return $new_array;
		}
	}

	private function displayTime($pdp) {
		$total_time = $pdp * $this->step; // seconds

		if ($total_time < 60) {
			return $total_time . ' secs';
		} else {
			$total_time = $total_time / 60;

			if ($total_time < 60) {
				return $total_time . ' mins';
			} else {
				$total_time = $total_time / 60;

				if ($total_time < 24) {
					return $total_time . ' hours';
				} else {
					$total_time = $total_time / 24;

					return $total_time . ' days';
				}
			}
		}
	}

	private function debug($string) {
		if ($this->debug) {
			print 'DEBUG: ' . $string . "\n";
		}
	}

	private function processStandardDeviationCalculation($samples) {
		$my_samples = $samples;

		if ($this->out_start > 0) {
			$my_samples = [];

			foreach($samples as $timestamp => $value) {
				if (($timestamp < $this->out_start || $timestamp > $this->out_end) && is_numeric($value)) {
					$my_samples[] = $value;
				}
			}
		}

		return $this->calculateStandardDeviation($my_samples);
	}

	private function calculateStandardDeviation($items) {
		$sum         = 0;
		$total_items = 0;

		/* remove NaN entries from the data set */
		if (cacti_sizeof($items)) {
			foreach($items as $key => $value) {
				if (is_numeric($value)) {
					$total_items++;

					$sum += $value;
				} else {
					unset($items[$key]);
				}
			}
		}

		if ($total_items < 2) {
			return false;
		}

		$mean  = $sum / $total_items;
		$carry = 0.0;

		foreach ($items as $val) {
			$d = ((float) $val) - $mean;
			$carry += $d * $d;
		}

		return sqrt($carry / $total_items);
	}
}
