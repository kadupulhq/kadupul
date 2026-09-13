<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
error_reporting(E_ALL);

define('IN_CACTI_INSTALL', 1);

include_once(dirname(__FILE__) . '/../include/cli_check.php');
include_once($config['base_path'] . '/install/functions.php');
include_once($config['base_path'] . '/lib/api_data_source.php');
include_once($config['base_path'] . '/lib/api_device.php');
include_once($config['base_path'] . '/lib/api_automation.php');
include_once($config['base_path'] . '/lib/api_automation_tools.php');
include_once($config['base_path'] . '/lib/data_query.php');
include_once($config['base_path'] . '/lib/import.php');
include_once($config['base_path'] . '/lib/installer.php');
include_once($config['base_path'] . '/lib/poller.php');
include_once($config['base_path'] . '/lib/snmp.php');
include_once($config['base_path'] . '/lib/utility.php');

cacti_log('Checking arguments', false, 'INSTALL:');
/* process calling arguments */
$params = $_SERVER['argv'];
array_shift($params);

global $cli_install;

$cli_install = true;
$now = time();

if (cacti_sizeof($params) == 0) {
	log_install_always('','no parameters passed' . PHP_EOL);
	exit(0);
}

if (function_exists('register_process_start')) {
	if (!register_process_start('install', 'master', '0', 600)) {
		exit(0);
	}
} else {
	$running = read_config_option('installer_running', true);

	if ($running != '' && $now - $running < 600) {
		exit(0);
	}

	set_config_option('installer_running', $now);
}

Installer::beginInstall($params[0]);

if (function_exists('register_process_start')) {
	unregister_process('install', 'master', 0);
} else {
	set_config_option('installer_running', '');
}

