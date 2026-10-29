<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace LtsSnmpPortIntegerTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

#[\AllowDynamicProperties]
final class SNMP {
	public const VERSION_1 = 0;
	public const VERSION_2c = 1;
	public const VERSION_3 = 3;
	public static array $targets = array();
	public function __construct($version, $target, $community, $timeout, $retries) { self::$targets[] = $target; }
}
function read_config_option($name) { return ''; }
function cacti_escapeshellarg($value) { return escapeshellarg($value); }

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/snmp.php');
if ($source === false) { throw new \RuntimeException('Cannot read SNMP implementation'); }
foreach (array('cacti_snmp_options_sanitize', 'snmp_format_target', 'cacti_snmp_session') as $name) {
	eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
}

test('sanitized numeric ports reach the shell target as integers', function ($input, $expected) {
	$port = $input;
	$timeout = 500;
	$retries = 1;
	$maxOids = 10;
	$accepted = cacti_snmp_options_sanitize(2, 'public', $port, $timeout, $retries, $maxOids);

	expect($accepted)->toBeTrue()->and($port)->toBe($expected)
		->and(snmp_format_target('192.0.2.1', $port))->toBe("'192.0.2.1':" . $expected);
})->with(array(
	'numeric string' => array('1161', 1161),
	'integer' => array(1161, 1161),
	'empty default' => array('', 161),
	'trailing newline' => array("1161\n", 1161),
	'leading space' => array(' 1161', 1161),
	'exponent' => array('1e3', 1000),
));

test('nonnumeric ports remain refused by the sanitizer', function ($port) {
	$timeout = 500;
	$retries = 1;
	$maxOids = 10;
	expect(cacti_snmp_options_sanitize(2, 'public', $port, $timeout, $retries, $maxOids))->toBeFalse();
})->with(array('161;id', '161 -v', 'abc'));

test('native SNMP targets carry an integer port', function ($port, $expected) {
	SNMP::$targets = array();
	$session = cacti_snmp_session('192.0.2.1', 'public', '2', port: $port);
	expect($session)->not->toBeFalse()->and(SNMP::$targets)->toBe(array('192.0.2.1:' . $expected));
})->with(array(
	'numeric string' => array('1161', 1161),
	'trailing newline' => array("1161\n", 1161),
	'direct invalid input falls back' => array('161;id', 161),
));
