<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

test('LDAP legacy method names and installed error callbacks remain callable', function () {
    $root = dirname(__DIR__, 2);
    $program = <<<'PROGRAM'
function __($message, ...$args) { return $args ? vsprintf($message, $args) : $message; }
function cacti_debug_backtrace(...$args) { return 'trace'; }
function cacti_session_close() { $GLOBALS['sessions'][] = 'close'; }
function cacti_session_start() { $GLOBALS['sessions'][] = 'start'; }
function CactiErrorHandler(...$args) { $GLOBALS['handled'][] = $args[1]; return true; }
require $argv[1] . '/lib/ldap.php';
$ldap = (new ReflectionClass(Ldap::class))->newInstanceWithoutConstructor();
foreach (['ErrorHandler', 'SetLdapHandler', 'RestoreCactiHandler', 'RecordError', 'Connect', 'Authenticate', 'GetMask', 'Search', 'Getcn'] as $name) {
    if (!is_callable([$ldap, $name])) { throw new RuntimeException('Legacy method unavailable: ' . $name); }
}
$success = call_user_func([LdapError::class, 'GetErrorDetails'], LdapError::Success);
$GLOBALS['sessions'] = [];
$GLOBALS['handled'] = [];
set_error_handler('CactiErrorHandler');
$ldap->SetLdapHandler();
trigger_error('LDAP callback', E_USER_WARNING);
$ldap->RestoreCactiHandler();
trigger_error('Cacti callback', E_USER_WARNING);
restore_error_handler();
echo json_encode([$success['error_num'], $success['error_text'], $ldap->GetMask(), $GLOBALS['sessions'], $GLOBALS['handled']]);
PROGRAM;
    $result = test_php_run([PHP_BINARY, '-r', $program, $root]);
    expect($result['status'])->toBe(0)->and($result['err'])->toBe('');
    expect(json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR))
        ->toBe([0, 'Authentication Success', ENT_COMPAT | ENT_HTML401, ['close', 'start'], ['Cacti callback']]);
});

test('deprecated matrix renderer remains callable through its legacy function name', function () {
    $root = dirname(__DIR__, 2);
    $program = <<<'PROGRAM'
require $argv[1] . '/lib/html.php';
if (!is_callable('DrawMatrixHeaderItem')) { throw new RuntimeException('Legacy renderer unavailable'); }
DrawMatrixHeaderItem('Matrix label', '#fff', 3);
PROGRAM;
    $result = test_php_run([PHP_BINARY, '-r', $program, $root]);
    expect($result['status'])->toBe(0)->and($result['err'])->toBe('');
    expect($result['out'])->toContain("colspan='3'", "<div class='textSubHeaderDark'>Matrix label</div>");
});
