<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = $argv[1];
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/tests/Helpers/PhpStatementSource.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$_SERVER = array('SERVER_NAME' => 'unit.test', 'HTTP_HOST' => 'untrusted.invalid', 'SERVER_PORT' => '80');
$_SESSION = $input['session'] ?? array();
$request = isset($input['source_action']) ? array('action' => $input['source_action']) : array();
$phase = 'redirect';
$source_reads = 0;

function get_nfilter_request_var($name, $default = '')
{
    global $request, $phase, $source_reads;
    if ($name !== 'action') throw new RuntimeException('Unexpected request field');
    if ($phase === 'redirect') $source_reads++;
    return $request[$name] ?? $default;
}

function isset_request_var($name)
{
    global $request;
    return isset($request[$name]);
}

function set_request_var($name, $value)
{
    global $request;
    $request[$name] = $value;
}

function read_user_setting($name, $default = false, $force = false, $user = 0)
{
    global $input;
    if ($name !== 'default_view_mode') throw new RuntimeException('Unexpected user setting');
    return $input['view_mode'] ?? '1';
}

function __($text, ...$arguments)
{
    return $arguments ? vsprintf($text, $arguments) : $text;
}
function __esc($text, ...$arguments)
{
    return html_escape(__($text, ...$arguments));
}
function form_end($ajax = true) {}
function read_config_option($name, $force = false)
{
    return $name === 'auth_method' ? 1 : '';
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array())
{
    return '';
}
function get_selected_theme()
{
    return 'fixture';
}
function display_output_messages($refresh = false)
{
    return "''";
}
function csrf_get_tokens()
{
    return 'fixture-only-token';
}
class CactiSecureHeaders
{
    public static function getNonceAttribute(): string
    {
        return '';
    }
    public static function getNonce(): string
    {
        return 'fixture-only-nonce';
    }
}

function is_view_allowed($name)
{
    return in_array($name, array('show_tree', 'show_list', 'show_preview'), true);
}

foreach (array('lib/functions.php' => array('sanitize_uri', 'is_urlencoded', 'appendHeaderSuppression'),
    'lib/html_utility.php' => array('validate_redirect_url'), 'lib/html_graph.php' => array('set_default_graph_action'),
    'lib/html_form.php' => array('form_save_button'), 'lib/html.php' => array('html_escape')) as $file => $functions) {
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) throw new RuntimeException('Cannot read redirect implementation');
    if ($file === 'lib/html.php' && strpos($source, 'function html_escape_charset(') !== false) {
        eval(test_php_function_source($source, 'html_escape_charset'));
    }
    foreach ($functions as $function) eval(test_php_function_source($source, $function));
}

$mode = $input['mode'] ?? 'redirect';
$extra = array();
if ($mode === 'referer') {
    $_SERVER['HTTP_REFERER'] = $input['url'];
    $source = file_get_contents($root . '/include/global.php');
    if ($source === false) throw new RuntimeException('Cannot read the bootstrap referer producer');
    eval(test_php_assignment_source($source, '$_SERVER', 'sanitize_uri', 'HTTP_REFERER'));
    if ($input['capture_referer'] ?? false) $extra['normalized_referer'] = $_SERVER['HTTP_REFERER'];
    $safe = validate_redirect_url($_SERVER['HTTP_REFERER'], $input['default'] ?? 'graph_view.php');
} elseif ($mode === 'cancel') {
    ob_start();
    form_save_button($input['url'], 'create');
    $rendered = ob_get_clean();
    $document = new DOMDocument();
    if (!$document->loadHTML($rendered, LIBXML_NONET)) throw new RuntimeException('Cannot parse the rendered cancel button');
    $nodes = (new DOMXPath($document))->query('//input[contains(concat(" ", normalize-space(@class), " "), " cactiReturnTo ")]');
    if ($nodes->length !== 1) throw new RuntimeException('Expected one rendered cancel destination');
    $safe = $nodes->item(0)->getAttribute('data-url');
} elseif ($mode === 'refresh_array' || $mode === 'session_refresh') {
    $config = array('url_path' => '/app/', 'cacti_version' => 'fixture', 'cacti_server_os' => 'unix');
    $_SERVER['SCRIPT_NAME'] = '/host.php';
    $_SERVER['REQUEST_URI'] = '/host.php?action=edit';
    if ($mode === 'refresh_array') {
        $refresh = array('seconds' => 30, 'page' => $input['url']);
    } else {
        $_SESSION['refresh'] = array('seconds' => 30, 'page' => $input['url']);
    }
    ob_start();
    require $root . '/include/global_session.php';
    $rendered = ob_get_clean();
    $document = new DOMDocument();
    if (!$document->loadHTML($rendered, LIBXML_NONET)) throw new RuntimeException('Cannot parse the rendered refresh script');
    if (!preg_match("/var\\s+refreshPage\\s*=\\s*'([^']*)';/", $document->textContent, $page)) throw new RuntimeException('Missing rendered refresh destination');
    if ($page[1] !== $myrefresh['page']) throw new RuntimeException('Refresh destination differs from its rendered value');
    $safe = $page[1];
} else {
    $safe = validate_redirect_url($input['url'], $input['default'] ?? 'graph_view.php');
}
$reads = $source_reads;
$request = array();
$query = parse_url($safe, PHP_URL_QUERY);
if (is_string($query)) parse_str($query, $request);
$phase = 'destination';
set_default_graph_action();
print json_encode(array_merge(array('url' => $safe, 'source_reads' => $reads, 'action' => $request['action'],
    'saved_action' => $_SESSION['sess_graph_view_action']), $extra), JSON_THROW_ON_ERROR);
