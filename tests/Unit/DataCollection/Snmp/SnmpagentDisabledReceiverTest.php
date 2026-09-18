<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * snmpagent_managers.disabled is char(2) holding '' or 'on', or NULL on rows
 * the form never saved. snmpagent_notification() is loaded into this
 * namespace with a database stand-in that filters the receiver rows the way
 * MySQL and MariaDB evaluate the query's disabled predicate, so the test
 * sees which receivers would be sent a trap.
 */

namespace SnmpagentDisabledReceiverTest;

const SNMPAGENT_EVENT_SEVERITY_LOW = 1;
const SNMPAGENT_EVENT_SEVERITY_MEDIUM = 2;
const SNMPAGENT_EVENT_SEVERITY_HIGH = 3;
const SNMPAGENT_EVENT_SEVERITY_CRITICAL = 4;
const POLLER_VERBOSITY_NONE = 1;
const POLLER_VERBOSITY_MEDIUM = 3;

function receiver_rows(): array
{
    $base = [
        'snmp_version' => 2, 'snmp_community' => 'public', 'snmp_port' => 162,
        'snmp_message_type' => 1, 'snmp_engine_id' => '', 'snmp_username' => '',
        'snmp_password' => '', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '',
        'snmp_priv_protocol' => '',
    ];

    return [
        $base + ['id' => 1, 'hostname' => 'enabled.example', 'disabled' => ''],
        $base + ['id' => 2, 'hostname' => 'disabled.example', 'disabled' => 'on'],
        $base + ['id' => 3, 'hostname' => 'legacy-null.example', 'disabled' => null],
    ];
}

/**
 * Applies the query's disabled predicate with MySQL comparison rules: a
 * string compared with the number 0 is cast to a number, so '' and 'on'
 * both equal 0, while NULL never compares equal to anything.
 */
function mysql_filter_disabled(string $sql, array $rows): array
{
    if (preg_match('/\bdisabled\s*=\s*0\b/', $sql)) {
        return array_values(array_filter($rows, fn($row) => $row['disabled'] !== null && (float) $row['disabled'] == 0));
    }

    if (preg_match("/COALESCE\\([a-z_.]*disabled, ''\\) = ''/", $sql)) {
        return array_values(array_filter($rows, fn($row) => (string) $row['disabled'] === ''));
    }

    if (preg_match('/\bdisabled\b/', $sql)) {
        throw new \RuntimeException('Unrecognised disabled predicate: ' . $sql);
    }

    return $rows;
}

function read_config_option($name)
{
    return '/usr/bin/snmptrap';
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {}

function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}

function cacti_escapeshellarg($arg)
{
    return escapeshellarg((string) $arg);
}

function cacti_escapeshellcmd($command)
{
    return escapeshellcmd($command);
}

function cacti_is_sensitive_key($key)
{
    return in_array($key, ['snmp_community', 'snmp_password', 'snmp_priv_passphrase'], true);
}

function sql_save($save, $table)
{
    return 1;
}

function db_fetch_cell_prepared($sql, $params = [])
{
    return '1.3.6.1.4.1.23925.1.2.1';
}

function db_fetch_assoc_prepared($sql, $params = [])
{
    if (strpos($sql, 'snmpagent_managers') !== false) {
        return mysql_filter_disabled($sql, receiver_rows());
    }

    return [];
}

function exec_background($filename, $args = '', $redirect_args = '')
{
    $GLOBALS['snmpagent_disabled_traps'][] = $args;
}

/**
 * Returns the source of one top-level function, found by brace matching.
 */
function snmpagent_function_source(string $source, string $name): string
{
    $start = strpos($source, "\nfunction " . $name . '(');

    if ($start === false) {
        throw new \RuntimeException($name . '() not found in lib/snmpagent.php');
    }

    $brace = strpos($source, '{', strpos($source, ')', $start));
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

if (!function_exists(__NAMESPACE__ . '\\snmpagent_notification')) {
    /* eval() runs only function source read from lib/snmpagent.php in this
     * repository, never external input. */
    eval('namespace ' . __NAMESPACE__ . '; ' . snmpagent_function_source(file_get_contents(__DIR__ . '/../../../../lib/snmpagent.php'), 'snmpagent_notification')); // nosemgrep: php.lang.security.eval-use.eval-use
}

beforeEach(function () {
    global $config, $snmpagent_event_severity;

    $config['library_path'] = sys_get_temp_dir() . '/snmpagent-disabled-receiver-lib';

    if (!is_dir($config['library_path'])) {
        mkdir($config['library_path'], 0700, true);
    }

    file_put_contents($config['library_path'] . '/poller.php', "<?php\n");

    unset($config['snmpagent']);
    $snmpagent_event_severity = [1 => 'LOW', 2 => 'MEDIUM', 3 => 'HIGH', 4 => 'CRITICAL'];
    $GLOBALS['snmpagent_disabled_traps'] = [];
});

test('a disabled notification receiver is not sent a trap', function () {
    snmpagent_notification('cactiNotifyDeviceDown', 'CACTI-MIB', [], SNMPAGENT_EVENT_SEVERITY_HIGH);

    $targets = implode("\n", $GLOBALS['snmpagent_disabled_traps']);

    expect($targets)->not->toContain('disabled.example');
});

test('enabled receivers, including legacy NULL rows, still get the trap', function () {
    snmpagent_notification('cactiNotifyDeviceDown', 'CACTI-MIB', [], SNMPAGENT_EVENT_SEVERITY_HIGH);

    $targets = implode("\n", $GLOBALS['snmpagent_disabled_traps']);

    expect($GLOBALS['snmpagent_disabled_traps'])->toHaveCount(2)
        ->and($targets)->toContain('enabled.example:162')
        ->and($targets)->toContain('legacy-null.example:162');
});
