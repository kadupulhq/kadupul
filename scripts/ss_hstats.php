#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

error_reporting(0);

if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../include/cli_check.php');

	array_shift($_SERVER['argv']);

	print call_user_func_array('ss_hstats', $_SERVER['argv']);
}

function ss_hstats($host_id = 0, $stat = '') {
	$allowed_columns = array(
		'polling_time'  => 'polling_time',
		'min_time'      => 'min_time',
		'max_time'      => 'max_time',
		'cur_time'      => 'cur_time',
		'avg_time'      => 'avg_time',
		'uptime'        => 'snmp_sysUpTimeInstance',
		'failed_polls'  => 'failed_polls',
		'availability'  => 'availability',
	);

	if (!isset($allowed_columns[$stat])) {
		return '0';
	}

	$column = $allowed_columns[$stat];

	if ($host_id > 0) {
		$value = db_fetch_cell_prepared("SELECT $column
			FROM host
			WHERE id = ?",
			array($host_id));

		return ($value == '' ? 'U' : $value);
	}

	return '0';
}
