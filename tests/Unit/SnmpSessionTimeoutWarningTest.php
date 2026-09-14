<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Runtime regression test for the SNMP timeout warnings in lib/snmp.php.
 *
 * The bundled SNMP class stores the session timeout in milliseconds. The
 * walk, get and getnext helpers used to divide it by 1000 again, so a 500 ms
 * timeout was logged as 0.5 ms or 1 ms. This test loads the three production
 * functions into this namespace, runs them against a session that reports a
 * timeout, and checks the logged line.
 */

namespace SnmpSessionTimeoutWarningTest;

const POLLER_VERBOSITY_HIGH = 4;
const SNMP_STRING_OUTPUT_GUESS = 1;

final class SNMP
{
    public const ERRNO_TIMEOUT = 2;
}

/**
 * A session whose every request fails with a timeout.
 */
final class TimedOutSession
{
    public array $info = ['timeout' => 500, 'hostname' => 'router-1'];
    public int $bulk_walk_size = 10;
    public $value_output_format;

    public function walk($oid, $suffix_as_key = false, $max_repetitions = -1, $non_repeaters = 0)
    {
        return false;
    }

    public function get($oid)
    {
        return false;
    }

    public function getnext($oid)
    {
        return false;
    }

    public function getErrno(): int
    {
        return SNMP::ERRNO_TIMEOUT;
    }
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '')
{
    $GLOBALS['snmp_timeout_warning_logs'][] = [$message, $output, $environ, $level];
}

function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}

function format_snmp_string($string, $snmp_oid_included, $value_output_format = SNMP_STRING_OUTPUT_GUESS, $strip_alpha = false)
{
    return $string;
}

/**
 * Returns the source of one top-level function, found by brace matching.
 */
function snmp_timeout_warning_function_source(string $source, string $name): string
{
    $start = strpos($source, 'function ' . $name . '(');

    if ($start === false) {
        throw new \RuntimeException($name . '() not found in lib/snmp.php');
    }

    $brace = strpos($source, '{', $start);
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

$snmpSource = file_get_contents(__DIR__ . '/../../lib/snmp.php');

/* eval() runs only function source read from lib/snmp.php in this repository,
 * never external input, so the test exercises the production code without
 * loading lib/snmp.php's include-time setup. */
foreach (['cacti_snmp_session_walk', 'cacti_snmp_session_get', 'cacti_snmp_session_getnext'] as $snmpFunction) {
    if (!function_exists(__NAMESPACE__ . '\\' . $snmpFunction)) {
        eval('namespace ' . __NAMESPACE__ . '; ' . snmp_timeout_warning_function_source($snmpSource, $snmpFunction)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

beforeEach(function () {
    $GLOBALS['snmp_timeout_warning_logs'] = [];
});

test('walk logs the configured timeout in milliseconds', function () {
    expect(cacti_snmp_session_walk(new TimedOutSession(), '.1.3.6.1.2.1.1.1'))->toBe([]);

    expect($GLOBALS['snmp_timeout_warning_logs'])->toBe([
        ["WARNING: SNMP Error:'Timeout (500 ms)', Device:'router-1', OID:'.1.3.6.1.2.1.1.1'", false, 'SNMP', POLLER_VERBOSITY_HIGH],
    ]);
});

test('get logs the configured timeout in milliseconds', function () {
    expect(cacti_snmp_session_get(new TimedOutSession(), '.1.3.6.1.2.1.1.3.0'))->toBeFalse();

    expect($GLOBALS['snmp_timeout_warning_logs'])->toBe([
        ["WARNING: SNMP Error:'Timeout (500 ms)', Device:'router-1', OID:'.1.3.6.1.2.1.1.3.0'", false, 'SNMP', POLLER_VERBOSITY_HIGH],
    ]);
});

test('getnext logs the configured timeout in milliseconds', function () {
    expect(cacti_snmp_session_getnext(new TimedOutSession(), '.1.3.6.1.2.1.1.5'))->toBeFalse();

    expect($GLOBALS['snmp_timeout_warning_logs'])->toBe([
        ["WARNING: SNMP Error:'Timeout (500 ms)', Device:'router-1', OID:'.1.3.6.1.2.1.1.5'", false, 'SNMP', POLLER_VERBOSITY_HIGH],
    ]);
});
