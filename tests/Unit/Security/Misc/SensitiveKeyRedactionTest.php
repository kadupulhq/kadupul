<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * cacti_is_sensitive_key() decides which log values are replaced with
 * '[REDACTED]'. It matches its list as case-insensitive substrings of the
 * key, so names that merely contain a listed word are redacted too; that
 * over-match is the fail-closed direction and is kept.
 *
 * The production functions are extracted into this namespace.
 */

namespace SensitiveKeyRedactionTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

if (!function_exists(__NAMESPACE__ . '\cacti_is_sensitive_key')) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
    $code   = '';

    foreach (['cacti_is_sensitive_key', 'cacti_redact_value', 'cacti_redact_sensitive'] as $name) {
        $code .= test_php_function_source($source, $name) . "\n";
    }

    // test-only eval of source read from this repository, not external input
    eval('namespace ' . __NAMESPACE__ . '; ' . $code);
}

/**
 * Credential names that were logged in clear before #871.
 *
 * @return list<string>
 */
function added_names(): array
{
    return [
        'api_key', 'apikey', 'private_key', 'privkey', 'pwd', 'authorization',
        'bearer', 'credential', 'access_key', 'session_id', 'psk', 'jwt',
        'communities',
    ];
}

/**
 * Lower, upper, mixed case and embedded forms of each added name.
 *
 * @return array<string, array{0: string}>
 */
function added_name_variants(): array
{
    $variants = [];

    foreach (added_names() as $name) {
        $variants[$name]               = [$name];
        $variants[$name . ' upper']    = [strtoupper($name)];
        $variants[$name . ' mixed']    = [ucwords($name, '_')];
        $variants[$name . ' prefix']   = [$name . '_value'];
        $variants[$name . ' suffix']   = ['remote_' . $name];
        $variants[$name . ' embedded'] = ['X_' . strtoupper($name) . '_2'];
    }

    return $variants;
}

test('credential names added for #871 are sensitive', function ($key) {
    expect(cacti_is_sensitive_key($key))->toBeTrue()
        ->and(cacti_redact_value($key, 'hunter2'))->toBe('[REDACTED]');
})->with(added_name_variants());

test('names that were already sensitive stay sensitive', function ($key) {
    expect(cacti_is_sensitive_key($key))->toBeTrue()
        ->and(cacti_redact_value($key, 'hunter2'))->toBe('[REDACTED]');
})->with([
    'password', 'pass', 'passwd', 'db_pass', 'snmp_password', 'snmp_priv_passphrase',
    'snmp_auth_passphrase', 'rsa_private_key', 'secret', 'secret_key', 'auth_key',
    'priv_key', 'token', 'cookie', 'community', 'community_string', 'snmp_community',
    'specific_password', 'ldap_password', 'csrf_token', 'LOGIN_PASSWORD',
]);

test('ordinary names are not redacted', function ($key) {
    expect(cacti_is_sensitive_key($key))->toBeFalse()
        ->and(cacti_redact_value($key, 'plain'))->toBe('plain');
})->with(['hostname', 'description', 'snmp_version', 'graph_id', 'login_username', 'ordinary']);

test('non-string keys are compared as strings', function () {
    $warnings = [];

    set_error_handler(function ($errno, $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    });

    try {
        $array_key = cacti_is_sensitive_key(['password']);
    } finally {
        restore_error_handler();
    }

    expect(cacti_is_sensitive_key(null))->toBeFalse()
        ->and(cacti_is_sensitive_key(0))->toBeFalse()
        ->and(cacti_is_sensitive_key(42))->toBeFalse()
        ->and(cacti_redact_value(7, 'plain'))->toBe('plain')
        ->and($array_key)->toBeFalse()
        ->and($warnings)->toBe(['Array to string conversion']);
});

test('cacti_redact_sensitive redacts added names at every depth', function () {
    $data = [
        'hostname' => 'router1',
        'api_key'  => 'k-1',
        'nested'   => [
            'Authorization' => 'Bearer abc',
            'ids'           => [1, 2],
            'deeper'        => ['session_id' => 's-1', 'PSK' => 'p-1', 'note' => 'keep'],
        ],
        'communities' => ['public', 'private'],
        3             => 'indexed',
    ];

    expect(cacti_redact_sensitive($data))->toBe([
        'hostname' => 'router1',
        'api_key'  => '[REDACTED]',
        'nested'   => [
            'Authorization' => '[REDACTED]',
            'ids'           => [1, 2],
            'deeper'        => ['session_id' => '[REDACTED]', 'PSK' => '[REDACTED]', 'note' => 'keep'],
        ],
        'communities' => '[REDACTED]',
        3             => 'indexed',
    ])
        ->and(cacti_redact_sensitive('jwt'))->toBe('jwt');
});
