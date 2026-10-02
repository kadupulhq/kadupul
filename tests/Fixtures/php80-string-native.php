<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = dirname(__DIR__, 2);
$directory = $argv[1];
if (isset($argv[2])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('THEME_SELECTION_TEST_COVERAGE', 1);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';

// Isolate external plugin availability and the native syslog destination.
// The CLI disables these three native functions before defining the boundary.
function api_plugin_is_enabled($name)
{
    return true;
}
if (!function_exists('syslog')) {
    function openlog($ident, $flags, $facility)
    {
        return true;
    }
    function closelog()
    {
        return true;
    }
    function syslog($priority, $message)
    {
        $GLOBALS['native_syslog'][] = [$priority, $message];
        return true;
    }
} else {
    throw new RuntimeException('Native syslog must be disabled for the isolated fixture');
}

$config = [
    'is_web' => false, 'poller_id' => 2, 'base_path' => $root,
    'cacti_server_os' => 'unix',
    'config_options_array' => [
        'client_timezone_support' => '', 'selective_debug' => '',
        'selective_plugin_debug' => 'thold', 'log_destination' => 1,
        'log_verbosity' => POLLER_VERBOSITY_DEBUG,
        'path_cactilog' => $directory . '/validation.log',
        'log_validation' => 'on', 'default_date_format' => 0,
        'default_datechar' => 0, 'log_perror' => 'on',
        'log_pwarn' => 'on', 'log_pstats' => 'on',
    ],
];
$_SESSION = [];
$no_http_headers = true;
$messages = [3 => ['message' => 'Invalid value', 'level' => MESSAGE_LEVEL_ERROR]];
$_SERVER['PHP_SELF'] = '/plugins/thold/view.php';
$native_syslog = [];
$result = [];

$result['remote_paths'] = [is_remote_path_setting('path_rrdtool'), is_remote_path_setting('rra_path'), is_remote_path_setting('poller_interval')];
$result['selective_level'] = get_selective_log_level();
$result['messages'] = [];
foreach ([0, '1', 2, '3', 4, 99] as $level) {
    $result['messages'][] = get_format_message_instance(['message' => 'body', 'level' => $level]);
}
$result['dates'] = [];
foreach ([0, '1', 2, '3', 4, '5', 99] as $format) {
    $config['config_options_array']['default_date_format'] = $format;
    $result['dates'][] = date_time_format();
}
$result['log_filters'] = [];
foreach ([
    [1, 'STATS'], [2, 'WARN'], [3, ' SQL query'], [3, 'plain'],
    [4, 'ERROR'], [5, ' SQL query'], [5, 'plain'], [6, 'DEBUG message'],
    [6, 'DEBUG SQL query'], [7, ' SQL query'], [8, 'AUTOM8'],
    [9, 'plain'], [9, 'STATS'], [10, 'BOOST'], [11, '] is down!'],
    [11, 'plain'], [12, 'Recache Event'], [12, 'plain'], [99, 'THOLD: Threshold'],
] as [$type, $line]) {
    $result['log_filters'][] = determine_display_log_entry($type, $line, '');
}
$result['addresses'] = [is_ipaddress('fe80::1%eth0'), is_ipaddress('invalid%eth0'), cacti_format_ipv6_colon('::1'), cacti_format_ipv6_colon('invalid')];
$result['sensitive'] = [cacti_is_sensitive_key('prefix_PASSWORD_suffix'), cacti_is_sensitive_key('ordinary')];
$hex = 'Hex- AB CD';
$result['hex'] = [is_hex_string($hex), $hex];
$result['email'] = [split_emaildetail('local-user'), split_emaildetail('person@example.test'), split_emaildetail('Name <person@example.test>')];
$result['tables'] = in_array('graph_local', get_cacti_base_tables(), true);
$result['urls'] = [appendHeaderSuppression('?action=edit'), appendHeaderSuppression('graph.php'), appendHeaderSuppression('graph.php?header=false')];

form_input_validate('valid', 'good', '^valid$', false);
form_input_validate('invalid', 'bad', '^valid$', false);
@form_input_validate('value', 'malformed', '[', false);
ini_set('pcre.backtrack_limit', '10');
@form_input_validate(str_repeat('a', 40) . '!', 'backtrack', '^(a+)+$', false);
$result['validation_fields'] = array_keys($_SESSION['sess_error_fields']);
$result['validation_log'] = file_get_contents($directory . '/validation.log');
$config['config_options_array']['log_destination'] = 3;
foreach (['ERROR: failure', 'WARNING: warning', 'STATS: stats', 'NOTICE: notice'] as $line) {
    cacti_log($line, false, 'NATIVE');
}
$result['syslog'] = $native_syslog;
file_put_contents($directory . '/result.json', json_encode($result, JSON_THROW_ON_ERROR));
