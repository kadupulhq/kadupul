<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2010 Boris Lytochkin, Sponsored by Yandex LLC
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* trim all but hex-string:, which will return 'hex-' */
#define('REGEXP_SNMP_TRIM', '/(counter(32|64):|gauge:|gauge(32|64):|float:|ipaddress:|string:|integer:)$/i');
define('REGEXP_SNMP_TRIM', '/(hex|counter(32|64)|gauge|gauge(32|64)|float|ipaddress|string|integer):/i');

define('SNMP_METHOD_PHP', 1);
define('SNMP_METHOD_BINARY', 2);

if (!defined('SNMP_STRING_OUTPUT_GUESS')) {
    define('SNMP_STRING_OUTPUT_GUESS', 1);
}

if (!defined('SNMP_STRING_OUTPUT_ASCII')) {
    define('SNMP_STRING_OUTPUT_ASCII', 2);
}

if (!defined('SNMP_STRING_OUTPUT_HEX')) {
    define('SNMP_STRING_OUTPUT_HEX', 3);
}

global $banned_snmp_strings;
$banned_snmp_strings = array('End of MIB', 'No Such', 'No more');

if ($config['php_snmp_support']) {
    include_once($config['include_path'] . '/vendor/phpsnmp/extension.php');
} else {
    include_once($config['include_path'] . '/vendor/phpsnmp/classSNMP.php');
}

use phpsnmp\SNMP;

/**
 * Preserve the shared SNMPv3 session/request security negotiation.
 *
 * Privacy-disabled requests retain their original passphrase at the native
 * API boundary; binary callers separately omit unused privacy arguments.
 *
 * @return array{0: string, 1: mixed} Security level and effective privacy protocol.
 */
function cacti_snmpv3_security_settings($auth_pass, $auth_proto, $priv_pass, $priv_proto): array
{
    if ($priv_proto == '[None]' || $priv_pass == '') {
        return array($auth_pass == '' || $auth_proto == '[None]' ? 'noAuthNoPriv' : 'authNoPriv', '');
    }

    return array('authPriv', $priv_proto);
}

function cacti_snmp_session(
    $hostname,
    $community,
    $version,
    $auth_user = '',
    $auth_pass = '',
    $auth_proto = '',
    $priv_pass = '',
    $priv_proto = '',
    $context = '',
    $engineid = '',
    $port = 161,
    $timeout_ms = 500,
    $retries = 0,
    $max_oids = 10,
    $bulk_walk_size = 10
) {

    switch ($version) {
        case '1':
            $version = SNMP::VERSION_1;
            break;
        case '2':
            $version = SNMP::VERSION_2c;
            break;
        case '3':
            $version = SNMP::VERSION_3;
            break;
    }

    $timeout_us = (int) ($timeout_ms * 1000);

    /* Encapsulate IPv6 addresses in brackets to prevent the SNMP library
       from interpreting the port as an IPv6 hextet */
    $snmp_hostname = $hostname;
    if (strpos($snmp_hostname, ':') !== false && strpos($snmp_hostname, '[') === false) {
        $snmp_hostname = '[' . $snmp_hostname . ']';
    }

    try {
        $session = @new SNMP($version, $snmp_hostname . ':' . $port, ($version == 3 ? $auth_user : $community), $timeout_us, $retries);
    } catch (Exception $e) {
        return false;
    }

    if (defined('SNMP_OID_OUTPUT_NUMERIC')) {
        $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
        $session->valueretrieval = SNMP_VALUE_PLAIN;
    }

    $session->quick_print = false;
    $session->max_oids = $max_oids;
    $session->bulk_walk_size = $bulk_walk_size;

    if (read_config_option('oid_increasing_check_disable') == 'on') {
        $session->oid_increasing_check = false;
    }

    if ($version != SNMP::VERSION_3) {
        return $session;
    }

    list($sec_level, $priv_proto) = cacti_snmpv3_security_settings($auth_pass, $auth_proto, $priv_pass, $priv_proto);

    try {
        $session->setSecurity($sec_level, $auth_proto, $auth_pass, $priv_proto, $priv_pass, $context, $engineid);
    } catch (Exception $e) {
        return false;
    }

    return $session;
}

function cacti_snmp_get(
    $hostname,
    $community,
    $oid,
    $version,
    $auth_user = '',
    $auth_pass = '',
    $auth_proto = '',
    $priv_pass = '',
    $priv_proto = '',
    $context = '',
    $port = 161,
    $timeout_ms = 500,
    $retries = 0,
    $environ = 'SNMP',
    $engineid = '',
    $value_output_format = SNMP_STRING_OUTPUT_GUESS
) {

    global $config, $snmp_error;

    $max_oids   = 1;
    $snmp_error = '';

    if (!cacti_snmp_options_sanitize($version, $community, $port, $timeout_ms, $retries, $max_oids)) {
        return 'U';
    }

    if (snmp_get_method('get', $version, $context, $engineid, $value_output_format) == SNMP_METHOD_PHP) {
        /* make sure snmp* is verbose so we can see what types of data
        we are getting back */
        snmp_set_quick_print(0);

        if (function_exists('snmp_set_enum_print')) {
            snmp_set_enum_print(true);
        }

        $timeout_us = (int) ($timeout_ms * 1000);
        $snmp_value = 'U';

        try {
            if ($version == '1') {
                $snmp_value = @snmpget($hostname . ':' . $port, $community, $oid, $timeout_us, $retries);
            } elseif ($version == '2') {
                $snmp_value = @snmp2_get($hostname . ':' . $port, $community, $oid, $timeout_us, $retries);
            } else {
                list($sec_level, $priv_proto) = cacti_snmpv3_security_settings($auth_pass, $auth_proto, $priv_pass, $priv_proto);

                $snmp_value = @snmp3_get($hostname . ':' . $port, $auth_user, $sec_level, $auth_proto, $auth_pass, $priv_proto, $priv_pass, $oid, $timeout_us, $retries);
            }
        } catch (Exception $ex) {
            $snmp_error = $ex->getMessage();
        }

        if ($snmp_value === false) {
            cacti_log("WARNING: SNMP Error:'$snmp_error', Device:'$hostname', OID:'$oid'", false, $environ);
            $snmp_value = 'U';
        } else {
            $snmp_value = format_snmp_string($snmp_value, false, $value_output_format);
        }
    } else {
        return cacti_snmp_read_binary_value(
            'get',
            array($hostname, $port, $oid, $version, $community, $timeout_ms, $retries),
            array($auth_proto, $auth_user, $auth_pass, $priv_proto, $priv_pass, $context, $engineid),
            $value_output_format
        );
    }

    return $snmp_value;
}

function cacti_snmp_get_raw(
    $hostname,
    $community,
    $oid,
    $version,
    $auth_user = '',
    $auth_pass = '',
    $auth_proto = '',
    $priv_pass = '',
    $priv_proto = '',
    $context = '',
    $port = 161,
    $timeout_ms = 500,
    $retries = 0,
    $environ = SNMP_POLLER,
    $engineid = '',
    $value_output_format = SNMP_STRING_OUTPUT_GUESS
) {

    global $config, $snmp_error;

    $max_oids   = 1;
    $snmp_error = '';

    if (!cacti_snmp_options_sanitize($version, $community, $port, $timeout_ms, $retries, $max_oids)) {
        return 'U';
    }

    if (snmp_get_method('get', $version, $context, $engineid, $value_output_format) == SNMP_METHOD_PHP) {
        /* make sure snmp* is verbose so we can see what types of data
        we are getting back */
        snmp_set_quick_print(0);

        $timeout_us = (int) ($timeout_ms * 1000);

        if (function_exists('snmp_set_enum_print')) {
            snmp_set_enum_print(true);
        }

        if ($version == '1') {
            $snmp_value = @snmpget($hostname . ':' . $port, $community, $oid, $timeout_us, $retries);
        } elseif ($version == '2') {
            $snmp_value = @snmp2_get($hostname . ':' . $port, $community, $oid, $timeout_us, $retries);
        } else {
            list($sec_level, $priv_proto) = cacti_snmpv3_security_settings($auth_pass, $auth_proto, $priv_pass, $priv_proto);

            $snmp_value = @snmp3_get($hostname . ':' . $port, $auth_user, $sec_level, $auth_proto, $auth_pass, $priv_proto, $priv_pass, $oid, $timeout_us, $retries);
        }

        if ($snmp_value === false) {
            cacti_log("WARNING: SNMP Error:'$snmp_error', Device:'$hostname', OID:'$oid'", false);
            $snmp_value = 'U';
        }
    } else {
        return cacti_snmp_read_binary_value(
            'get_raw',
            array($hostname, $port, $oid, $version, $community, $timeout_ms, $retries),
            array($auth_proto, $auth_user, $auth_pass, $priv_proto, $priv_pass, $context, $engineid),
            $value_output_format
        );
    }

    return $snmp_value;
}

function cacti_snmp_getnext(
    $hostname,
    $community,
    $oid,
    $version,
    $auth_user = '',
    $auth_pass = '',
    $auth_proto = '',
    $priv_pass = '',
    $priv_proto = '',
    $context = '',
    $port = 161,
    $timeout_ms = 500,
    $retries = 0,
    $environ = 'SNMP',
    $engineid = '',
    $value_output_format = SNMP_STRING_OUTPUT_GUESS
) {

    return cacti_snmp_getnext_request(
        array($hostname, $port, $oid, $version, $community, $timeout_ms, $retries),
        array($auth_proto, $auth_user, $auth_pass, $priv_proto, $priv_pass, $context, $engineid),
        $value_output_format
    );
}

/**
 * Keep the legacy getnext argument list at the compatibility boundary.
 *
 * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: mixed, 6: mixed} $request Host, port, OID, version, community, timeout milliseconds and retries.
 * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: mixed, 6: mixed} $security Authentication protocol/user/passphrase, privacy protocol/passphrase, context and engine ID.
 */
function cacti_snmp_getnext_request(array $request, array $security, mixed $value_output_format): mixed
{
    list($hostname, $port, $oid, $version, $community, $timeout_ms, $retries) = $request;
    list($auth_proto, $auth_user, $auth_pass, $priv_proto, $priv_pass, $context, $engineid) = $security;

    global $config, $snmp_error;

    $max_oids   = 1;
    $snmp_error = '';

    if (!cacti_snmp_options_sanitize($version, $community, $port, $timeout_ms, $retries, $max_oids)) {
        return 'U';
    }

    if (snmp_get_method('getnext', $version, $context, $engineid, $value_output_format) == SNMP_METHOD_PHP) {
        /* make sure snmp* is verbose so we can see what types of data
        we are getting back */
        snmp_set_quick_print(0);

        $timeout_us = (int) ($timeout_ms * 1000);

        if ($version == '1') {
            $snmp_value = @snmpgetnext($hostname . ':' . $port, $community, $oid, $timeout_us, $retries);
        } elseif ($version == '2') {
            $snmp_value = @snmp2_getnext($hostname . ':' . $port, $community, $oid, $timeout_us, $retries);
        } else {
            list($sec_level, $priv_proto) = cacti_snmpv3_security_settings($auth_pass, $auth_proto, $priv_pass, $priv_proto);

            $snmp_value = @snmp3_getnext($hostname . ':' . $port, $auth_user, $sec_level, $auth_proto, $auth_pass, $priv_proto, $priv_pass, $oid, $timeout_us, $retries);
        }

        if ($snmp_value === false) {
            cacti_log("WARNING: SNMP Error:'$snmp_error', Device:'$hostname', OID:'$oid'", false);
            $snmp_value = 'U';
        } else {
            $snmp_value = format_snmp_string($snmp_value, false, $value_output_format);
        }
    } else {
        return cacti_snmp_read_binary_value(
            'getnext',
            array($hostname, $port, $oid, $version, $community, $timeout_ms, $retries),
            array($auth_proto, $auth_user, $auth_pass, $priv_proto, $priv_pass, $context, $engineid),
            $value_output_format
        );
    }

    return $snmp_value;
}

/**
 * Execute binary reads while preserving the get, raw and getnext output policies.
 *
 * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: mixed, 6: mixed} $request Host, port, OID, version, community, timeout milliseconds and retries.
 * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: mixed, 6: mixed} $security Authentication protocol/user/passphrase, privacy protocol/passphrase, context and engine ID.
 * @param mixed $value_output_format Legacy SNMP output mode.
 */
function cacti_snmp_read_binary_value(string $operation, array $request, array $security, mixed $value_output_format): ?string
{
    $raw = $operation === 'get_raw';
    $next = $operation === 'getnext';
    $request[0] = cacti_format_ipv6_colon($request[0]);
    $command = cacti_snmp_read_command(
        $next ? 'path_snmpgetnext' : 'path_snmpget',
        ($raw ? 'fntev' : 'fntevU') . ($value_output_format == SNMP_STRING_OUTPUT_HEX ? 'x' : ''),
        $request,
        $security
    );
    if ($command === null) {
        return null;
    }

    if (isset($_SESSION)) {
        debug_log_insert('data_query', __esc('SNMP Command is: %s', cacti_snmp_command_log_string($command)));
    }

    $snmp_value = implode(' ', cacti_snmp_exec_argv($command));
    if (strpos($snmp_value, 'Timeout') !== false) {
        cacti_log("WARNING: SNMP Error:'Timeout', Device:'{$request[0]}', OID:'{$request[2]}'", false, 'SNMP', POLLER_VERBOSITY_HIGH);
        if (!$next) {
            return 'U';
        }
    }

    return $raw ? $snmp_value : format_snmp_string($snmp_value, false, $value_output_format);
}

/**
 * Build the common get/raw/getnext request without changing output flags.
 *
 * @param string $binary_option Configured executable option, read only after version admission.
 * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: mixed, 6: mixed} $request Host, port, OID, version, community, timeout milliseconds and retries.
 * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: mixed, 6: mixed} $security Authentication protocol/user/passphrase, privacy protocol/passphrase, context and engine ID.
 *
 * @return array<int, string>|null Null retains the unsupported-version return.
 */
function cacti_snmp_read_command($binary_option, $output_options, array $request, array $security): ?array
{
    list($hostname, $port, $oid, $version, $community, $timeout_ms, $retries) = $request;
    /* net snmp want the timeout in seconds */
    $timeout_s = (int) ceil($timeout_ms / 1000);
    if ($version == '1' || $version == '2') {
        $snmp_auth = array('-c', $community);
        if ($version == '2') {
            $version = '2c'; /* ucd/net snmp prefers this over '2' */
        }
    } elseif ($version == '3') {
        $snmp_auth = cacti_get_snmpv3_auth_arguments(...$security);
    }

    if (empty($snmp_auth)) {
        return null;
    }

    return cacti_snmp_build_binary_command(
        read_config_option($binary_option),
        $output_options,
        $snmp_auth,
        $version,
        $timeout_s,
        $retries,
        snmp_format_target($hostname, $port),
        $oid
    );
}

/**
 * Build argument-array options for a binary SNMPv3 request.
 *
 * @param string $auth_proto Authentication protocol key.
 * @param string $auth_user  Authentication user.
 * @param string $auth_pass  Authentication passphrase.
 * @param string $priv_proto Privacy protocol key.
 * @param string $priv_pass  Privacy passphrase.
 * @param string $context    Context name.
 * @param string $engineid   Engine identifier.
 *
 * @return array<int, string>
 */
function cacti_get_snmpv3_auth_arguments($auth_proto, $auth_user, $auth_pass, $priv_proto, $priv_pass, $context, $engineid)
{
    global $snmp_priv_protocols, $snmp_auth_protocols;

    $sec_details = array('-a', $snmp_auth_protocols[$auth_proto] ?? '', '-A', $auth_pass);

    list($sec_level, $priv_proto) = cacti_snmpv3_security_settings($auth_pass, $auth_proto, $priv_pass, $priv_proto);
    if ($sec_level === 'noAuthNoPriv') {
        $sec_details = array();
    }
    if ($sec_level !== 'authPriv') {
        $priv_pass = '';
    } else {
        $priv_proto = $snmp_priv_protocols[$priv_proto] ?? '';
    }

    $arguments = array('-u', $auth_user, '-l', $sec_level);
    $arguments = array_merge($arguments, $sec_details);

    if ($priv_pass != '') {
        $arguments = array_merge($arguments, array('-X', $priv_pass, '-x', $priv_proto));
    }

    if ($context != '') {
        $arguments = array_merge($arguments, array('-n', $context));
    }

    if ($engineid != '') {
        $arguments = array_merge($arguments, array('-e', $engineid));
    }

    return $arguments;
}

/**
 * Build the argument list for a Net-SNMP binary command.
 *
 * @param string         $binary        Configured executable path.
 * @param string         $output_options Net-SNMP output flags.
 * @param array          $auth_arguments Authentication option/value pairs.
 * @param string         $version        SNMP version.
 * @param int            $timeout        Timeout in seconds.
 * @param int            $retries        Retry count.
 * @param string         $target         Host and port argument.
 * @param string         $oid            Requested OID.
 * @param array<int,string> $extra_arguments Additional options before target.
 *
 * @return array<int, string>
 */
function cacti_snmp_build_binary_command($binary, $output_options, array $auth_arguments, $version, $timeout, $retries, $target, $oid, array $extra_arguments = array())
{
    return array_merge(
        array($binary, '-O', $output_options),
        $auth_arguments,
        array('-v', (string) $version, '-t', (string) $timeout, '-r', (string) $retries),
        $extra_arguments,
        array($target, $oid)
    );
}

/**
 * Run a binary SNMP command without passing user-controlled values through a shell.
 *
 * @param array<int, string> $arguments Command and arguments.
 *
 * @return array<int, string> Standard output lines.
 */
function cacti_snmp_exec_argv(array $arguments)
{
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\LegacyCommandOutput::class)) {
        require_once __DIR__ . '/../src/Platform/Infrastructure/Legacy/LegacyComponentAutoloader.php';
        \Kadupul\Platform\Infrastructure\Legacy\LegacyComponentAutoloader::register(dirname(__DIR__));
    }

    return (new \Kadupul\Platform\Infrastructure\Legacy\LegacyCommandOutput())->linesFromArguments($arguments);
}

/**
 * Format an argument list for the existing debug log without executing it.
 *
 * @param array<int, string> $arguments Command and arguments.
 *
 * @return string
 */
function cacti_snmp_command_log_string(array $arguments)
{
    return implode(' ', array_map('cacti_escapeshellarg', $arguments));
}

/**
 * @deprecated Retained for plugins using the legacy shell-string contract.
 *             Core binary callers use cacti_get_snmpv3_auth_arguments().
 */
function cacti_get_snmpv3_auth($auth_proto, $auth_user, $auth_pass, $priv_proto, $priv_pass, $context, $engineid)
{
    $arguments = cacti_get_snmpv3_auth_arguments($auth_proto, $auth_user, $auth_pass, $priv_proto, $priv_pass, $context, $engineid);
    $parts = array();
    foreach ($arguments as $index => $argument) {
        $parts[] = $index % 2 === 0 ? $argument : snmp_escape_string($argument);
    }

    return implode(' ', $parts);
}

function cacti_snmp_timeout_ms($session, $info)
{
    /* phpsnmp\SNMP has two implementations with different units: the
       native extension wrapper (extension.php) extends \SNMP and leaves
       info['timeout'] in microseconds, while the bundled class
       (classSNMP.php) does not extend \SNMP and already converts its own
       info['timeout'] to milliseconds in its constructor */
    if ($session instanceof \SNMP) {
        return round($info['timeout'] / 1000, 0);
    }

    return round($info['timeout'], 0);
}

function cacti_snmp_session_walk(
    $session,
    $oid,
    $dummy = false,
    $max_repetitions = null,
    $non_repeaters = null,
    $value_output_format = SNMP_STRING_OUTPUT_GUESS
) {

    $info = $session->info;
    if (is_array($oid) && cacti_sizeof($oid) == 0) {
        cacti_log('Empty OID!', false);
        return array();
    }

    if (is_array($oid)) {
        foreach ($oid as $index => $o) {
            $oid[$index] = trim($o);
        }
    } else {
        $oid = trim($oid);
    }

    $session->value_output_format = $value_output_format;

    if ($non_repeaters === NULL) {
        $non_repeaters = 0;
    }

    if ($max_repetitions === NULL) {
        $max_repetitions = $session->bulk_walk_size;
    }

    if ($max_repetitions <= 0) {
        $max_repetitions = 10;
    }

    try {
        $out = @$session->walk($oid, false, $max_repetitions, $non_repeaters);
    } catch (Exception $e) {
        $out = false;
    }

    if ($out === false) {
        if ($oid == '.1.3.6.1.2.1.47.1.1.1.1.2' ||
            $oid == '.1.3.6.1.4.1.9.9.68.1.2.2.1.2' ||
            $oid == '.1.3.6.1.4.1.9.9.46.1.6.1.1.5' ||
            $oid == '.1.3.6.1.4.1.9.9.46.1.6.1.1.14' ||
            $oid == '.1.3.6.1.4.1.9.9.23.1.2.1.1.6') {
            /* do nothing */
        } elseif ($session->getErrno() == SNMP::ERRNO_TIMEOUT) {
            cacti_log('WARNING: SNMP Error:\'Timeout (' . cacti_snmp_timeout_ms($session, $info) . " ms)', Device:'" . $info['hostname'] . "', OID:'$oid'", false, 'SNMP', POLLER_VERBOSITY_HIGH);
        }

        return array();
    }

    if (cacti_sizeof($out)) {
        foreach ($out as $oid => $value) {
            if (is_array($value)) {
                foreach ($value as $index => $sval) {
                    $out[$oid][$index] = format_snmp_string($sval, false, $value_output_format);
                }
            } elseif ($out[$oid] !== false) {
                $out[$oid] = format_snmp_string($value, false, $value_output_format);
            }
        }
    } else {
        $out = format_snmp_string($oid, false, $value_output_format);
    }

    return $out;
}

function cacti_snmp_session_get($session, $oid, $strip_alpha = false)
{
    $info = $session->info;

    if (is_array($oid) && cacti_sizeof($oid) == 0) {
        cacti_log('Empty OID!', false);
        return array();
    } elseif (is_array($oid)) {
        foreach ($oid as $index => $o) {
            $oid[$index] = trim($o);
        }
    } else {
        $oid = trim($oid);
    }

    try {
        $out = @$session->get($oid);
    } catch (Exception $e) {
        $out = false;
    }

    if (is_array($oid)) {
        $oid = implode(',', $oid);
    }

    if ($out === false) {
        if ($session->getErrno() == SNMP::ERRNO_TIMEOUT) {
            cacti_log('WARNING: SNMP Error:\'Timeout (' . cacti_snmp_timeout_ms($session, $info) . " ms)', Device:'" . $info['hostname'] . "', OID:'$oid'", false, 'SNMP', POLLER_VERBOSITY_HIGH);
        }

        return false;
    }

    if (is_array($out)) {
        foreach ($out as $oid => $value) {
            $out[$oid] = format_snmp_string($value, false, SNMP_STRING_OUTPUT_GUESS, $strip_alpha);
        }
    } else {
        $out = format_snmp_string($out, false, SNMP_STRING_OUTPUT_GUESS, $strip_alpha);
    }

    return $out;
}

function cacti_snmp_session_getnext($session, $oid)
{
    $info = $session->info;
    if (is_array($oid) && cacti_sizeof($oid) == 0) {
        cacti_log('Empty OID!', false);
        return array();
    }

    if (is_array($oid)) {
        foreach ($oid as $index => $o) {
            $oid[$index] = trim($o);
        }
    } else {
        $oid = trim($oid);
    }

    try {
        $out = @$session->getnext($oid);
    } catch (Exception $e) {
        $out = false;
    }

    if (is_array($oid)) {
        $oid = implode(',', $oid);
    } elseif ($out === false) {
        if ($session->getErrno() == SNMP::ERRNO_TIMEOUT) {
            cacti_log('WARNING: SNMP Error:\'Timeout (' . cacti_snmp_timeout_ms($session, $info) . " ms)', Device:'" . $info['hostname'] . "', OID:'$oid'", false, 'SNMP', POLLER_VERBOSITY_HIGH);
        }

        return false;
    }

    if (is_array($out)) {
        foreach ($out as $oid => $value) {
            $out[$oid] = format_snmp_string($value, false);
        }
    } else {
        $out = format_snmp_string($out, false);
    }

    return $out;
}

function cacti_snmp_validate_oid($oid)
{
    $oid = ltrim($oid, '.');

    if ($oid === '') {
        return false;
    }

    $validate = array_map('is_numeric', explode('.', $oid));

    return !in_array(false, $validate, true);
}

function cacti_snmp_walk(
    $hostname,
    $community,
    $oid,
    $version,
    $auth_user = '',
    $auth_pass = '',
    $auth_proto = '',
    $priv_pass = '',
    $priv_proto = '',
    $context = '',
    $port = 161,
    $timeout_ms = 500,
    $retries = 0,
    $bulk_walk_size = 10,
    $environ = 'SNMP',
    $engineid = '',
    $value_output_format = SNMP_STRING_OUTPUT_GUESS
) {

    global $config, $banned_snmp_strings, $snmp_error;

    $snmp_error        = '';
    $snmp_oid_included = true;
    $snmp_auth	       = '';
    $snmp_array        = array();
    $temp_array        = array();

    if (!cacti_snmp_options_sanitize($version, $community, $port, $timeout_ms, $retries, $bulk_walk_size)) {
        return array();
    }

    $path_snmpbulkwalk = read_config_option('path_snmpbulkwalk');

    if (snmp_get_method('walk', $version, $context, $engineid, $value_output_format) == SNMP_METHOD_PHP) {
        /* make sure snmp* is verbose so we can see what types of data
        we are getting back */

        $timeout_us = (int) ($timeout_ms * 1000);

        /* force php to return numeric oid's */
        cacti_oid_numeric_format();

        if (function_exists('snmprealwalk')) {
            $snmp_oid_included = false;
        }

        snmp_set_quick_print(0);

        if ($version == '1') {
            $temp_array = snmprealwalk($hostname . ':' . $port, $community, $oid, $timeout_us, $retries);
        } elseif ($version == 2) {
            $temp_array = snmp2_real_walk($hostname . ':' . $port, $community, $oid, $timeout_us, $retries);
        } else {
            if ($priv_proto == '[None]' || $priv_pass == '') {
                if ($auth_pass == '') {
                    $sec_level   = 'noAuthNoPriv';
                } else {
                    $sec_level   = 'authNoPriv';
                }
                $priv_proto = '';
            } else {
                $sec_level = 'authPriv';
            }

            $temp_array = @snmp3_real_walk($hostname . ':' . $port, $auth_user, $sec_level, $auth_proto, $auth_pass, $priv_proto, $priv_pass, $oid, $timeout_us, $retries);
        }

        /* check for bad entries */
        if ($temp_array !== false && cacti_sizeof($temp_array)) {
            foreach ($temp_array as $key => $value) {
                foreach ($banned_snmp_strings as $item) {
                    if (strstr($value, $item) != '') {
                        unset($temp_array[$key]);
                        continue 2;
                    }
                }
            }

            $o = 0;
            for (reset($temp_array); $i = key($temp_array); next($temp_array)) {
                if ($temp_array[$i] != 'NULL') {
                    $snmp_array[$o]['oid'] = preg_replace('/^\./', '', $i);
                    $snmp_array[$o]['value'] = format_snmp_string($temp_array[$i], $snmp_oid_included, $value_output_format);
                }
                $o++;
            }
        }
    } else {
        /* ucd/net snmp want the timeout in seconds */
        $timeout_s = (int) ceil($timeout_ms / 1000);
        $hostname = cacti_format_ipv6_colon($hostname);

        if ($version == '1' || $version == '2') {
            $snmp_auth = array('-c', $community);
            if ($version == '2') {
                $version = '2c'; /* ucd/net snmp prefers this over '2' */
            }
        } elseif ($version == '3') {
            $snmp_auth = cacti_get_snmpv3_auth_arguments($auth_proto, $auth_user, $auth_pass, $priv_proto, $priv_pass, $context, $engineid);
        }

        if (read_config_option('oid_increasing_check_disable') == 'on') {
            $oidCheck = '-Cc';
        } else {
            $oidCheck = '';
        }

        if (file_exists($path_snmpbulkwalk) && ($version > 1) && ($bulk_walk_size > 1)) {
            $extra_arguments = array('-Cr' . $bulk_walk_size);
            if ($oidCheck != '') {
                $extra_arguments[] = $oidCheck;
            }

            $command = cacti_snmp_build_binary_command(
                $path_snmpbulkwalk,
                'QnU' . ($value_output_format == SNMP_STRING_OUTPUT_HEX ? 'x' : ''),
                $snmp_auth,
                $version,
                $timeout_s,
                $retries,
                snmp_format_target($hostname, $port),
                $oid,
                $extra_arguments
            );

            if (isset($_SESSION)) {
                debug_log_insert('data_query', __esc('SNMP Command is: %s', cacti_snmp_command_log_string($command)));
            }

            $temp_array = cacti_snmp_exec_argv($command);
        } else {
            $extra_arguments = array();
            if ($oidCheck != '') {
                $extra_arguments[] = $oidCheck;
            }

            $command = cacti_snmp_build_binary_command(
                read_config_option('path_snmpwalk'),
                'QnU' . ($value_output_format == SNMP_STRING_OUTPUT_HEX ? 'x' : ''),
                $snmp_auth,
                $version,
                $timeout_s,
                $retries,
                snmp_format_target($hostname, $port),
                $oid,
                $extra_arguments
            );

            if (isset($_SESSION)) {
                debug_log_insert('data_query', __esc('SNMP Command is: %s', cacti_snmp_command_log_string($command)));
            }

            $temp_array = cacti_snmp_exec_argv($command);
        }

        if (strpos(implode(' ', $temp_array), 'Timeout') !== false) {
            cacti_log("WARNING: SNMP Error:'Timeout', Device:'$hostname', OID:'$oid'", false, 'SNMP', POLLER_VERBOSITY_HIGH);
        }

        if (strpos(implode(' ', $temp_array), '(tooBig)') !== false) {
            cacti_log("WARNING: SNMP Error:'Error in packet.  Response message would have been too large.', Device:'$hostname', OID:'$oid'", false, 'SNMP', POLLER_VERBOSITY_HIGH);
        }

        /* check for bad entries */
        if (is_array($temp_array) && cacti_sizeof($temp_array)) {
            foreach ($temp_array as $key => $value) {
                foreach ($banned_snmp_strings as $item) {
                    if (strstr($value, $item) != '') {
                        unset($temp_array[$key]);
                        continue 2;
                    }
                }
            }

            /**
             * Using this technique to catch multi-line
             * snmpwalk responses from net-snmp.  This happens
             * usually on sysDescr on Cisco devices.
             */
            $i = 0;

            foreach ($temp_array as $index => $value) {
                if (preg_match('/(.*) =.*/', $value)) {
                    $parts   = explode('=', $value, 2);
                    $t_oid   = trim($parts[0]);
                    $t_value = $parts[1];

                    if (!cacti_snmp_validate_oid($t_oid)) {
                        cacti_log(sprintf('WARNING: SNMP Agent exploit attempted on SNMP agent from host ip: %s with oid: %s', $hostname, $t_oid), false, 'SECURITY');
                        continue;
                    }

                    $snmp_array[$i]['oid']   = $t_oid;
                    $snmp_array[$i]['value'] = $t_value;
                    $i++;
                } else {
                    $snmp_array[$i - 1]['value'] .= $value;
                }
            }
        }
    }

    /**
     * replay the array to escape value data in case of a multi-line exploit
     */
    if (cacti_sizeof($snmp_array)) {
        foreach ($snmp_array as $index => $data) {
            $snmp_array[$index]['value'] = format_snmp_string($data['value'], false, $value_output_format);
        }
    }

    return $snmp_array;
}

function format_snmp_string($string, $snmp_oid_included, $value_output_format = SNMP_STRING_OUTPUT_GUESS, $strip_alpha = false)
{
    global $banned_snmp_strings;

    if ($string === null) {
        return '';
    }

    $string = preg_replace(REGEXP_SNMP_TRIM, '', trim($string));

    if ($snmp_oid_included) {
        /* strip off all leading junk (the oid and stuff) */
        $string_array = explode('=', $string, 2);

        if (cacti_sizeof($string_array) == 1) {
            /* trim excess first */
            $string = trim($string);
        } elseif ((substr($string, 0, 1) == '.') || (strpos($string, '::') !== false)) {
            /* drop the OID from the array */
            array_shift($string_array);
            $string = trim(implode('=', $string_array));
        } else {
            $string = trim(implode('=', $string_array));
        }
    } else {
        $string = trim($string);
    }

    /* remove quotes and extraneous data */
    $string = trim($string, " \n\r\v\"'");

    /* return the easiest value */
    if ($string == '') {
        return $string;
    }

    /* now check for the second most obvious */
    if (is_numeric($string)) {
        return $string;
    }

    /* remove ALL quotes, and other special delimiters */
    $string = str_replace(array('"', "'", '>', '<', "\\", "\n", "\r"), '', $string);

    /* account for invalid MIB files */
    if (strpos($string, 'Wrong Type') !== false) {
        $string = strrev($string);
        if ($position = strpos($string, ':')) {
            $string = trim(strrev(substr($string, 0, $position)));
        } else {
            $string = trim(strrev($string));
        }
    }

    /* Remove invalid chars, if the string output is to be numeric */
    if ($strip_alpha && $value_output_format == SNMP_STRING_OUTPUT_GUESS) {
        $string = trim(str_ireplace('hex:', '', $string));
        $len    = strlen($string);
        $pos    = $len - 1;

        while ($pos > 0) {
            $value = ord($string[$pos]);

            if (($value < 48 || $value > 57) && $value != 32) {
                $string[$pos] = ' ';
            } else {
                break;
            }

            $pos--;
        }

        $string = trim($string);
        $len    = strlen($string);
        $pos    = 0;

        while ($pos < $len) {
            $value = ord($string[$pos]);

            if (($value < 48 || $value > 57) && $value != 32) {
                $string[$pos] = ' ';
            } else {
                break;
            }

            $pos++;
        }

        $string = trim($string);

        if ($string == '') {
            return 'U';
        }
    }

    /* Remove non-printable characters, allow UTF-8 */
    if ($value_output_format == SNMP_STRING_OUTPUT_GUESS) {
        $string = preg_replace('/[^[:print:]\r\n]/', '', $string);
    }

    /* Trim the string of trailing and leading spaces */
    $string = trim($string);

    /* convert hex strings to numeric values */
    if (is_hex_string($string)) {
        /* the is_hex_string() function will remove the hex:
         * and hex-string: from the passed value
         */
        $output = '';
        $parts  = explode(' ', $string);

        if (cacti_sizeof($parts) == 4) {
            $possible_ip = true;

            $ip_address = '';

            /* convert the hex string into an ascii string */
            foreach ($parts as $part) {
                if ($possible_ip && hexdec($part) >= 0 && hexdec($part) <= 255) {
                    $ip_address .= ($ip_address != '' ? '.' : '') . hexdec($part);
                } else {
                    $possible_ip = false;
                }

                $output .= chr(hexdec($part));
            }

            if ($possible_ip && is_ipaddress($ip_address)) {
                $string = $ip_address;
            } else {
                $string = $output;
            }
            /* hex string is mac-address */
        } elseif (cacti_sizeof($parts) == 6) {
            $possible_ip = false;

            /* convert the hex string into an ascii string */
            foreach ($parts as $part) {
                $output .= ($output != '' ? ':' : '');
                if ($part == '00') {
                    $output .= '00';
                } else {
                    $output .= str_pad($part, 2, '0', STR_PAD_LEFT);
                }
            }

            if (is_numeric($output)) {
                $string = number_format($output, 0, '', '');
            } else {
                $string = $output;
            }
        } else {
            $possible_ip = false;
        }
    } elseif (substr(strtolower($string), 0, 4) == 'hex:') {
        /* strip off the 'Hex:' */
        $string = trim(str_ireplace('hex:', '', $string));

        /* normalize some forms */
        $output = '';
        $string = str_replace(array(' ', '-', '.'), ':', $string);
        $parts  = explode(':', $string);

        if (!is_mac_address($string)) {
            /* convert the hex string into an ascii string */
            foreach ($parts as $part) {
                $output .= ($output != '' ? ':' : '');
                if ($part == '00') {
                    $output .= '00';
                } else {
                    $output .= str_pad($part, 2, '0', STR_PAD_LEFT);
                }
            }

            if (is_numeric($output)) {
                $string = number_format($output, 0, '', '');
            } else {
                $string = $output;
            }
        }
    } elseif (preg_match('/Timeticks:\s\((\d+)\)\s/', $string, $matches)) {
        $string = $matches[1];
    }

    foreach ($banned_snmp_strings as $item) {
        if (strpos($string, $item) !== false) {
            $string = '';
            break;
        }
    }

    return $string;
}

/**
 * snmp_format_target - format one host:port argument for binary SNMP commands,
 * forcing udp6: transport for IPv6 to prevent DNS ambiguity.
 *
 * @param string $hostname - The target hostname or IP
 * @param int    $port     - The SNMP port
 *
 * @return string The formatted target argument
 */
function snmp_format_target($hostname, $port)
{
    if (strpos($hostname, ':') !== false) {
        /* IPv6: force udp6: transport and bracket-encapsulate */
        $clean = str_replace(array('[', ']'), '', $hostname);

        return 'udp6:[' . $clean . ']:' . $port;
    }

    return $hostname . ':' . $port;
}

function snmp_escape_string($string)
{
    global $config;

    if (!defined('SNMP_ESCAPE_CHARACTER')) {
        define('SNMP_ESCAPE_CHARACTER', '"');
    }

    if ($config['cacti_server_os'] == 'win32') {
        /* SECURITY: Always wrap the string in quotes on Windows,
         * preventing command chaining via &, |, or ^ operators. */
        $string = str_replace(SNMP_ESCAPE_CHARACTER, "\\" . SNMP_ESCAPE_CHARACTER, $string);

        return SNMP_ESCAPE_CHARACTER . $string . SNMP_ESCAPE_CHARACTER;
    }

    return cacti_escapeshellarg($string);
}

function snmp_get_method(
    $type = 'walk',
    $version = 1,
    $context = '',
    $engineid = '',
    $value_output_format = SNMP_STRING_OUTPUT_GUESS
) {

    global $config;

    if (isset($config['php_snmp_support']) && !$config['php_snmp_support']) {
        return SNMP_METHOD_BINARY;
    } elseif ($value_output_format == SNMP_STRING_OUTPUT_HEX) {
        return SNMP_METHOD_BINARY;
    } elseif ($version == 3) {
        return SNMP_METHOD_BINARY;
    } elseif ($type == 'walk' && file_exists(read_config_option('path_snmpbulkwalk'))) {
        return SNMP_METHOD_BINARY;
    } elseif (function_exists('snmpget') && $version == 1) {
        return SNMP_METHOD_PHP;
    } elseif (function_exists('snmp2_get') && $version == 2) {
        return SNMP_METHOD_PHP;
    } else {
        return SNMP_METHOD_BINARY;
    }
}

function cacti_snmp_options_sanitize($version, $community, &$port, &$timeout, &$retries, &$max_oids)
{
    /* determine default retries */
    if ($retries == 0 || !is_numeric($retries)) {
        $retries = read_config_option('snmp_retries');

        if ($retries == '') {
            $retries = 3;
        }
    }

    /* determine default max_oids */
    if ($max_oids == 0 || !is_numeric($max_oids)) {
        $max_oids = read_config_option('max_get_size');

        if ($max_oids == '') {
            $max_oids = 10;
        }
    }

    /* determine default port */
    if (empty($port)) {
        $port = '161';
    }

    /* do not attempt to poll invalid combinations */
    if (($version == 0) || (!is_numeric($version)) ||
        (!is_numeric($max_oids)) ||
        (!is_numeric($port)) ||
        (!is_numeric($retries)) ||
        (!is_numeric($timeout)) ||
        (($community == '') && ($version != 3))
    ) {

        return false;
    }

    return true;
}
