<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace DevicePingErrorOutputTest;

function __($message) { return $message; }
function cacti_sizeof($value) { return count($value); }
function db_fetch_row_prepared($sql, $parameters) {
	$GLOBALS['ping_error_lookup'] = [$sql, $parameters];
	return [];
}

$root = dirname(__DIR__, 4);
require_once $root . '/tests/Helpers/PhpSource.php';
$pingErrorProduction = '';
foreach (['lib/api_device.php' => 'api_device_ping_device', 'lib/html.php' => 'html_escape'] as $file => $function) {
	$source = file_get_contents($root . '/' . $file);
	$body = \test_php_function_source($source, $function);
	// Only checked-in production functions are evaluated; no request input is executable.
	eval('namespace ' . __NAMESPACE__ . ';' . $body); // nosemgrep: php.lang.security.eval-use.eval-use
	$pingErrorProduction .= $body . "\n";
}

test('missing device ping renders text and preserves the bound lookup', function ($id, $remote) {
	unset($GLOBALS['ping_error_lookup']);
	ob_start();
	try {
		api_device_ping_device($id, $remote);
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	$suffix = $remote ? 'Please perform Full Sync!' : 'Please check database for errors.';
	$message = 'ERROR: Device[' . $id . '] not found.  ' . $suffix;
	$contract = str_replace('`', '&#96;', $message);
	$contract = htmlspecialchars($contract, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
	expect($output)->toBe($contract);
	expect($output)->not->toContain('<svg', '<script', '<img');
	expect($GLOBALS['ping_error_lookup'][1])->toBe([$id]);
	expect($GLOBALS['ping_error_lookup'][0])->toContain('WHERE id = ?');
})->with([
	[7], ['42'], ['<svg onload=alert(1)>'], ['"><img src=x onerror=alert(1)>'],
	["'&<script>alert(1)</script>"], ['router-日本語'], ['`onmouseover=alert(1)`'],
	['router&amp;<b>encoded</b>'],
])->with([false, true]);

test('ping errors preserve the configured character set', function ($charset, $id, $remote) use ($pingErrorProduction) {
	// A fresh process models a separate installation and isolates html_escape's static charset.
	$script = 'function __($message) { return $message; }'
		. 'function cacti_sizeof($value) { return count($value); }'
		. 'function db_fetch_row_prepared($sql, $parameters) { return []; }'
		. $pingErrorProduction
		. 'api_device_ping_device(base64_decode(' . var_export(base64_encode($id), true) . '), ' . ($remote ? 'true' : 'false') . ');';
	$process = proc_open([PHP_BINARY, '-d', 'default_charset=' . $charset, '-r', $script],
		[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	expect(is_resource($process))->toBeTrue();
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0);
	expect($error)->toBe('');
	$suffix = $remote ? 'Please perform Full Sync!' : 'Please check database for errors.';
	$message = 'ERROR: Device[' . $id . '] not found.  ' . $suffix;
	$expected = htmlspecialchars(str_replace('`', '&#96;', $message), ENT_QUOTES | ENT_HTML5, $charset ?: 'UTF-8', false);
	expect($output)->toBe($expected);
})->with([
	['ISO-8859-1', "caf\xe9 `<svg>&amp;"],
	['UTF-8', 'café `<svg>&amp;'],
	['', 'café `<svg>&amp;'],
])->with([false, true]);
