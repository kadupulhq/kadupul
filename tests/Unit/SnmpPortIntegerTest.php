<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The SNMP port reaches the net-snmp command line and the native
 * "host:port" session string, so both paths must carry an integer.
 * Upstream Cacti #7689.
 */

namespace Kadupul\Tests\SnmpPortInteger;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

#[\AllowDynamicProperties]
final class SNMP
{
    public const VERSION_1 = 0;
    public const VERSION_2c = 1;
    public const VERSION_3 = 3;

    public static array $targets = [];
    public function __construct($version, $target, $community, $timeout, $retries)
    {
        self::$targets[] = $target;
    }
}

function read_config_option(string $name): string
{
    return '';
}

$snmpSource = file_get_contents(dirname(__DIR__, 2) . '/lib/snmp.php');
if (!is_string($snmpSource)) {
    throw new \RuntimeException('Unable to read lib/snmp.php.');
}

foreach (['cacti_snmp_session', 'cacti_snmp_options_sanitize', 'snmp_format_target'] as $function) {
    eval('namespace ' . __NAMESPACE__ . '; ' . test_php_function_source($snmpSource, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}

function sanitizedPort(mixed $port): array
{
    $version = 2;
    $community = 'public';
    $timeout = 500;
    $retries = 1;
    $maxOids = 10;
    $accepted = cacti_snmp_options_sanitize($version, $community, $port, $timeout, $retries, $maxOids);

    return [$accepted, $port];
}

test('the sanitizer hands callers an integer port', function (mixed $input, int $expected) {
    [$accepted, $port] = sanitizedPort($input);

    expect($accepted)->toBeTrue()->and($port)->toBe($expected);
})->with([
    'numeric string' => ['1161', 1161],
    'integer' => [1161, 1161],
    'empty uses the default' => ['', 161],
    'trailing newline' => ["1161\n", 1161],
    'leading space' => [' 1161', 1161],
    'exponent form' => ['1e3', 1000],
]);

test('the sanitizer still rejects a non-numeric port', function (string $input) {
    [$accepted] = sanitizedPort($input);

    expect($accepted)->toBeFalse();
})->with(['161;id', '161 -v', 'abc']);

test('a sanitized port cannot carry whitespace into the command target', function () {
    [, $port] = sanitizedPort("1161\n");

    expect(snmp_format_target('192.0.2.1', $port))->toBe('192.0.2.1:1161');
});

test('the native session target uses an integer port', function (mixed $input, string $expected) {
    SNMP::$targets = [];

    expect(cacti_snmp_session('192.0.2.1', 'public', '2'))->not->toBeFalse();
    SNMP::$targets = [];
    cacti_snmp_session('192.0.2.1', 'public', '2', port: $input);

    expect(SNMP::$targets)->toBe([$expected]);
})->with([
    'numeric string' => ['1161', '192.0.2.1:1161'],
    'trailing newline' => ["1161\n", '192.0.2.1:1161'],
    'non-numeric falls back to the default' => ['161;id', '192.0.2.1:161'],
]);
