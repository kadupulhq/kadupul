<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$mode = $argv[1];
$directory = $argv[2];
if (($argv[4] ?? '') === 'coverage') {
    define('PER_CS_REVIEW_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    if ($mode === 'csrf') {
        define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/cli/refresh_csrf.php');
        define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/cli/refresh_csrf.php');
    }
    require __DIR__ . '/rrd-process-coverage.php';
}
$config = array('base_path' => $directory, 'library_path' => $directory . '/lib', 'rra_path' => $directory, 'url_path' => '/kadupul/');
if ($mode === 'clog') {
    set_error_handler(static function ($severity, $message) {
        throw new RuntimeException($message);
    });
    $calls = array();
    function get_data_source_title($id)
    {
        $GLOBALS['calls'][] = $id;
        return 'Title ' . $id;
    }
    require $root . '/lib/clog_webapi.php';
    echo json_encode(array(clog_get_datasource_titles(array(7, 7, 8)), clog_get_datasource_titles(7), $calls));
    exit;
}

function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
if ($mode === 'csrf') {
    function get_cacti_cli_version()
    {
        return 'fixture-version';
    }
    define('COPYRIGHT_YEARS', '2026');
    $_SERVER['argv'] = array('refresh_csrf.php', $argv[3]);
    require $directory . '/cli/refresh_csrf.php';
    throw new RuntimeException('Metadata request reached secret rotation');
}

$_REQUEST = array('action' => '', 'filter' => $argv[3], 'rows' => 10, 'page' => 2, 'age' => 0, 'sort_column' => 'name', 'sort_direction' => 'ASC');
function get_request_var($key)
{
    return $_REQUEST[$key] ?? '';
}
function isset_request_var($key)
{
    return isset($_REQUEST[$key]);
}
function set_default_action() {}
function top_header()
{
    ob_start();
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function __x($context, $text)
{
    return $text;
}
function __esc($text)
{
    return htmlspecialchars($text, ENT_QUOTES);
}
function html_escape_request_var($key)
{
    return htmlspecialchars(get_request_var($key), ENT_QUOTES);
}
function validate_store_request_vars(...$args) {}
function html_start_box(...$args) {}
function html_end_box(...$args) {}
function db_qstr($value)
{
    return "'" . str_replace("'", "''", $value) . "'";
}
function db_fetch_cell(...$args)
{
    return 20;
}
function db_fetch_assoc(...$args)
{
    return array();
}
function get_order_string()
{
    return 'ORDER BY name';
}
class CactiSecureHeaders
{
    public static function getNonceAttribute()
    {
        return '';
    }
}
function html_nav_bar($url, ...$args)
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode(array('url' => $url));
    exit;
}
define('MAX_DISPLAY_PAGES', 20);
$item_rows = array();
chdir($directory);
require $root . '/rrdcleaner.php';
