<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require dirname(__DIR__) . '/Helpers/PhpSource.php';
$root = dirname(__DIR__, 2);
require $root . '/include/global_constants.php';
require $root . '/lib/headers_secure.php';
require $root . '/src/Platform/Infrastructure/Legacy/UtilityRows.php';
function get_request_var($name)
{
    return array('id' => 3, 'severity' => '-1', 'receiver' => '-1', 'filter' => '', 'rows' => '10', 'page' => 1, 'mib' => '', 'filename' => 'cacti.log')[$name] ?? '';
}
function get_nfilter_request_var($name)
{
    return get_request_var($name);
}
function get_filter_request_var($name)
{
    return get_request_var($name);
}
function isset_request_var($name)
{
    return ($GLOBALS['argv'][1] ?? '') === 'clog' && $name === 'purge';
}
function isempty_request_var($name)
{
    return get_request_var($name) === '';
}
function validate_store_request_vars(...$args) {}
function read_config_option($name)
{
    return 10;
}
function html_start_box(...$args)
{
    print '<table>';
}
function html_end_box()
{
    print '</table>';
}
function form_start(...$args) {}
function html_header(...$args) {}
function html_nav_bar(...$args)
{
    return '';
}
function html_escape_request_var($name)
{
    return htmlspecialchars((string) get_request_var($name), ENT_QUOTES);
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function __esc($text, ...$args)
{
    return htmlspecialchars(__($text, ...$args), ENT_QUOTES);
}
function __esc_x($context, $text, ...$args)
{
    return __esc($text, ...$args);
}
function html_escape($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function db_fetch_cell(...$args)
{
    return 0;
}
function db_fetch_assoc(...$args)
{
    return array();
}
function cacti_sizeof($rows)
{
    return count($rows);
}
function clog_admin()
{
    return true;
}
function clog_validate_filename(&$file, &$path, &$name, $check = false)
{
    $path = '/tmp';
    $name = $file;
    return true;
}
function kill_session_var($name) {}
function set_request_var($name, $value) {}
function load_current_session_value(...$args) {}
function set_page_refresh($value) {}
function general_header() {}
$item_rows = array();
$config = array('url_path' => '/');
switch ($argv[1] ?? 'manager') {
    case 'utilities':
        // eval has its own import scope; retain the controller's real dependency alias.
        eval('use Kadupul\\Platform\\Infrastructure\\Legacy\\UtilityRows;' . test_php_function_source(file_get_contents($root . '/utilities.php'), 'snmpagent_utilities_run_eventlog'));
        snmpagent_utilities_run_eventlog();
        break;
    case 'clog':
        eval(test_php_function_source(file_get_contents($root . '/lib/clog_webapi.php'), 'clog_view_logfile'));
        clog_view_logfile();
        break;
    default:
        eval(test_php_function_source(file_get_contents($root . '/managers.php'), 'manager_logs'));
        manager_logs(3, 'Notification log');
}
