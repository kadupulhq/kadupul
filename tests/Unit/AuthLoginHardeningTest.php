<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$authSource = file_get_contents(dirname(__DIR__, 2) . '/lib/auth.php');
$authLoginSource = file_get_contents(dirname(__DIR__, 2) . '/auth_login.php');

test('auth_process_lockout uses atomic SQL increment for failed_attempts', function () use ($authSource) {
    // The fix replaces SELECT-then-UPDATE with a single atomic UPDATE
    expect(str_contains($authSource, 'failed_attempts = failed_attempts + 1'))
        ->toBeTrue();
});

test('auth_process_lockout atomic increment is inside auth_process_lockout function', function () use ($authSource) {
    // Extract the function body and verify the pattern is there
    $start = strpos($authSource, 'function auth_process_lockout(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 2000);
    expect(str_contains($body, 'failed_attempts = failed_attempts + 1'))
        ->toBeTrue();
});

test('set_auth_cookie does not use mt_rand', function () use ($authSource) {
    // Extract set_auth_cookie function body
    $start = strpos($authSource, 'function set_auth_cookie(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 1000);
    expect(str_contains($body, 'mt_rand'))
        ->toBeFalse();
});

test('set_auth_cookie does not use md5 with REQUEST_TIME', function () use ($authSource) {
    $start = strpos($authSource, 'function set_auth_cookie(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 1000);
    expect(str_contains($body, "md5(\$_SERVER['REQUEST_TIME']"))
        ->toBeFalse();
});

test('set_auth_cookie uses random_bytes for CSPRNG', function () use ($authSource) {
    $start = strpos($authSource, 'function set_auth_cookie(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 1000);
    expect(str_contains($body, 'random_bytes('))
        ->toBeTrue();
});

test('set_auth_cookie fails closed on CSPRNG failure', function () use ($authSource) {
    $start = strpos($authSource, 'function set_auth_cookie(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 1000);
    // Must catch Exception from random_bytes and return false
    expect(str_contains($body, 'catch (Exception'))
        ->toBeTrue();
    expect(str_contains($body, 'return false'))
        ->toBeTrue();
});

function auth_hardening_contract_run(string $mode, array $input): array
{
    $program = <<<'PHP'
namespace AuthHardeningNative;
set_time_limit(15);
$root = $argv[1];
$mode = $argv[2];
$input = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
$config = array('url_path' => '/app/');
$_SESSION = array('sess_user_id' => 42);
$_SERVER = array('SERVER_NAME' => 'app.example', 'SERVER_PORT' => '443');
$GLOBALS['logout_count'] = 0;
function read_config_option($name) { return $name === 'auth_method' ? 2 : ($GLOBALS['input']['custom'] ?? ''); }
function cacti_cookie_logout() { $GLOBALS['logout_count']++; }
function html_common_header($title) { print '<title>Login failure</title>'; }
function __($text) { return $text; }
function user_setting_exists(...$args) { return false; }
function is_realm_allowed($realm) { return true; }
function api_user_realm_auth($page) { return true; }
function cacti_log(...$args) {}
function header($value) { $GLOBALS['redirect'] = $value; }
require $root . '/include/global_constants.php';
require $root . '/lib/auth.php';
require $root . '/lib/functions.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
require $root . '/tests/Helpers/PhpSource.php';
$source = file_get_contents($root . '/lib/auth.php');
if ($source === false) { throw new \RuntimeException('Cannot read authentication source.'); }
// Isolate only cookie transport, page chrome and header emission. Escaping,
// URL validation, basename handling and complete auth functions are production.
$name = $mode === 'display' ? 'auth_display_custom_error_message' : 'auth_login_redirect';
eval('namespace AuthHardeningNative; ' . \test_php_function_source($source, $name));
if ($mode === 'display') {
    ob_start();
    auth_display_custom_error_message($input['message']);
    echo json_encode(array('html' => ob_get_clean(), 'logout_count' => $GLOBALS['logout_count']), JSON_THROW_ON_ERROR);
} else {
    $_SERVER[$input['source']] = $input['url'];
    register_shutdown_function(static function () {
        echo json_encode(array('redirect' => $GLOBALS['redirect'] ?? null), JSON_THROW_ON_ERROR);
    });
    auth_login_redirect('1');
}
PHP;
    $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', '-r', $program, dirname(__DIR__, 2), $mode, json_encode($input, JSON_THROW_ON_ERROR)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start authentication contract fixture.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

test('authentication failure renders both message fields as text', function (string $field, string $payload) {
    $input = array('message' => 'Normal failure', 'custom' => 'Contact the administrator');
    $input[$field] = $payload;
    $result = auth_hardening_contract_run('display', $input);
    $document = new DOMDocument();
    expect($document->loadHTML($result['html'], LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET))->toBeTrue();
    $xpath = new DOMXPath($document);
    expect($xpath->query('//script|//img|//*[@onerror]')->length)->toBe(0)
        ->and($xpath->query('//p')->item(0)->textContent)->toBe($input['message'])
        ->and($xpath->query('//p')->item(1)->textContent)->toBe($input['custom'])
        ->and($result['logout_count'])->toBe(1);
})->with(array(
    array('message', '<script>alert("x")</script><img src=x onerror="x"> & "quotes" `'),
    array('custom', '<script>alert("x")</script><img src=x onerror="x"> & "quotes" `'),
    array('message', 'A normal message'),
    array('custom', 'A normal custom message'),
));

test('login referer and server redirect keep safe local destinations', function (string $source, string $url, string $expected) {
    $result = auth_hardening_contract_run('redirect', array('source' => $source, 'url' => $url));
    expect($result['redirect'])->toBe('Location: ' . $expected);
})->with(array(
    array('HTTP_REFERER', '//elsewhere.example/app/graph_view.php', '/app/index.php'),
    array('REDIRECT_URL', '//elsewhere.example/app/graph_view.php', 'index.php'),
    array('HTTP_REFERER', 'https://elsewhere.example/app/graph_view.php', '/app/index.php'),
    array('REDIRECT_URL', 'https://elsewhere.example/app/graph_view.php', 'index.php'),
    // The existing sanitizer retains the graph-view action context.
    array('HTTP_REFERER', '/app/graph_view.php?id=2', '/app/graph_view.php?id=2&action='),
    array('REDIRECT_URL', '/app/graph_view.php?id=2', '/app/graph_view.php?id=2&action='),
    array('HTTP_REFERER', '/app/host.php?id=2', '/app/host.php?id=2'),
    array('REDIRECT_URL', '/app/host.php?id=2', '/app/host.php?id=2'),
));

test('auth_login performs auth transition hardening on successful login', function () use ($authLoginSource) {
    $tokens = array_filter(token_get_all($authLoginSource), static fn($token) => !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true));
    $normalized = implode('', array_map(static fn($token) => is_array($token) ? $token[1] : $token, $tokens));
    expect(str_contains($normalized, "cacti_auth_transition((int)\$user['id'],'login')"))
        ->toBeTrue();
});
