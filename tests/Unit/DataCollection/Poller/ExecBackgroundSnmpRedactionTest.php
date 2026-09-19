<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * snmpagent_notification() launches snmptrap through exec_background(),
 * which logs its arguments before spawning. The production functions are
 * loaded into this namespace so the log line can be checked without
 * starting a process.
 */

namespace ExecBackgroundSnmpRedactionTest;

const POLLER_VERBOSITY_NONE = 1;
const POLLER_VERBOSITY_DEBUG = 5;

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '')
{
    $GLOBALS['exec_background_logs'][] = $message;
}

function cacti_escapeshellarg($arg)
{
    return escapeshellarg($arg);
}

function file_exists($path)
{
    return false;
}

function file_exists_2gb($path)
{
    return false;
}

/**
 * Returns the source of one top-level function, found by brace matching.
 */
function exec_background_function_source(string $source, string $name): string
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

/* eval() runs only function source read from this repository, never
 * external input. */
foreach (['lib/functions.php' => 'cacti_redact_snmp_command', 'lib/poller.php' => 'exec_background'] as $file => $function) {
    if (!function_exists(__NAMESPACE__ . '\\' . $function)) {
        eval('namespace ' . __NAMESPACE__ . '; ' . exec_background_function_source(file_get_contents(__DIR__ . '/../../../../' . $file), $function)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

beforeEach(function () {
    $GLOBALS['config']['cacti_server_os'] = 'unix';
    $GLOBALS['debug'] = false;
    $GLOBALS['exec_background_logs'] = [];
});

test('the spawn log masks snmptrap communities and v3 passphrases', function () {
    exec_background("'/usr/bin/snmptrap'", " -v 2c -c 'Comm-S3cret' -Ci 'nms.example:162' \"\" '1.3.6.1.4.1.23925'");
    exec_background('/usr/bin/snmptrap', " -v 3 -e '0x80' -u 'monitor' -l authPriv -a 'SHA' -A 'Auth-S3cret' -x 'AES' -X 'Priv-S3cret' 'nms.example:162' \"\" '1.3.6.1.4.1.23925'");

    expect($GLOBALS['exec_background_logs'])->toHaveCount(2);

    foreach ($GLOBALS['exec_background_logs'] as $line) {
        expect($line)->toContain('[REDACTED]')
            ->and($line)->toContain('nms.example:162')
            ->and($line)->not->toContain('S3cret');
    }

    expect($GLOBALS['exec_background_logs'][1])->toContain("-u 'monitor'");
});

test('the spawn log masks secrets for a snmptrap wrapper the caller names', function () {
    $args = " -v 2c -c 'Comm-S3cret' 'nms.example:162' \"\" '1.3.6.1.4.1.23925'";

    exec_background('/opt/monitor/send-trap', $args, '', cacti_redact_snmp_command($args));

    expect($GLOBALS['exec_background_logs'][0])->toContain('CMD: /opt/monitor/send-trap')
        ->and($GLOBALS['exec_background_logs'][0])->toContain('-c [REDACTED]')
        ->and($GLOBALS['exec_background_logs'][0])->not->toContain('S3cret');
});

test('snmpagent passes the redacted arguments to the spawn log', function () {
    $source = file_get_contents(__DIR__ . '/../../../../lib/snmpagent.php');

    expect($source)->toContain("exec_background(cacti_escapeshellcmd(\$path_snmptrap), \$args, '', cacti_redact_snmp_command(\$args));");
});

test('the spawn log leaves other commands untouched', function () {
    exec_background('/usr/bin/php', "-q '/var/www/kadupul/poller_automation.php' -c 5");

    expect($GLOBALS['exec_background_logs'][0])->toContain("ARGS: -q '/var/www/kadupul/poller_automation.php' -c 5]");
});
