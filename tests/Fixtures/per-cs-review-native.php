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
    $result = array(clog_get_datasource_titles(array(7, 7, 8)), clog_get_datasource_titles(7), $calls);
    define('NATIVE_COVERAGE_COMPLETED', array('clog-production-observed'));
    echo json_encode($result);
    exit;
}

function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
if ($mode === 'csrf') {
    function get_cacti_cli_version()
    {
        define('NATIVE_COVERAGE_COMPLETED', array('csrf-production-observed'));
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
    if (isset($GLOBALS['scanDb'])) return $GLOBALS['scanDb']->quote($value);
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
function read_config_option($key, ...$arguments)
{
    return $key === 'storage_location' && $GLOBALS['mode'] === 'scan-proxy' ? 1 : '';
}
function rrd_init(...$arguments)
{
    return 'scan-pipe';
}
function rrd_close(...$arguments) {}
function rrdtool_execute($command, ...$arguments)
{
    return $command === 'rrd-list' ? $GLOBALS['scanResponse'] : true;
}
function db_execute($sql)
{
    if (str_contains($sql, 'SELECT local_data_id')) return true;
    $GLOBALS['scanBatches'][] = substr_count($sql, ',0)');
    $sql = str_replace(
        'ON DUPLICATE KEY UPDATE size=VALUES(size), last_mod=VALUES(last_mod)',
        'ON CONFLICT(name) DO UPDATE SET size=excluded.size, last_mod=excluded.last_mod',
        $sql
    );
    return $GLOBALS['scanDb']->exec($sql) !== false;
}
function html_nav_bar($url, ...$args)
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (str_starts_with($GLOBALS['mode'], 'scan-')) {
        $GLOBALS['scanDb'] = new PDO('sqlite::memory:');
        $GLOBALS['scanDb']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $GLOBALS['scanDb']->exec('CREATE TABLE data_source_purge_temp (name TEXT PRIMARY KEY, size INTEGER, last_mod TEXT, in_cacti INTEGER)');
        $GLOBALS['scanBatches'] = [];
        $rows = json_decode($GLOBALS['argv'][3], true, flags: JSON_THROW_ON_ERROR);
        $GLOBALS['scanResponse'] = implode("\r\n", array_map(fn($row) => $GLOBALS['rra_path'] . $row[0] . ',' . $row[1] . ',' . $row[2], $rows));
        if ($GLOBALS['mode'] === 'scan-local') {
            foreach ($rows as $row) {
                file_put_contents($GLOBALS['config']['rra_path'] . '/' . $row[0], str_repeat('x', $row[1]));
                touch($GLOBALS['config']['rra_path'] . '/' . $row[0], $row[2]);
            }
        }
        get_files();
        define('NATIVE_COVERAGE_COMPLETED', [$GLOBALS['mode'] . '-production-observed']);
        echo json_encode(['rows' => $GLOBALS['scanDb']->query('SELECT name,size,last_mod,in_cacti FROM data_source_purge_temp ORDER BY name')->fetchAll(PDO::FETCH_ASSOC), 'batches' => $GLOBALS['scanBatches']]);
    } else {
        define('NATIVE_COVERAGE_COMPLETED', array('cleaner-production-observed'));
        echo json_encode(array('url' => $url));
    }
    exit;
}
define('RRDTOOL_OUTPUT_NULL', 0);
define('RRDTOOL_OUTPUT_STDOUT', 1);
define('MAX_DISPLAY_PAGES', 20);
$item_rows = array();
chdir($directory);
require $root . '/rrdcleaner.php';
