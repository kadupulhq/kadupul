<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The net-snmp binary path in lib/snmp.php logs each command line to the
 * session debug log, which host.php renders in the Data Query debug panel
 * and a remote agent returns as JSON. Communities and v3 passphrases must
 * not reach that log. The production functions are loaded into this
 * namespace so exec() and the debug log can be observed without a device.
 */

namespace SnmpCommandSecretRedactionTest;

const SNMP_METHOD_PHP = 1;
const SNMP_METHOD_BINARY = 2;
const SNMP_STRING_OUTPUT_GUESS = 1;
const SNMP_STRING_OUTPUT_HEX = 3;
const POLLER_VERBOSITY_HIGH = 4;
const SNMP_POLLER = 0;

function read_config_option($name)
{
    return '/usr/bin/' . str_replace('path_', '', $name);
}

function cacti_snmp_options_sanitize($version, $community, &$port, &$timeout, &$retries, &$max_oids)
{
    return true;
}

function snmp_get_method($type = 'walk', $version = 1, $context = '', $engineid = '', $value_output_format = SNMP_STRING_OUTPUT_GUESS)
{
    return SNMP_METHOD_BINARY;
}

function cacti_format_ipv6_colon($hostname)
{
    return $hostname;
}

function cacti_escapeshellcmd($command)
{
    return escapeshellcmd($command);
}

function cacti_escapeshellarg($arg)
{
    return escapeshellarg($arg);
}

function format_snmp_string($string, $snmp_oid_included, $value_output_format = SNMP_STRING_OUTPUT_GUESS, $strip_alpha = false)
{
    return $string;
}

function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {}

function __esc($text, ...$args)
{
    return htmlspecialchars(vsprintf($text, $args), ENT_QUOTES);
}

function debug_log_insert($type, $text)
{
    $GLOBALS['snmp_redaction_debug_log'][] = $text;
}

function exec($command, &$output = null, &$result_code = null)
{
    $GLOBALS['snmp_redaction_exec'][] = $command;
    $output = array();
    $result_code = 0;

    return '';
}

function file_exists($path)
{
    return $GLOBALS['snmp_redaction_bulkwalk'];
}

function exec_into_array($command)
{
    $GLOBALS['snmp_redaction_exec'][] = $command;

    return array();
}

/**
 * Returns the source of one top-level function, found by brace matching.
 */
function snmp_redaction_function_source(string $source, string $name): string
{
    $start = strpos($source, "\nfunction " . $name . '(');

    if ($start === false) {
        throw new \RuntimeException($name . '() not found');
    }

    $brace = strpos($source, ') {', $start) + 2;
    $depth = 1;
    $i = $brace + 1;
    $length = strlen($source);

    while ($depth > 0 && $i < $length) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
        }

        $i++;
    }

    return substr($source, $start, $i - $start);
}

$snmpRedactionSources = [
    'lib/functions.php' => ['cacti_redact_snmp_command'],
    'lib/snmp.php'      => ['cacti_snmp_get', 'cacti_snmp_get_raw', 'cacti_snmp_getnext', 'cacti_snmp_walk', 'cacti_get_snmpv3_auth', 'snmp_format_target', 'snmp_escape_string'],
];

/* eval() runs only function source read from this repository, never
 * external input, so the test exercises the production code without the
 * include-time setup of lib/functions.php and lib/snmp.php. */
foreach ($snmpRedactionSources as $file => $functions) {
    $source = file_get_contents(__DIR__ . '/../../../../' . $file);

    foreach ($functions as $function) {
        if (!function_exists(__NAMESPACE__ . '\\' . $function) && strpos($source, "\nfunction " . $function . '(') !== false) {
            eval('namespace ' . __NAMESPACE__ . '; ' . snmp_redaction_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
        }
    }
}

beforeEach(function () {
    global $config, $snmp_auth_protocols, $snmp_priv_protocols, $banned_snmp_strings;

    $config['cacti_server_os'] = 'unix';
    $snmp_auth_protocols = ['SHA' => 'SHA'];
    $snmp_priv_protocols = ['AES128' => 'AES'];
    $banned_snmp_strings = ['End of MIB'];

    $_SESSION = [];
    $GLOBALS['snmp_redaction_debug_log'] = [];
    $GLOBALS['snmp_redaction_exec'] = [];
    $GLOBALS['snmp_redaction_bulkwalk'] = true;
});

dataset('snmp binary calls', [
    'get v2c' => ['cacti_snmp_get', ['192.0.2.1', 'Comm-S3cret', '.1.3.6.1.2.1.1.1.0', 2]],
    'get_raw v2c' => ['cacti_snmp_get_raw', ['192.0.2.1', 'Comm-S3cret', '.1.3.6.1.2.1.1.1.0', 2]],
    'getnext v1' => ['cacti_snmp_getnext', ['192.0.2.1', 'Comm-S3cret', '.1.3.6.1.2.1.1.1', 1]],
    'walk v2c' => ['cacti_snmp_walk', ['192.0.2.1', 'Comm-S3cret', '.1.3.6.1.2.1.1', 2]],
    'get v3' => ['cacti_snmp_get', ['192.0.2.1', '', '.1.3.6.1.2.1.1.1.0', 3, 'monitor', 'Auth-S3cret', 'SHA', 'Priv-S3cret', 'AES128']],
    'walk v3' => ['cacti_snmp_walk', ['192.0.2.1', '', '.1.3.6.1.2.1.1', 3, 'monitor', 'Auth-S3cret', 'SHA', 'Priv-S3cret', 'AES128']],
]);

test('the SNMP debug log never carries the community or v3 passphrases', function (string $function, array $args) {
    call_user_func_array(__NAMESPACE__ . '\\' . $function, $args);

    expect($GLOBALS['snmp_redaction_exec'])->not->toBeEmpty();
    expect($GLOBALS['snmp_redaction_debug_log'])->toHaveCount(count($GLOBALS['snmp_redaction_exec']));

    foreach ($GLOBALS['snmp_redaction_debug_log'] as $line) {
        expect($line)->toStartWith('SNMP Command is: ');
        expect($line)->toContain('[REDACTED]');
        expect($line)->not->toContain('S3cret');
    }
})->with('snmp binary calls');

test('the snmpwalk fallback debug log is redacted when snmpbulkwalk is absent', function () {
    $GLOBALS['snmp_redaction_bulkwalk'] = false;

    cacti_snmp_walk('192.0.2.1', 'Comm-S3cret', '.1.3.6.1.2.1.1', 2);

    expect($GLOBALS['snmp_redaction_exec'][0])->toContain('/usr/bin/snmpwalk ');
    expect($GLOBALS['snmp_redaction_debug_log'][0])->toContain('-c [REDACTED]');
    expect($GLOBALS['snmp_redaction_debug_log'][0])->not->toContain('S3cret');
});

test('the v3 security name stays visible for debugging', function () {
    cacti_snmp_get('192.0.2.1', '', '.1.3.6.1.2.1.1.1.0', 3, 'monitor', 'Auth-S3cret', 'SHA', 'Priv-S3cret', 'AES128');

    expect($GLOBALS['snmp_redaction_debug_log'][0])->toContain('-u &#039;monitor&#039;');
    expect($GLOBALS['snmp_redaction_debug_log'][0])->toContain('-A [REDACTED]');
    expect($GLOBALS['snmp_redaction_debug_log'][0])->toContain('-X [REDACTED]');
});

test('command redaction covers quoted, escaped and joined values', function (string $command, string $expected) {
    expect(cacti_redact_snmp_command($command))->toBe($expected);
})->with([
    'single quoted' => ["snmpget -c 'pub lic' -v 2c 'h':161", "snmpget -c [REDACTED] -v 2c 'h':161"],
    'posix embedded quote' => ["snmpget -c 'it'\\''s' -v 1 h", 'snmpget -c [REDACTED] -v 1 h'],
    'joined' => ["snmpget -c'x y' -v 1 h", 'snmpget -c[REDACTED] -v 1 h'],
    'bare' => ['snmpget -cplain -v 1 h', 'snmpget -c[REDACTED] -v 1 h'],
    'win32 double quoted' => ['snmpget -c "a\\"b c" -v 1 h', 'snmpget -c [REDACTED] -v 1 h'],
    'v3' => ["snmpwalk -u 'bob' -a 'SHA' -A 'p w' -X 'q' -x 'AES' -Cr10 -Cc h", "snmpwalk -u 'bob' -a 'SHA' -A [REDACTED] -X [REDACTED] -x 'AES' -Cr10 -Cc h"],
]);
