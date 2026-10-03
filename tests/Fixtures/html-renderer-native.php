<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Actual HTML/functions/SQL; translation, plugin callbacks and permission
// visibility are isolated boundaries. This is not HTTP authorization coverage.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$case = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (isset($argv[2])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[2]);
    define('HTML_RENDERER_NATIVE_TEST_COVERAGE', true);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/' . ($case['page'] ?? 'native.php');
$_SERVER['REQUEST_URI'] = $_SERVER['PHP_SELF'];
$_REQUEST = $case['request'] ?? array();
$_SESSION = array('sess_user_id' => 9, 'selected_theme' => $case['theme'] ?? 'classic', 'sess_user_config_array' => array('num_columns' => 2, 'page_refresh' => 60, 'custom_fonts' => '', 'show_graph_title' => 'on', 'default_width' => 120, 'default_height' => 40, 'realtime_mode' => 1));
$config = array('base_path' => $root, 'url_path' => '/native/', 'poller_id' => 1, 'is_web' => false, 'config_options_array' => array('title_size' => 10, 'realtime_enabled' => 'on', 'autocomplete_enabled' => 'on', 'auth_method' => 1, 'selected_theme' => 'classic'));
$themes = array('classic' => 'Classic', 'modern' => 'Modern', 'midwinter' => 'Midwinter');
$tabs_left = array();
$menu = array('Console' => array('native.php' => 'Native Device'));
$menu_glyphs = array('Console' => 'fa fa-cog');
$user_auth_realm_filenames = array('native.php' => 8);
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE host(id INTEGER, description TEXT, disabled TEXT, location TEXT);
INSERT INTO host VALUES(1,'Alpha & Beta','','Rack & West'),(2,'Zeta','on','East'),(3,'Empty','','');
CREATE TABLE graph_local(id INTEGER, host_id INTEGER, graph_template_id INTEGER);
INSERT INTO graph_local VALUES(11,1,21),(12,2,22);
CREATE TABLE sites(id INTEGER, name TEXT);
INSERT INTO sites VALUES(1,'Site & One'),(2,'Second');
CREATE TABLE external_links(id INTEGER, title TEXT, style TEXT, enabled TEXT, sortorder INTEGER);
INSERT INTO external_links VALUES(1,'Operator & Tools','TAB','on',1),(2,'Disabled','TAB','',2);");
$queries = $hooks = array();
function db_fetch_assoc_prepared($sql, $params = array())
{
    $GLOBALS['queries'][] = array($sql, $params);
    $query = $GLOBALS['db']->prepare($sql);
    $query->execute($params);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_row_prepared($sql, $params = array())
{
    return db_fetch_assoc_prepared($sql, $params)[0] ?? array();
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $row = db_fetch_row_prepared($sql, $params);
    return $row ? reset($row) : false;
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function __($text, ...$args)
{
    $text = $GLOBALS['case']['translations'][$text] ?? $text;
    return $args ? vsprintf($text, $args) : $text;
}
function __esc($text, ...$args)
{
    return html_escape(__($text, ...$args));
}
function is_realm_allowed($realm)
{
    return in_array($realm, $GLOBALS['case']['realms'] ?? array(), true);
}
function api_user_realm_auth($page)
{
    // Explicit permission boundary: no installed authorization claim.
    return false;
}
function get_allowed_devices($where)
{
    return db_fetch_assoc('SELECT * FROM host ' . $where . ' ORDER BY description');
}
function get_allowed_sites($where)
{
    return db_fetch_assoc('SELECT * FROM sites ' . $where . ' ORDER BY name');
}
function aggregate_build_children_url($id)
{
    return '';
}
function api_plugin_hook_function($hook, $value)
{
    return $value;
}
function api_plugin_hook($hook, $arguments = array())
{
    $GLOBALS['hooks'][] = array($hook, $arguments);
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/headers_secure.php';
require $root . '/lib/html.php';
ob_start();
switch ($case['kind']) {
    case 'host': html_host_filter($case['selected'] ?? '-1', 'reloadDevices', $case['where'] ?? '', $case['noany'] ?? false, $case['nonone'] ?? false);
        break;
    case 'site': html_site_filter($case['selected'] ?? '-1', 'reloadSites()', $case['where'] ?? '', $case['noany'] ?? false, $case['nonone'] ?? false);
        break;
    case 'location': html_location_filter($case['selected'] ?? '', 'reloadLocations', $case['where'] ?? '', $case['noany'] ?? false, $case['nonone'] ?? false);
        break;
    case 'box': html_start_box('Inventory', '100%', true, 0, 'left', $case['add_text'] ?? array(array('id' => 'add-item', 'href' => 'native.php?action=add', 'title' => 'Add Item', 'callback' => true, 'class' => 'fa fa-plus')), $case['add_label'] ?? false);
        html_end_box(false, true);
        break;
    case 'checkbox': html_header_checkbox(array(array('display' => 'Name & Value', 'align' => 'left'), 'State'), true);
        break;
    case 'tabs': html_show_tabs_left();
        break;
    case 'menu': draw_menu();
        break;
    case 'sort':
        $_REQUEST += array('sort_column' => 'name', 'sort_direction' => 'ASC');
        html_header_sort_checkbox(array('name' => array('display' => 'Name', 'align' => 'left'), 'state' => array('display' => 'State')), 'name', 'ASC', true);
        break;
    case 'drilldown': graph_drilldown_icons(11, 'native_graph_buttons', 7, 8);
        break;
    case 'graph':
    case 'thumbnail':
        $graphs = ($case['empty'] ?? false) ? array() : array(array('local_graph_id' => 11, 'title_cache' => 'Alpha & Beta', 'width' => 300, 'height' => 80), array('local_graph_id' => 12, 'title_cache' => 'Zeta', 'width' => 400, 'height' => 90));
        if ($case['missing'] ?? false) {
            $graphs[] = array('local_graph_id' => 99, 'title_cache' => 'Missing', 'width' => 1, 'height' => 1);
        }
        print '<table>';
        if ($case['kind'] === 'graph') {
            html_graph_area($graphs, 'No Graphs', '', '', $case['columns'] ?? 2);
        } else {
            html_graph_thumbnail_area($graphs, 'No Graphs', '', '', $case['columns'] ?? 2);
        }
        print '</table>';
        break;
    default: throw new RuntimeException('Unknown native renderer case');
}
$html = ob_get_clean();
define('NATIVE_COVERAGE_COMPLETED', array('html-rendered:' . $case['kind']));
fwrite(STDOUT, json_encode(array('html' => $html, 'queries' => $queries, 'hooks' => $hooks), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
