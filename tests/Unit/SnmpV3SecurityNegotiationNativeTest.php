<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\SnmpV3SecurityNegotiationNative;

require_once dirname(__DIR__) . '/Helpers/ChildProcessCoverage.php';

function securitySettings(array $scenario): array
{
    $root = dirname(__DIR__, 2);
    $program = <<<'CHILD'
        $root = $argv[1];
        $scenario = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
        require $root . '/include/global_constants.php';
        function read_config_option($name) { $GLOBALS['configReads'][] = $name; return $name === 'path_snmpget' ? '/native/bin/snmpget' : ''; }
        function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
        $config = array('php_snmp_support' => false, 'include_path' => $root . '/include');
        require $root . '/lib/snmp.php';
        $snmp_auth_protocols = array('SHA' => 'SHA');
        $snmp_priv_protocols = array('AES' => 'AES');
        list($auth_pass, $auth_proto, $priv_pass, $priv_proto) = $scenario;
        $session = cacti_snmp_session('2001:db8::1', 'community', 3, 'user', $auth_pass, $auth_proto,
            $priv_pass, $priv_proto, 'context', 'engine', 1161, 1501, 2);
        if (!$session instanceof \phpsnmp\SNMP) { throw new RuntimeException('Missing bundled SNMP session'); }
        $state = array();
        $reflection = new ReflectionClass($session);
        foreach (array('sec_level', 'auth_proto', 'auth_pass', 'priv_proto', 'priv_pass', 'contextName', 'contextEngineID') as $property) {
            $state[$property] = $reflection->getProperty($property)->getValue($session);
        }
        $arguments = cacti_get_snmpv3_auth_arguments($auth_proto, 'user', $auth_pass, $priv_proto, $priv_pass, 'context', 'engine');
        $command = cacti_snmp_read_command('path_snmpget', 'fntevU',
            array('2001:db8::1', 1161, '.1.3.6.1', 3, 'community', 1501, 2),
            array($auth_proto, 'user', $auth_pass, $priv_proto, $priv_pass, 'context', 'engine'));
        $GLOBALS['nativeChildCoverageMarkers'] = array('native-snmp-session-security-observed', 'native-snmp-binary-security-observed');
        print json_encode(array('session' => $state, 'info' => $session->info, 'arguments' => $arguments, 'command' => $command), JSON_THROW_ON_ERROR);
        CHILD;
    $registration = \child_coverage_registration(
        __FILE__,
        'snmp-security',
        $scenario,
        array('native-snmp-session-security-observed', 'native-snmp-binary-security-observed'),
        array('lib/snmp.php'),
        array('lib/snmp.php', 'include/global_constants.php', 'include/vendor/phpsnmp/classSNMP.php')
    );
    $registration['collectorPrelude'] = 'define("SNMP_SECURITY_NATIVE_TEST_COVERAGE", true);';
    $command = \child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, json_encode($scenario, JSON_THROW_ON_ERROR)), $directory, $registration);
    try {
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch native SNMP security probe.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        expect($stderr)->toBe('')->and($status)->toBe(0);
        \child_coverage_collect($directory);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if ($directory !== null && is_dir($directory)) {
            foreach (glob($directory . '/*.coverage*') ?: array() as $ownedReport) {
                unlink($ownedReport);
            }
            rmdir($directory);
            unset($GLOBALS['child_coverage_registrations'][$directory]);
        }
    }
}

function rejectedRequests(array $scenario): array
{
    $root = dirname(__DIR__, 2);
    $program = <<<'CHILD'
        $root = $argv[1];
        $scenario = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
        require $root . '/include/global_constants.php';
        require $root . '/tests/Helpers/PhpSource.php';
        $functions = file_get_contents($root . '/lib/functions.php');
        if (!is_string($functions)) { throw new RuntimeException('Missing actual IPv6 source'); }
        eval(test_php_function_source($functions, 'cacti_format_ipv6_colon')); // nosemgrep: php.lang.security.eval-use.eval-use
        function read_config_option($name) { $GLOBALS['configReads'][] = $name; return ''; }
        $config = array('php_snmp_support' => false, 'include_path' => $root . '/include');
        require $root . '/lib/snmp.php';
        $GLOBALS['configReads'] = array();
        $results = array();
        foreach (array('cacti_snmp_get', 'cacti_snmp_get_raw', 'cacti_snmp_getnext') as $method) {
            $results[] = $method('192.0.2.1', 'community', '.1.3.6.1', $scenario[0], timeout_ms: $scenario[1], retries: 2);
        }
        $GLOBALS['nativeChildCoverageMarkers'] = array('native-snmp-request-rejection-observed');
        print json_encode(array('results' => $results, 'reads' => $GLOBALS['configReads']), JSON_THROW_ON_ERROR);
        CHILD;
    $registration = \child_coverage_registration(
        __FILE__,
        'snmp-rejection',
        $scenario,
        array('native-snmp-request-rejection-observed'),
        array('lib/snmp.php'),
        array('lib/snmp.php', 'tests/Helpers/PhpSource.php', 'include/global_constants.php', 'include/vendor/phpsnmp/classSNMP.php')
    );
    $registration['collectorPrelude'] = 'define("SNMP_SECURITY_NATIVE_TEST_COVERAGE", true);';
    $command = \child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, json_encode($scenario, JSON_THROW_ON_ERROR)), $directory, $registration);
    try {
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch native SNMP rejection probe.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        expect($stderr)->toBe('')->and($status)->toBe(0);
        \child_coverage_collect($directory);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if ($directory !== null && is_dir($directory)) {
            foreach (glob($directory . '/*.coverage*') ?: array() as $ownedReport) {
                unlink($ownedReport);
            }
            rmdir($directory);
            unset($GLOBALS['child_coverage_registrations'][$directory]);
        }
    }
}

test('native SNMP rejected requests retain return values without binary configuration reads', function (array $scenario, mixed $expected) {
    expect(rejectedRequests($scenario))->toBe(array('results' => array_fill(0, 3, $expected), 'reads' => array()));
})->with(array(
    'unsupported numeric version' => array(array(4, 500), null),
    'unsupported numeric version with zero timeout' => array(array(4, 0), null),
    'zero version' => array(array(0, 500), 'U'),
    'missing timeout' => array(array(2, null), 'U'),
    'false timeout' => array(array(2, false), 'U'),
    'array timeout' => array(array(2, array()), 'U'),
    'malformed scalar timeout' => array(array(2, 'invalid'), 'U'),
));

test('native SNMPv3 session and binary options preserve security negotiation', function (array $scenario, string $level, mixed $protocol, array $authentication) {
    $result = securitySettings($scenario);
    expect($result['session'])->toBe(array('sec_level' => $level, 'auth_proto' => $scenario[1], 'auth_pass' => $scenario[0],
        'priv_proto' => $protocol, 'priv_pass' => $scenario[2], 'contextName' => 'context', 'contextEngineID' => 'engine'))
        ->and($result['info']['timeout'])->toBe(1501)->and($result['info']['port'])->toBe('1161')
        ->and($result['arguments'])->toBe(array('-u', 'user', '-l', $level, ...$authentication, '-n', 'context', '-e', 'engine'))
        ->and($result['command'])->toBe(array('/native/bin/snmpget', '-O', 'fntevU', ...$result['arguments'],
            '-v', '3', '-t', '2', '-r', '2', 'udp6:[2001:db8::1]:1161', '.1.3.6.1'));
})->with(array(
    'empty authentication' => array(array('', 'SHA', '', 'AES'), 'noAuthNoPriv', '', array()),
    'explicitly absent authentication' => array(array('auth', '[None]', 'priv', '[None]'), 'noAuthNoPriv', '', array()),
    'authentication without privacy' => array(array('auth', 'SHA', '', 'AES'), 'authNoPriv', '', array('-a', 'SHA', '-A', 'auth')),
    'unused privacy passphrase remains on native boundary' => array(array('auth', 'SHA', 'priv', '[None]'), 'authNoPriv', '', array('-a', 'SHA', '-A', 'auth')),
    'authentication and privacy' => array(array('auth', 'SHA', 'priv', 'AES'), 'authPriv', 'AES', array('-a', 'SHA', '-A', 'auth', '-X', 'priv', '-x', 'AES')),
    'privacy retains legacy empty authentication' => array(array('', '[None]', 'priv', 'AES'), 'authPriv', 'AES', array('-a', '', '-A', '', '-X', 'priv', '-x', 'AES')),
    'unknown protocols preserve native values and empty binary mappings' => array(array('auth', 'unknown', 'priv', 'unknown'), 'authPriv', 'unknown', array('-a', '', '-A', 'auth', '-X', 'priv', '-x', '')),
    'null authentication preserves native omission semantics' => array(array(null, 'SHA', null, 'AES'), 'noAuthNoPriv', '', array()),
    'false authentication preserves native omission semantics' => array(array(false, 'SHA', false, 'AES'), 'noAuthNoPriv', '', array()),
    'zero authentication preserves legacy loose comparison' => array(array(0, 'SHA', '', 'AES'), 'authNoPriv', '', array('-a', 'SHA', '-A', 0)),
));
