<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

// Namespace adapters isolate external I/O without changing the production bodies.
eval(<<<'HARNESS'
namespace KadupulPhp81Tests;
function __($message) {
    if (!empty($GLOBALS['php81_translation_pcre'])) { \preg_match('/translation/', 'translation'); }
    return 'translated: ' . $message;
}
function restore_error_handler() { return true; }
function set_error_handler($handler) { $GLOBALS['php81_handler'] = $handler; return null; }
function cacti_session_close() { $GLOBALS['php81_session_closed'] = true; }
function ini_get($name) { return false; }
function ini_set($name, $value) { return false; }
function preg_match($pattern, $subject) {
    return isset($GLOBALS['php81_pcre_error']) ? false : \preg_match($pattern, $subject);
}
function preg_last_error() { return $GLOBALS['php81_pcre_error'] ?? \preg_last_error(); }
function preg_last_error_msg() { return $GLOBALS['php81_pcre_message'] ?? \preg_last_error_msg(); }
function cacti_count($value) { return count($value); }
function fsockopen($address, $port) { $GLOBALS['php81_socket_opened'] = true; return null; }
function stream_set_timeout(...$arguments) { return true; }
function stream_set_blocking(...$arguments) { return true; }
function fwrite($handle, $data) { $GLOBALS['php81_dns_packet'] = $data; return strlen($data); }
function fread(...$arguments) { return ''; }
function stream_get_meta_data($handle) { return []; }
function fclose($handle) { return true; }
HARNESS);

$root = dirname(__DIR__, 2);
foreach (['validate_is_regex' => 'lib/html_utility.php', 'automation_get_dns_from_ip' => 'lib/api_automation.php'] as $function => $file) {
    eval('namespace KadupulPhp81Tests; ' . test_php_function_source(file_get_contents($root . '/' . $file), $function));
}
foreach ([['Ping', 'lib/ping.php', 'set_ping_error_handler', 'ping_error_handler'], ['Ldap', 'lib/ldap.php', 'SetLdapHandler', 'ErrorHandler']] as [$class, $file, $register, $handler]) {
    $source = file_get_contents($root . '/' . $file);
    eval('namespace KadupulPhp81Tests; class ' . $class . ' {' . test_php_function_source($source, $register) . test_php_function_source($source, $handler) . '}');
}

afterEach(function () {
    foreach (['handler', 'session_closed', 'pcre_error', 'pcre_message', 'socket_opened', 'dns_packet', 'translation_pcre'] as $key) {
        unset($GLOBALS['php81_' . $key]);
    }
});

test('native callables preserve receiver and overridden error handlers', function ($class, $register, $handler) {
    $name = 'KadupulPhp81Tests\\' . $class . 'Override';
    eval('namespace KadupulPhp81Tests; class ' . $class . 'Override extends ' . $class . ' { public $called = false; function ' . $handler . '(...$arguments) { $this->called = true; return true; }}');
    $receiver = new $name();
    $receiver->$register();
    expect($GLOBALS['php81_handler'])->toBeInstanceOf(Closure::class);
    expect(($GLOBALS['php81_handler'])(E_USER_WARNING, 'warning', __FILE__, __LINE__))->toBeTrue();
    expect($receiver->called)->toBeTrue();
    if ($class === 'Ldap') {
        expect($GLOBALS['php81_session_closed'])->toBeTrue();
    }
})->with([['Ping', 'set_ping_error_handler', 'ping_error_handler'], ['Ldap', 'SetLdapHandler', 'ErrorHandler']]);

test('DNS request preserves one two and three byte segment prefixes', function () {
    expect(KadupulPhp81Tests\automation_get_dns_from_ip('1.22.123.4', 'unused'))->toBe('1.22.123.4');
    expect(substr($GLOBALS['php81_dns_packet'], 12))->toBe("\1" . '4' . "\3" . '123' . "\2" . '22' . "\1" . '1' . "\7in-addr\4arpa\0\0\x0C\0\1");
});

test('invalid DNS segments are rejected before opening a socket', function ($ip) {
    expect(KadupulPhp81Tests\automation_get_dns_from_ip($ip, 'unused'))->toBe('ERROR');
    expect(isset($GLOBALS['php81_socket_opened']))->toBeFalse();
})->with(['1.2.3', '1.2.3.', '1234.2.3.4']);

test('regex validation returns a boolean or an explanatory message', function ($pattern, $valid) {
    $result = KadupulPhp81Tests\validate_is_regex($pattern);
    if ($valid) {
        expect($result)->toBeTrue();
    } else {
        expect($result)->toBeString()->not->toBe('');
    }
})->with([['', true], ['[a-z]+', true], ['(', false], ['a;b', false], [str_repeat('a', 51), false]]);

test('regex diagnostics preserve translations and cover unmapped PCRE failures', function ($code, $native, $expected) {
    $GLOBALS['php81_pcre_error'] = $code;
    $GLOBALS['php81_pcre_message'] = $native;
    expect(KadupulPhp81Tests\validate_is_regex('pattern'))->toBe($expected);
})->with([
    [PREG_BACKTRACK_LIMIT_ERROR, 'Backtrack limit exhausted', 'translated: Backtrack limit was exhausted!'],
    [PREG_JIT_STACKLIMIT_ERROR, 'JIT stack limit exhausted', 'JIT stack limit exhausted'],
]);

test('regex diagnostics are captured before translation can change PCRE state', function () {
    $GLOBALS['php81_translation_pcre'] = true;
    expect(KadupulPhp81Tests\validate_is_regex('('))->toBe('translated: There was an internal error!');
});
