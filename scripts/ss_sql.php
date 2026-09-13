#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

error_reporting(0);

if (!isset($called_by_script_server)) {
	include_once(dirname(__FILE__) . '/../include/cli_check.php');

	print call_user_func('ss_sql');
}

function ss_sql() {
	global $database_username;
	global $database_password;
	global $database_hostname;

	$args = array(
		'--host=' . $database_hostname,
		'--user=' . $database_username
	);

	if ($database_password != '') {
		$args[] = '--password=' . $database_password;
	}

	$result = cacti_exec_string('mysqladmin', $args);
	
	$result = preg_replace('/: /', ':', $result);
	$result = preg_replace('/  /', ' ', $result);
	$result = preg_replace('/Slow queries/', 'SlowQueries', $result);
	$result = preg_replace('/Open tables/', 'OpenTables', $result);
	$result = preg_replace('/Queries per second avg/', 'QPS', $result);
	$result = preg_replace('/Flush tables/', 'FlushTables', $result);

	return trim($result) ?: 'U';
}
