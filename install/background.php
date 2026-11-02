<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
error_reporting(E_ALL);

define('IN_CACTI_INSTALL', 1);

if (PHP_SAPI === 'cli' && str_starts_with($_SERVER['argv'][1] ?? '', 'v13:')) {
    define('KADUPUL_REDACT_DATABASE_LOGS', true);
    define('KADUPUL_THROW_DATABASE_ERRORS', true);
}

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

// The reviewed Symfony plan owns its token and connection-scoped execution
// lock. Do not enter the legacy timeout/kill registration for these jobs.
if (is_string($params[0]) && preg_match('/\Av13:([a-f0-9]{32})\z/D', $params[0], $installerMatch)) {
    require __DIR__ . '/installer-bootstrap.php';
    $installerState = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array('installer_v13_job'));
    $installerJob = json_decode((string) $installerState, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($installerJob) || !is_string($installerJob['actor'] ?? null)) {
        exit(1);
    }
    $kernel = require __DIR__ . '/../config/bootstrap.php';
    $kernel->boot();
    $installerBackend = $kernel->getContainer()->get('installer.native');
    exit($installerBackend->execute($installerMatch[1]) ? 0 : 1);
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

