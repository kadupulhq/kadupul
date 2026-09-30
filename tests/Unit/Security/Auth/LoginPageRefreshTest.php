<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * An anonymous visitor on the login page must not be sent to the timeout
 * logout page when the session lifetime passes.
 */

function login_page_refresh_run(string $uri, array $session): array
{
    $root = dirname(__DIR__, 4);
    $program = <<<'PHP'
$_SERVER['REQUEST_URI'] = $argv[2];
$_SERVER['SCRIPT_NAME'] = parse_url($argv[2], PHP_URL_PATH);
$_SESSION = json_decode($argv[3], true);
$config = array('url_path' => '/kadupul/', 'cacti_version' => '1.3.0', 'cacti_server_os' => 'unix');
ini_set('session.gc_maxlifetime', '1440');
function kill_session_var($name) { unset($_SESSION[$name]); }
function isset_request_var($name) { return false; }
function get_nfilter_request_var($name, $default = '') { return $default; }
function get_filter_request_var($name, $filter = 0, $options = array()) { return ''; }
function api_plugin_hook_function($name, $arg = null) { return $arg; }
function read_user_setting($name, $default = false) { return 0; }
function read_config_option($name, $force = false) { return $name === 'auth_method' ? 1 : ''; }
function is_realm_allowed($realm) { return false; }
function sanitize_uri($uri) { return $uri; }
function appendHeaderSuppression($uri) { return $uri; }
function get_selected_theme() { return 'modern'; }
function display_output_messages($refresh = false) { return "''"; }
function csrf_get_tokens() { return 'token'; }
class CactiSecureHeaders {
    public static function getNonceAttribute() { return ''; }
    public static function getNonce() { return ''; }
}
require $argv[1] . '/include/global_session.php';
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . E_ALL, '-r', $program, $root, $uri, json_encode($session)),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stderr)->toBe('');
    expect(preg_match("/var refreshIsLogout=(\\w+);/", $stdout, $logout))->toBe(1);
    expect(preg_match("/var refreshPage='([^']*)';/", $stdout, $page))->toBe(1);

    return array('logout' => $logout[1], 'page' => $page[1]);
}

test('an anonymous visitor on the login page stays there', function () {
    expect(login_page_refresh_run('/kadupul/index.php', array()))
        ->toBe(array('logout' => 'false', 'page' => '/kadupul/index.php'));
});

test('an anonymous visitor elsewhere still times out to the logout page', function () {
    expect(login_page_refresh_run('/kadupul/host.php', array()))
        ->toBe(array('logout' => 'true', 'page' => '/kadupul/logout.php?action=timeout'));
});

test('a signed-in user on the index page still times out to the logout page', function () {
    expect(login_page_refresh_run('/kadupul/index.php', array('sess_user_id' => 7)))
        ->toBe(array('logout' => 'true', 'page' => '/kadupul/logout.php?action=timeout'));
});
