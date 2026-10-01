<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 2);
if (isset($argv[2])) {
    define('DATA_INPUT_INDEX_UPGRADE_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
    require __DIR__ . '/rrd-process-coverage.php';
}
$config = ['base_path' => $root, 'is_web' => false, 'url_path' => '/', 'cacti_server_os' => 'unix', 'poller_id' => 1, 'connection' => 'local'];
function __($text, ...$arguments)
{
    return $arguments ? vsprintf($text, $arguments) : $text;
}
function __x($context, $text, ...$arguments)
{
    return __($text, ...$arguments);
}
function cacti_version_compare($old, $new, $operator)
{
    return version_compare($old, $new, $operator);
}
function get_rrdtool_version()
{
    return '1.8';
}
function get_auth_realms()
{
    return [];
}
function read_config_option(...$arguments)
{
    return '';
}
function api_plugin_hook(...$arguments) {}
function db_index_exists($table, $name)
{
    if ($table !== 'data_template_rrd' || $name !== 'data_input_field_id') {
        throw new RuntimeException('Unexpected index lookup.');
    }
    return $GLOBALS['argv'][1] === 'present';
}
function db_install_execute($sql)
{
    $GLOBALS['statements'][] = $sql;
}
$statements = [];
require $root . '/include/global_constants.php';
require $root . '/include/global_arrays.php';
$target = trim(file_get_contents($root . '/include/cacti_version'));
if (!version_compare($target, '1.2.33', '>=') || !array_key_exists('1.2.33', $cacti_version_codes) || !array_key_exists('1.2.32', $cacti_version_codes)) {
    throw new RuntimeException('Canonical metadata does not register the index migration after 1.2.31.');
}
require $root . '/install/upgrades/1_2_33.php';
upgrade_to_1_2_33();
echo json_encode($statements, JSON_THROW_ON_ERROR);
