<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

/*
 * An anonymous visitor on the login page must not be sent to the timeout
 * logout page when the session lifetime passes.
 */

function login_page_refresh_run(string $uri, array $session, ?string $script = null, array $options = array()): array
{
    $root = dirname(__DIR__, 4);
    $program = <<<'PHP'
$options = json_decode($argv[5], true);
if ($options['missing_uri'] ?? false) {
    unset($_SERVER['REQUEST_URI']);
} else {
    $_SERVER['REQUEST_URI'] = $argv[2];
}
if (isset($options['refresh'])) $refresh = $options['refresh'];
$_SERVER['SCRIPT_NAME'] = $argv[4];
$_SESSION = json_decode($argv[3], true);
$config = array('url_path' => '/kadupul/', 'cacti_version' => '1.3.0', 'cacti_server_os' => 'unix');
ini_set('session.gc_maxlifetime', '1440');
function kill_session_var($name) { unset($_SESSION[$name]); }
function isset_request_var($name) { return false; }
function get_nfilter_request_var($name, $default = '') { return $default; }
function get_filter_request_var($name, $filter = 0, $options = array()) { return ''; }
function api_plugin_hook_function($name, $arg = null) { return $arg; }
function read_user_setting($name, $default = false) { return 0; }
function read_config_option($name, $force = false) {
    global $options;
    return $options['config'][$name] ?? ($name === 'auth_method' ? 1 : '');
}
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
ob_start();
require $argv[1] . '/include/global_session.php';
$rendered = ob_get_clean();
echo $rendered;
if (preg_match('/var refreshIsLogout=(\\w+);/', $rendered) && str_contains($rendered, "var refreshPage='")) {
    $GLOBALS['nativeChildCoverageMarkers'][] = 'refresh-render-readback';
}
PHP;

    $process = proc_open(
        child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . E_ALL, '-r', $program, $root, $uri, json_encode($session), $script ?? parse_url($uri, PHP_URL_PATH), json_encode($options, JSON_THROW_ON_ERROR)), $coverage_dir, child_coverage_registration(__FILE__, 'login-page-refresh', array($uri, $session, $script, $options), array('refresh-render-readback'), array('include/global_session.php'), array())),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) throw new RuntimeException('Cannot start session refresh fixture');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    child_coverage_collect($coverage_dir);

    expect($status)->toBe(0);
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

test('the dedicated login entry preserves an anonymous login form on refresh', function () {
    expect(login_page_refresh_run('/kadupul/login.php', array()))
        ->toBe(array('logout' => 'false', 'page' => '/kadupul/login.php'));
});

test('a signed-in user on the index page still times out to the logout page', function () {
    expect(login_page_refresh_run('/kadupul/index.php', array('sess_user_id' => 7)))
        ->toBe(array('logout' => 'true', 'page' => '/kadupul/logout.php?action=timeout'));
});

test('the application root refreshes the executing login script', function () {
    expect(login_page_refresh_run('/kadupul/', array(), '/kadupul/index.php'))->toBe(array('logout' => 'false', 'page' => '/kadupul/'));
});

test('index.php in another page query is not the login page', function () {
    expect(login_page_refresh_run('/kadupul/host.php?return=index.php', array()))->toBe(array('logout' => 'true', 'page' => '/kadupul/logout.php?action=timeout'));
});

test('refresh paths retain the request URI or an empty fallback without changing logout policy', function (array $session, array $options, bool $missing): void {
    $options['missing_uri'] = $missing;
    $uri = '/kadupul/index.php?node=1';
    expect(login_page_refresh_run($uri, $session, '/kadupul/index.php', $options))
        ->toBe(array('logout' => 'false', 'page' => $missing ? '' : $uri));
})->with(array(
    'scalar refresh' => array(array(), array('refresh' => 30)),
    'remembered login' => array(array('cacti_remembers' => true), array('config' => array('auth_cache_enabled' => 'on'))),
    'anonymous login' => array(array(), array()),
    'guest session' => array(array('sess_user_id' => 7), array('config' => array('guest_user' => 7))),
    'Basic authentication' => array(array('sess_user_id' => 7), array('config' => array('auth_method' => 2))),
))->with(array(true, false));
