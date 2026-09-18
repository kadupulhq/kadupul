<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/ClogProductionFunctions.php';

function clogTailLinesProgram(): string
{
    $program = <<<'CODE'
$_REQUEST = $input['request'];
define('FILTER_VALIDATE_IS_REGEX', 0x10000);
define('MAX_DISPLAY_PAGES', 5);
$_CACTI_REQUEST = array();
$config = array('url_path' => '/', 'base_path' => '/nonexistent');
$log_tail_lines = array(-1 => 'All Lines', 10 => '10', 500 => '500', 10000 => '10000');
$tailed = array();
function clog_admin() { return false; }
function validate_store_request_vars($filters) {
	foreach ($filters as $name => $filter) {
		$GLOBALS['_CACTI_REQUEST'][$name] = isset($_REQUEST[$name]) ? filter_var($_REQUEST[$name], FILTER_VALIDATE_INT) : $filter['default'];
	}
}
function get_request_var($name) { return $GLOBALS['_CACTI_REQUEST'][$name] ?? ''; }
function set_request_var($name, $value) { $GLOBALS['_CACTI_REQUEST'][$name] = $value; }
function get_nfilter_request_var($name) { return $_REQUEST[$name] ?? ''; }
function isset_request_var($name) { return isset($_REQUEST[$name]); }
function read_config_option($name) { return $name == 'max_display_rows' ? 1000 : ($name == 'num_rows_log' ? 500 : ''); }
function clog_validate_filename(&$file, &$path, &$name, $check = false) { return false; }
function tail_file($file, $lines, $type, $filter, &$page, &$total, $matches = true) { $GLOBALS['tailed'][] = $lines; return array(); }
function html_nav_bar() { return ''; }
function db_fetch_assoc() { return array(); }
function clog_get_regex_array() { return array('complete' => '~^$~'); }
function __($text, ...$args) { return $text; }
function kill_session_var() {}
function load_current_session_value() {}
function set_page_refresh() {}
function general_header() {}
function html_start_box() {}
function html_end_box() {}
function filter() {}
function bottom_footer() {}
CODE;

    return $program . clogProductionFunction('lib/clog_webapi.php', 'clog_limit_tail_lines')
        . clogProductionFunction('lib/clog_webapi.php', 'clog_view_logfile')
        . 'ob_start(); foreach ($input["requests"] as $request) { $_REQUEST = $request; $_CACTI_REQUEST = array(); clog_view_logfile(); } ob_end_clean();'
        . 'echo json_encode($tailed);';
}

test('the log viewer caps requested tail lines at the largest filter choice', function () {
    $tailed = clogRunProduction(clogTailLinesProgram(), array(
        'request'  => array(),
        'requests' => array(
            array('tail_lines' => '10001'),
            array('tail_lines' => '2147483647'),
        ),
    ));

    expect($tailed)->toBe(array(10000, 10000));
});

test('the log viewer keeps offered tail line choices and the All Lines setting', function () {
    $tailed = clogRunProduction(clogTailLinesProgram(), array(
        'request'  => array(),
        'requests' => array(
            array('tail_lines' => '10'),
            array('tail_lines' => '10000'),
            array('tail_lines' => '-1'),
            array(),
        ),
    ));

    expect($tailed)->toBe(array(10, 10000, 1000, 500));
});

// utilities.php renders its own log view; stop at tail_file() once the
// requested line count is known.
function utilitiesTailLinesProgram(): string
{
    $program = <<<'CODE'
define('FILTER_VALIDATE_IS_REGEX', 0x10000);
$_CACTI_REQUEST = array();
$config = array('url_path' => '/');
$log_tail_lines = array(-1 => 'All Lines', 10 => '10', 500 => '500', 10000 => '10000');
$page_refresh_interval = array();
$tailed = array();
class CactiSecureHeaders { public static function getNonceAttribute() { return ''; } }
function validate_store_request_vars($filters) {
	foreach ($filters as $name => $filter) {
		$GLOBALS['_CACTI_REQUEST'][$name] = isset($_REQUEST[$name]) ? filter_var($_REQUEST[$name], FILTER_VALIDATE_INT) : $filter['default'];
	}
}
function get_request_var($name) { return $GLOBALS['_CACTI_REQUEST'][$name] ?? ''; }
function set_request_var($name, $value) { $GLOBALS['_CACTI_REQUEST'][$name] = $value; }
function get_nfilter_request_var($name) { return $_REQUEST[$name] ?? ''; }
function read_config_option($name) { return $name == 'max_display_rows' ? 1000 : ($name == 'path_cactilog' ? '/var/log/cacti.log' : ''); }
function clog_validate_filename(&$file, &$path, &$name, $check = false) { $path = '/var/log'; $name = 'cacti.log'; return true; }
function clog_get_logfiles() { return array('cacti.log'); }
function tail_file($file, $lines, $type, $filter, &$page, &$total, $matches = true) { $GLOBALS['tailed'][] = $lines; throw new LogicException('stop'); }
function __($text, ...$args) { return $text; }
function __esc($text, ...$args) { return $text; }
function __esc_x($context, $text) { return $text; }
function html_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function html_escape_request_var($name) { return html_escape(get_request_var($name)); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return is_array($value) ? count($value) : 0; }
function set_page_refresh() {}
function top_header() {}
function html_start_box() {}
function html_end_box() {}
CODE;

    return $program . clogProductionFunction('lib/clog_webapi.php', 'clog_limit_tail_lines')
        . clogProductionFunction('utilities.php', 'utilities_view_logfile')
        . 'ob_start(); foreach ($input["requests"] as $request) { $_REQUEST = $request; $_CACTI_REQUEST = array(); try { utilities_view_logfile(); } catch (LogicException $e) {} } ob_end_clean();'
        . 'echo json_encode($tailed);';
}

test('the utilities log view caps requested tail lines at the largest filter choice', function () {
    $tailed = clogRunProduction(utilitiesTailLinesProgram(), array(
        'requests' => array(
            array('tail_lines' => '10001'),
            array('tail_lines' => '2147483647'),
            array('tail_lines' => '500'),
            array('tail_lines' => '-1'),
        ),
    ));

    expect($tailed)->toBe(array(10000, 10000, 500, 1000));
});
