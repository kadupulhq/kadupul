<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$snmpSource = file_get_contents(__DIR__ . '/../../lib/snmp.php');
$pingSource = file_get_contents(__DIR__ . '/../../lib/ping.php');
$funcSource = file_get_contents(__DIR__ . '/../../lib/functions.php');
require_once __DIR__ . '/../Helpers/PhpSource.php';
if (!is_string($snmpSource) || !is_string($pingSource) || !is_string($funcSource)) {
    throw new RuntimeException('Unable to read the actual IPv6 production sources.');
}

test('cacti_snmp_session brackets IPv6 before port append', function () use ($snmpSource) {
    $start = strpos($snmpSource, 'function cacti_snmp_session(');
    $body = substr($snmpSource, $start, 1500);
    expect($body)->toContain("snmp_hostname = '[' . \$snmp_hostname . ']'");
});

test('snmp_format_target function exists', function () use ($snmpSource) {
    expect($snmpSource)->toContain('function snmp_format_target($hostname, $port)');
});

test('snmp_format_target forces udp6 for IPv6', function () use ($snmpSource) {
    $start = strpos($snmpSource, 'function snmp_format_target(');
    $body = substr($snmpSource, $start, 500);
    expect($body)->toContain("'udp6:[' . \$clean . ']:'");
});

test('binary SNMP source retains target normalization through the shared request builder', function () use ($snmpSource) {
    foreach (array('cacti_snmp_get', 'cacti_snmp_get_raw', 'cacti_snmp_getnext') as $caller) {
        expect(test_php_function_source($snmpSource, $caller))->toContain('cacti_snmp_read_command(');
    }
    foreach (array('cacti_snmp_read_command', 'cacti_snmp_walk') as $builder) {
        expect(test_php_function_source($snmpSource, $builder))->toContain('snmp_format_target($hostname, $port)');
    }
});

test('ping.php is_ipaddress strips zone index', function () use ($pingSource) {
    $start = strpos($pingSource, 'function is_ipaddress(');
    $body = substr($pingSource, $start, 500);
    expect($body)->toContain("explode('%', \$clean_ip, 2)");
});

test('functions.php is_ipaddress strips zone index', function () use ($funcSource) {
    $start = strpos($funcSource, 'function is_ipaddress(');
    $body = substr($funcSource, $start, 500);
    expect($body)->toContain("explode('%', \$clean_ip, 2)");
});
