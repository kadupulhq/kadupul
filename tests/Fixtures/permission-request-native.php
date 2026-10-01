<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Bootstrap/plugins are isolated; request and session validation execute natively.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
mkdir($directory . '/include', 0700, true);
file_put_contents($directory . '/include/auth.php', '<?php');
chdir($directory);
$page = $scenario['group'] ? 'user_group_admin.php' : 'user_admin.php';
$_SERVER['SCRIPT_NAME'] = $page;
$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $page;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = $scenario['session'];
$_REQUEST = array('action' => 'fixture') + $scenario['request'];
$_GET = $_REQUEST;
$_POST = array();
$config = array('is_web' => false, 'config_options_array' => array('num_rows_table' => 23, 'log_validation' => 'off'));
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function api_plugin_hook_function($hook, $value)
{
    return true;
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/variables.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('PERMISSION_REQUEST_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/' . $page;
$function = 'process_' . $scenario['kind'] . '_request_vars';
ob_start();
$error = null;
if (!empty($scenario['reject'])) {
    require_once $root . '/src/IdentityAccess/Infrastructure/Legacy/PermissionRequests.php';
    try {
        \Kadupul\IdentityAccess\Infrastructure\Legacy\PermissionRequests::process($scenario['group'], $scenario['kind']);
    } catch (InvalidArgumentException $exception) {
        $error = get_class($exception);
    }
} else {
    $function();
}
$output = ob_get_clean();
print json_encode(array('request' => $_REQUEST, 'session' => $_SESSION, 'output' => $output, 'error' => $error), JSON_THROW_ON_ERROR);
