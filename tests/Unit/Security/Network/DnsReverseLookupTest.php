<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace DnsReverseLookupTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$root = dirname(__DIR__, 4);
eval('namespace ' . __NAMESPACE__ . ';' . substr(file_get_contents($root . '/lib/dns.php'), 5));
foreach (['lib/functions.php' => 'get_dns_from_ip', 'lib/api_automation.php' => 'automation_get_dns_from_ip'] as $file => $function) {
	$body = \test_php_function_source(file_get_contents($root . '/' . $file), $function);
	$include = "require_once __DIR__ . '/dns.php';";
	if (substr_count($body, $include) !== 1) throw new \RuntimeException('Missing shared DNS include');
	// Load the actual wrapper in this isolated namespace; the shared helper is already loaded above.
	eval('namespace ' . __NAMESPACE__ . ';' . str_replace($include, '', $body));
}

function random_bytes($length) {
	if ($length !== 2) throw new \LogicException('DNS ID must be two bytes');
	if ($GLOBALS['dns_case'] === 'entropy') throw new \RuntimeException('No entropy');
	return "\0\xff";
}
function fsockopen($address, $port, &$errno, &$error, $timeout) {
	$GLOBALS['dns_open'][] = [$address, $port, $timeout];
	return $GLOBALS['dns_case'] === 'connect' ? false : fopen('php://memory', 'r+');
}
function stream_set_timeout($handle, $seconds, $micros) {
	$GLOBALS['dns_timeout'] = [$seconds, $micros];
	return $GLOBALS['dns_case'] !== 'configure';
}
function stream_set_blocking($handle, $blocking) { return true; }
function fwrite($handle, $request) {
	$GLOBALS['dns_request'] = $request;
	return $GLOBALS['dns_case'] === 'short_write' ? 1 : strlen($request);
}
function stream_get_meta_data($handle) { return ['timed_out' => $GLOBALS['dns_case'] === 'timeout']; }
function fclose($handle) { $GLOBALS['dns_closed']++; return \fclose($handle); }
function wire_name($name) {
	$result = '';
	foreach (explode('.', $name) as $label) $result .= chr(strlen($label)) . $label;
	return $result . "\0";
}
function ptr_packet($request, $target = null) {
	$target = $target ?? wire_name('host.example');
	return substr($request, 0, 2) . pack('nnnnn', 0x8180, 1, 1, 0, 0) . substr($request, 12)
		. "\xc0\x0c" . pack('nnNn', 12, 1, 60, strlen($target)) . $target;
}
function fread($handle, $length) {
	$request = $GLOBALS['dns_request'];
	$packet = ptr_packet($request);
	$answer = strlen($request);
	switch ($GLOBALS['dns_case']) {
	case 'empty': return '';
	case 'read_error': return false;
	case 'id': $packet[0] = "\1"; break;
	case 'query': $packet[2] = "\1"; break;
	case 'opcode': $packet[2] = "\x89"; break;
	case 'truncated_flag': $packet[2] = "\x83"; break;
	case 'rcode': $packet[3] = "\x83"; break;
	case 'question_count': $packet[5] = "\2"; break;
	case 'question_name': $packet[13] = '9'; break;
	case 'question_type': $packet[$answer - 3] = "\1"; break;
	case 'question_class': $packet[$answer - 1] = "\3"; break;
	case 'answer_class': $packet[$answer + 5] = "\3"; break;
	case 'answer_type': $packet[$answer + 3] = "\1"; break;
	case 'unrelated_owner':
		$packet = substr($packet, 0, $answer) . wire_name('unrelated.example') . substr($packet, $answer + 2); break;
	case 'bad_length': $packet[$answer + 11] = "\xff"; break;
	case 'loop': $packet = ptr_packet($request, pack('n', 0xc000 | ($answer + 12))); break;
	case 'pointer_header': $packet = ptr_packet($request, "\xc0\0"); break;
	case 'label_type': $packet = ptr_packet($request, "\x40\0"); break;
	case 'unsafe_label': $packet = ptr_packet($request, "\3a.b\0"); break;
	case 'overlong_name': $packet = ptr_packet($request, str_repeat("\x3f" . str_repeat('a', 63), 4) . "\0"); break;
	case 'trailing': $packet .= 'extra'; break;
	}
	return $packet;
}

test('both legacy DNS wrappers preserve success and failure contracts', function ($function, $scenario) {
	$GLOBALS['dns_case'] = $scenario;
	$GLOBALS['dns_open'] = [];
	$GLOBALS['dns_closed'] = 0;
	$GLOBALS['dns_request'] = null;
	$call = __NAMESPACE__ . '\\' . $function;
	$result = $call('8.8.8.8', '192.0.2.53', 1250);
	expect($result)->toBe($scenario === 'valid' ? 'HOST.EXAMPLE' : ($scenario === 'timeout' ? 'timed_out' : '8.8.8.8'));
	if ($scenario === 'entropy') {
		expect($GLOBALS['dns_open'])->toBe([]);
	} else {
		expect($GLOBALS['dns_open'])->toBe([['udp://192.0.2.53', 53, 1.25]]);
		if ($scenario !== 'connect') expect($GLOBALS['dns_timeout'])->toBe([1, 250000]);
	}
	expect($GLOBALS['dns_closed'])->toBe(in_array($scenario, ['entropy', 'connect'], true) ? 0 : 1);
	if ($GLOBALS['dns_request'] !== null) {
		expect($GLOBALS['dns_request'])->toBe("\0\xff" . pack('nnnnn', 0x0100, 1, 0, 0, 0)
			. wire_name('8.8.8.8.in-addr.arpa') . pack('nn', 12, 1));
	}
})->with(['get_dns_from_ip', 'automation_get_dns_from_ip'])->with([
	'valid', 'timeout', 'entropy', 'connect', 'configure', 'short_write', 'empty', 'read_error', 'id', 'query',
	'opcode', 'truncated_flag', 'rcode', 'question_count', 'question_name', 'question_type', 'question_class',
	'answer_class', 'answer_type', 'unrelated_owner', 'bad_length', 'loop', 'pointer_header', 'label_type',
	'unsafe_label', 'overlong_name', 'trailing',
]);

test('invalid IPv4 inputs fail before network access', function ($function, $ip) {
	$GLOBALS['dns_open'] = [];
	$call = __NAMESPACE__ . '\\' . $function;
	expect($call($ip, '192.0.2.53'))->toBe('ERROR');
	expect($GLOBALS['dns_open'])->toBe([]);
})->with(['get_dns_from_ip', 'automation_get_dns_from_ip'])->with(['999.1.2.3', '1.2.3', '::1', '1.2.3.x', '']);

test('all truncated packet prefixes are rejected without warnings', function () {
	$request = "\0\xff" . pack('nnnnn', 0x0100, 1, 0, 0, 0) . wire_name('8.8.8.8.in-addr.arpa') . pack('nn', 12, 1);
	$packet = ptr_packet($request);
	for ($size = 0; $size < strlen($packet); $size++) {
		expect(cacti_dns_parse_ptr(substr($packet, 0, $size), "\0\xff", '8.8.8.8.in-addr.arpa'))->toBeFalse();
	}
});

test('CNAME chains and compressed PTR hostnames are matched to the question', function () {
	$question = wire_name('8.8.8.8.in-addr.arpa') . pack('nn', 12, 1);
	$packet = "\0\xff" . pack('nnnnn', 0x8180, 1, 2, 0, 0) . $question;
	$alias = wire_name('alias.example');
	$alias_offset = strlen($packet) + 12;
	$packet .= "\xc0\x0c" . pack('nnNn', 5, 1, 60, strlen($alias)) . $alias;
	$target = "\4host" . pack('n', 0xc000 | ($alias_offset + 6));
	$packet .= pack('n', 0xc000 | $alias_offset) . pack('nnNn', 12, 1, 60, strlen($target)) . $target;
	expect(cacti_dns_parse_ptr($packet, "\0\xff", '8.8.8.8.in-addr.arpa'))->toBe('HOST.EXAMPLE');
});
