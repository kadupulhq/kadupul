<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if ($_SERVER['REQUEST_URI'] === '/health') {
    echo 'READY';
    return;
}
$root = getenv('HTTPS_NATIVE_ROOT');
$directory = getenv('HTTPS_NATIVE_DIRECTORY');
$scenario = json_decode(getenv('HTTPS_NATIVE_SCENARIO'), true, 512, JSON_THROW_ON_ERROR);
foreach (array('HTTPS', 'REQUEST_URI', 'SERVER_NAME', 'HTTP_HOST') as $key) {
    unset($_SERVER[$key]);
}
$_SERVER = array_merge($_SERVER, $scenario['server']);
$_SERVER['SCRIPT_NAME'] = '/host.php';
define('IN_CACTI_INSTALL', true);
$_SESSION = array('sess_config_array' => array());
if (getenv('HTTPS_NATIVE_COVERAGE') === '1') {
    define('FORCE_HTTPS_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/include/global.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/include/global.php');
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
require $directory . '/include/global.php';
