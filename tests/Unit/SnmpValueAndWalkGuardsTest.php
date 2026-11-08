<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/*
 * #1030 Hex-STRING values reach the 'hex-string:' branch; #1031 a walk without
 * an OID is skipped; #1056 a host already marked down returns no session.
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

function snmp_guard_run($file, $functions, $stubs, $body) {
	$source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
	expect($source)->toBeString();
	$program = 'namespace SnmpGuardProbe;error_reporting(E_ALL);'
		. 'set_error_handler(function($n,$m){throw new \ErrorException($m);});' . $stubs;
	foreach ($functions as $function) {
		$program .= test_php_function_source($source, $function);
	}
	$process = proc_open(array(PHP_BINARY, '-r', $program . $body), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0, $err . $out)->and($err)->toBe('');

	return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

test('a Hex-STRING value is read through the hex-string prefix', function ($value, $hex, $result) {
	$state = snmp_guard_run('lib/functions.php', array('is_hex_string'), 'function cacti_sizeof($a){return is_countable($a)?count($a):0;}',
		'$v=' . var_export($value, true) . ';$r=is_hex_string($v);echo json_encode(array($r,$v));');

	expect($state)->toBe(array($hex, $result));
})->with(array(
	'Hex-STRING with prefix'       => array('Hex-STRING: 00 1A 2B', true, '00 1A 2B'),
	'lower case'                   => array('hex-string: de ad', true, 'de ad'),
	'Hex- after String: stripping' => array('Hex- 0A 0B', true, '0A 0B'),
	'not hex'                      => array('Hex-STRING: zz yy', false, 'Hex-STRING: zz yy'),
	'single byte'                  => array('Hex-STRING: 0A', false, 'Hex-STRING: 0A'),
	'plain string'                 => array('STRING: 00 1A', false, 'STRING: 00 1A'),
));

test('a walk without an OID is skipped before any SNMP request', function ($oid) {
	$state = snmp_guard_run('lib/snmp.php', array('cacti_snmp_walk'),
		'const SNMP_STRING_OUTPUT_GUESS=1;const POLLER_VERBOSITY_HIGH=4;$calls=array();'
		. 'function cacti_snmp_options_sanitize(...$a){return true;}'
		. 'function cacti_log($m,$o=false,$e="",$l=0){$GLOBALS["calls"][]=array("log",$m);}'
		. 'function read_config_option($n){$GLOBALS["calls"][]=array("config",$n);return "";}'
		. 'function snmp_get_method(...$a){$GLOBALS["calls"][]=array("method");return 1;}',
		'$r=cacti_snmp_walk("router1","public",' . var_export($oid, true) . ',"2");echo json_encode(array($r,$GLOBALS["calls"]));');

	expect($state)->toBe(array(array(), array(array('log', "WARNING: SNMP walk requested without an OID, Device:'router1'"))));
})->with(array('empty' => array(''), 'spaces' => array('  ')));

test('a host already marked down returns no session and is not reopened', function () {
	$state = snmp_guard_run('cmd.php', array('open_snmp_session', 'snmp_mark_host_down'),
		'$sessions=array();$downhosts=array();$opens=0;'
		. 'function read_config_option($n){return 10;}'
		. 'function cacti_snmp_session(...$a){$GLOBALS["opens"]++;return $GLOBALS["opens"]===1?false:"session";}',
		'$item=array("snmp_version"=>2,"snmp_port"=>161,"hostname"=>"h","snmp_community"=>"c","snmp_username"=>"","snmp_password"=>"",'
		. '"snmp_auth_protocol"=>"","snmp_priv_passphrase"=>"","snmp_priv_protocol"=>"","snmp_context"=>"","snmp_engine_id"=>"","snmp_timeout"=>500);'
		. '$first=open_snmp_session(5,$item);$second=open_snmp_session(5,$item);'
		. '$other=$item;$other["snmp_port"]=1161;snmp_mark_host_down(6,$other);$marked=open_snmp_session(6,$other);'
		. 'echo json_encode(array($first,$second,$marked,$GLOBALS["opens"]));');

	expect($state)->toBe(array(false, false, false, 1));
});
