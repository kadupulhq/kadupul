#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

ini_set('output_buffering', 'Off');

require(__DIR__ . '/../include/cli_check.php');

require_once($config['base_path'] . '/lib/utility.php');

ini_set('max_execution_time', '0');
ini_set('memory_limit', '-1');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$php_binary = read_config_option('path_php_binary');

cacti_log('WARNING: Deprecated script push_out_hosts.php. Please use rebuild_poller_cache.php.', false, 'PUSHOUT');

if (in_array('-v', $parms) || in_array('-V', $parms) || in_array('--version', $parms)) {
	// exception for github tests
	print 'Kadupul Push out hosts/repopulate poller cache Tool, Version ' . get_cacti_cli_version() . ' ' . COPYRIGHT_YEARS . PHP_EOL;
} else {
	print 'WARNING: Deprecated script push_out_hosts.php. Please use rebuild_poller_cache.php.' . PHP_EOL;

	if (!is_string($php_binary) || trim($php_binary) === '') {
		cacti_log('ERROR: Rejected an empty PHP binary.', false, 'SYSTEM');

		exit(1);
	}

	if (strpos(trim($php_binary), '-') === 0) {
		cacti_log('ERROR: Rejected PHP binary starting with dash: ' . $php_binary, false, 'SYSTEM');

		exit(1);
	}

	$args = array_merge(array($config['base_path'] . '/cli/rebuild_poller_cache.php'), $parms);

	$command = cacti_escapeshellcmd($php_binary) . ' ' . implode(' ', array_map('cacti_escapeshellarg', $args));

	$exit_code = 0;

	passthru($command, $exit_code);

	exit($exit_code);
}
