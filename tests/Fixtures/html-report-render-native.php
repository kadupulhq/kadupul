<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Bootstrap and permissions are isolated; rendering and SQL run in production files.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (isset($argv[3])) {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/html-report-render-native.php', $argv[1], array('lib/html.php', 'lib/reports.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'));
}
$config = ['base_path' => $root, 'url_path' => '/kadupul/'];
$alignment = [0 => 'left', 1 => 'center', 2 => 'right'];
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE host (id INTEGER, description TEXT, location TEXT)');
$db->exec('CREATE TABLE sites (id INTEGER, name TEXT)');
$db->prepare('INSERT INTO host VALUES (?, ?, ?)')->execute([100, 'Router <one> & "two"', 'Rack <west> & "A"']);
$db->exec("INSERT INTO host VALUES (101, 'Other', ''), (102, 'Duplicate location', 'Rack <west> & \"A\"')");
$db->prepare('INSERT INTO sites VALUES (?, ?)')->execute([4, 'Site <west> & "A"']);
$db->exec('CREATE TABLE graph_local (id INTEGER, host_id INTEGER, graph_template_id INTEGER)');
$db->exec('INSERT INTO graph_local VALUES (200, 100, 6)');
$db->exec('CREATE TABLE reports (id INTEGER, user_id INTEGER, name TEXT, cformat TEXT, format_file TEXT, alignment INTEGER, font_size INTEGER, graph_columns INTEGER)');
$db->exec('CREATE TABLE reports_items (id INTEGER, report_id INTEGER, sequence INTEGER, item_type INTEGER, local_graph_id INTEGER, item_text TEXT, align INTEGER, font_size INTEGER)');
$db->prepare('INSERT INTO reports VALUES (7, 42, ?, ?, ?, 0, 12, 1)')->execute(['Weekly <network> & "status"', '', '']);
$db->prepare('INSERT INTO reports_items VALUES (70, 7, 2, 2, 0, ?, 0, 10)')->execute(['Second <script>alert(1)</script> & "text"']);
$db->prepare('INSERT INTO reports_items VALUES (71, 7, 1, 2, 0, ?, 2, 11)')->execute(['First <strong>literal</strong>']);
$db->exec("INSERT INTO reports_items VALUES (80, 8, 1, 2, 0, 'Foreign text', 0, 10)");
function db_fetch_assoc_prepared($sql, $params = [])
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_row_prepared($sql, $params = [])
{
    return db_fetch_assoc_prepared($sql, $params)[0] ?? [];
}
function db_fetch_cell_prepared($sql, $params = [])
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchColumn();
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function get_allowed_devices($where)
{
    return db_fetch_assoc('SELECT id, description FROM host WHERE id = 100 ' . $where);
}
function get_allowed_sites($where)
{
    return db_fetch_assoc('SELECT id, name FROM sites ' . $where);
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function get_selected_theme()
{
    return $GLOBALS['scenario']['theme'] ?? 'classic';
}
function read_config_option($name)
{
    return $name === 'autocomplete_enabled' ? 'on' : 0;
}
function read_user_setting($name)
{
    return 0;
}
function isset_request_var($name)
{
    return false;
}
function strip_domain($text)
{
    return $text;
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function __esc($text)
{
    return html_escape($text);
}
function get_current_page()
{
    return 'reports_admin.php';
}
function cacti_log(...$args) {}
function aggregate_build_children_url($id)
{
    return '';
}
function is_realm_allowed($realm)
{
    return in_array($realm, $GLOBALS['scenario']['realms'] ?? [], true);
}
function api_plugin_hook($name, $args)
{
    $GLOBALS['hook'] = [$name, $args];
}
require $root . '/include/global_constants.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
    define('HTML_REPORT_RENDER_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/html.php';
require $root . '/lib/reports.php';
ob_start();
switch ($scenario['operation']) {
    case 'filters':
        html_host_filter(100, 'refresh');
        html_site_filter(4, 'refresh', '', $scenario['noany'] ?? false, $scenario['nonone'] ?? false);
        html_location_filter('Rack <west> & "A"', 'refresh');
        break;
    case 'icons':
        graph_drilldown_icons(200, 'graph_buttons', 3, 9);
        break;
    case 'header':
        html_header_checkbox([['display' => 'Name <literal>', 'tip' => 'Tip "quoted"']], true);
        break;
    case 'report':
        $theme = '';
        print reports_generate_html(7, $scenario['email'] ? REPORTS_OUTPUT_EMAIL : REPORTS_OUTPUT_STDOUT, $theme);
        break;
    default:
        throw new InvalidArgumentException('Unknown render operation.');
}
$html = ob_get_clean();
$nativeChildCoverageMarkers = array('native-render-operation-returned', 'buffered-html-observed');
print json_encode(['html' => $html, 'custom' => $_SESSION['custom'] ?? null, 'hook' => $hook ?? null], JSON_THROW_ON_ERROR);
