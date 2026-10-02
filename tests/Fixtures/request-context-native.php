<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$root = dirname(__DIR__, 2);
$directory = $argv[1];
$autoload = $argv[2] === 'autoload';
if (isset($argv[3])) {
    define('REQUEST_CONTEXT_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
$loaders = spl_autoload_functions();
if ($autoload) {
    require $root . '/include/vendor/autoload.php';
    require $root . '/src/Platform/Infrastructure/Legacy/LegacyRequestContext.php';
} else {
    // Preserve real early-bootstrap class availability while retaining the
    // coverage driver's loader for serialization after the measured calls.
    foreach ($loaders as $loader) {
        spl_autoload_unregister($loader);
    }
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
$config = array('is_web' => false, 'base_path' => $directory, 'config_options_array' => array('selective_debug' => '', 'client_timezone_support' => '0', 'log_destination' => '1', 'log_verbosity' => (string) POLLER_VERBOSITY_LOW, 'path_cactilog' => $directory . '/cacti.log'));
$_SESSION = array();
$result = array();
foreach (array(
    array('SCRIPT_NAME' => '/admin/index.php', 'SCRIPT_FILENAME' => '/srv/fallback.php', 'REQUEST_URI' => '/raw.php?a=1&b=2'),
    array('SCRIPT_NAME' => '', 'SCRIPT_FILENAME' => '/srv/fallback.php', 'QUERY_STRING' => 'x=1&y=2'),
    array('SCRIPT_NAME' => '/index.php'),
    array(),
    array('SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => "/index.php?x=<script>bad</script>\r\n")
) as $server) {
    $_SERVER = $server;
    $result[] = array('page' => get_current_page(), 'full_page' => get_current_page(false), 'uri' => get_browser_query_string());
}
if (!$autoload) {
    foreach ($loaders as $loader) {
        spl_autoload_register($loader);
    }
}
echo json_encode(array('results' => $result, 'log' => file_get_contents($directory . '/cacti.log')), JSON_THROW_ON_ERROR);
