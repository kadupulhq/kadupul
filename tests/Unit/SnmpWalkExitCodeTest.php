<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// #1099: cacti_snmp_walk() must judge a binary walk by the command's exit
// code. net-snmp writes timeouts and tooBig errors to stderr, so matching
// that text in the walk output only matched device data.

namespace Kadupul\Tests\SnmpWalkExitCode;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/** Run the production walk in a child process against an owned fake snmpwalk. */
function walk(string $output, int $exit, string $version = '2', string $authProtocol = 'SHA', string $oidCheckDisabled = ''): array
{
    $root = dirname(__DIR__, 2);
    $program = <<<'CHILD'
        $root = $argv[1];
        $scenario = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
        require $root . '/include/global_constants.php';
        require $root . '/tests/Helpers/PhpSource.php';
        $source = file_get_contents($root . '/lib/functions.php');
        foreach (array('cacti_format_ipv6_colon', 'cacti_escapeshellarg', 'cacti_sizeof', 'is_hex_string', 'is_ipaddress', 'is_mac_address') as $function) {
            eval(test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
        }
        function read_config_option($name) { return $name === 'path_snmpwalk' ? $GLOBALS['fakeWalk'] : ($name === 'oid_increasing_check_disable' ? $GLOBALS['scenario']['oidcheck'] : ''); }
        function debug_log_insert($channel, $message) {}
        function __esc($message, ...$arguments) { return sprintf($message, ...$arguments); }
        function cacti_log($message, ...$arguments) { $GLOBALS['warnings'][] = $message; }
        $config = array('php_snmp_support' => false, 'include_path' => $root . '/include', 'cacti_server_os' => 'unix');
        $banned_snmp_strings = array('End of MIB', 'No Such');
        require $root . '/lib/snmp.php';
        $owned = sys_get_temp_dir() . '/kadupul-walk-exit-' . bin2hex(random_bytes(8));
        if (!mkdir($owned, 0700)) { throw new RuntimeException('Unable to create the owned directory'); }
        $GLOBALS['fakeWalk'] = $owned . '/snmpwalk';
        $GLOBALS['warnings'] = array();
        $fake = '#!' . PHP_BINARY . "\n<?php\n" . 'echo ' . var_export($scenario['output'], true) . '; exit(' . (int) $scenario['exit'] . ');';
        try {
            if (file_put_contents($GLOBALS['fakeWalk'], $fake) !== strlen($fake) || !chmod($GLOBALS['fakeWalk'], 0700)) {
                throw new RuntimeException('Unable to prepare the fake snmpwalk');
            }
            $rows = cacti_snmp_walk('192.0.2.1', 'public', '.1.3.6.1.2.1.31.1.1.1.18', $scenario['version'], 'user', 'secret', $scenario['auth'], 'secret', 'AES', '', 161, 500, 0, 1);
            print json_encode(array('rows' => $rows, 'warnings' => $GLOBALS['warnings']), JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($GLOBALS['fakeWalk'])) { unlink($GLOBALS['fakeWalk']); }
            rmdir($owned);
        }
        CHILD;
    $run = \test_php_run([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, json_encode(['output' => $output, 'exit' => $exit, 'version' => $version, 'auth' => $authProtocol, 'oidcheck' => $oidCheckDisabled], JSON_THROW_ON_ERROR)]);
    expect($run['status'])->toBe(0, $run['err']);

    return json_decode($run['out'], true, 512, JSON_THROW_ON_ERROR);
}

const ROWS = ".1.3.6.1.2.1.31.1.1.1.18.1 = Timeout Monitor\n.1.3.6.1.2.1.31.1.1.1.18.2 = uplink (tooBig) frames\n";

test('a successful walk keeps rows whose values mention Timeout or tooBig', function () {
    $result = walk(ROWS, 0);

    expect($result['rows'])->toBe([
        ['oid' => '.1.3.6.1.2.1.31.1.1.1.18.1', 'value' => 'Timeout Monitor'],
        ['oid' => '.1.3.6.1.2.1.31.1.1.1.18.2', 'value' => 'uplink (tooBig) frames'],
    ])->and($result['warnings'])->toBe([]);
});

test('a walk that exits non-zero returns no rows, even after partial output', function (string $output, int $exit, string $hint) {
    $result = walk($output, $exit);

    expect($result['rows'])->toBe([])
        ->and($result['warnings'])->toBe(["WARNING: SNMP Error:'Exit Code $exit', Device:'192.0.2.1', OID:'.1.3.6.1.2.1.31.1.1.1.18'" . $hint]);
})->with([
    'no output' => ['', 1, ''],
    // net-snmp exits 1 after the rows for a timeout and for an OID out of order alike
    'partial output before a timeout or an OID out of order' => [ROWS, 1, " The walk failed after returning rows; if the device returns OIDs out of order, enable 'Disable increasing OID Check' under Settings > Poller."],
    'another failure code' => [ROWS, 2, ''],
]);

test('a walk without credentials for its version is refused before running', function () {
    $result = walk(ROWS, 0, '4');

    expect($result['rows'])->toBe([])
        ->and($result['warnings'])->toBe(["WARNING: SNMP Error:'Missing credentials', Device:'192.0.2.1', OID:'.1.3.6.1.2.1.31.1.1.1.18'"]);
});

test('the out-of-order hint is left out when the increasing OID check is already disabled', function () {
    $result = walk(ROWS, 1, '2', 'SHA', 'on');

    expect($result['rows'])->toBe([])
        ->and($result['warnings'])->toBe(["WARNING: SNMP Error:'Exit Code 1', Device:'192.0.2.1', OID:'.1.3.6.1.2.1.31.1.1.1.18'"]);
});
