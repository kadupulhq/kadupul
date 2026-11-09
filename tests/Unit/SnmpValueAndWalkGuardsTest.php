<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * #1030 Hex-STRING values reach the 'hex-string:' branch; #1031 a walk without
 * an OID is skipped; #1056 a host already marked down returns no session.
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

function snmp_guard_run(string $file, array $functions, string $stubs, string $body): array
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    expect($source)->not->toBeFalse();
    $program = 'namespace SnmpGuardProbe;error_reporting(E_ALL);'
        . 'set_error_handler(function($n,$m){throw new \ErrorException($m);});' . $stubs;
    foreach ($functions as $function) {
        $program .= test_php_function_source($source, $function);
    }
    $result = test_php_run($program . $body);
    expect($result['status'])->toBe(0, $result['err'])->and($result['err'])->toBe('');

    return json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
}

test('a Hex-STRING value is read through the hex-string prefix', function (string $value, bool $hex, string $result) {
    $state = snmp_guard_run(
        'lib/functions.php',
        ['is_hex_string'],
        'function cacti_sizeof($a){return is_countable($a)?count($a):0;}',
        '$v=' . var_export($value, true) . ';$r=is_hex_string($v);echo json_encode([$r,$v]);'
    );

    expect($state)->toBe([$hex, $result]);
})->with([
    'Hex-STRING with prefix' => ['Hex-STRING: 00 1A 2B', true, '00 1A 2B'],
    'lower case' => ['hex-string: de ad', true, 'de ad'],
    'Hex- after String: stripping' => ['Hex- 0A 0B', true, '0A 0B'],
    'not hex' => ['Hex-STRING: zz yy', false, 'Hex-STRING: zz yy'],
    'single byte' => ['Hex-STRING: 0A', false, 'Hex-STRING: 0A'],
    'plain string' => ['STRING: 00 1A', false, 'STRING: 00 1A'],
]);

test('a walk without an OID is skipped before any SNMP request', function (string $oid) {
    $state = snmp_guard_run(
        'lib/snmp.php',
        ['cacti_snmp_walk'],
        'const SNMP_STRING_OUTPUT_GUESS=1;const POLLER_VERBOSITY_HIGH=4;$calls=[];'
        . 'function cacti_snmp_options_sanitize(...$a){return true;}'
        . 'function cacti_log($m,$o=false,$e="",$l=0){$GLOBALS["calls"][]=["log",$m];}'
        . 'function read_config_option($n){$GLOBALS["calls"][]=["config",$n];return "";}'
        . 'function snmp_get_method(...$a){$GLOBALS["calls"][]=["method"];return 1;}',
        '$r=cacti_snmp_walk("router1","public",' . var_export($oid, true) . ',"2");echo json_encode([$r,$GLOBALS["calls"]]);'
    );

    expect($state)->toBe([[], [['log', "WARNING: SNMP walk requested without an OID, Device:'router1'"]]]);
})->with(['empty' => [''], 'spaces' => ['  ']]);

test('a host already marked down returns no session and is not reopened', function () {
    $state = snmp_guard_run(
        'cmd.php',
        ['open_snmp_session', 'snmp_mark_host_down'],
        '$sessions=[];$downhosts=[];$opens=0;'
        . 'function read_config_option($n){return 10;}'
        . 'function cacti_snmp_session(...$a){$GLOBALS["opens"]++;return $GLOBALS["opens"]===1?false:"session";}',
        '$item=["snmp_version"=>2,"snmp_port"=>161,"hostname"=>"h","snmp_community"=>"c","snmp_username"=>"","snmp_password"=>"",'
        . '"snmp_auth_protocol"=>"","snmp_priv_passphrase"=>"","snmp_priv_protocol"=>"","snmp_context"=>"","snmp_engine_id"=>"","snmp_timeout"=>500];'
        . '$first=open_snmp_session(5,$item);$second=open_snmp_session(5,$item);'
        . '$other=$item;$other["snmp_port"]=1161;snmp_mark_host_down(6,$other);$marked=open_snmp_session(6,$other);'
        . 'echo json_encode([$first,$second,$marked,$GLOBALS["opens"]]);'
    );

    expect($state)->toBe([false, false, false, 1]);
});
