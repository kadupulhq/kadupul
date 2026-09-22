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
foreach (['lib/api_device.php' => 'api_device_ping_device', 'lib/html.php' => 'html_escape'] as $file => $function) {
	$source = file_get_contents($root . '/' . $file);
	if (!preg_match('/^function ' . $function . '\(.*?^}/ms', $source, $match)) {
		throw new \RuntimeException('Missing production function: ' . $function);
	}
	eval('namespace ' . __NAMESPACE__ . ';' . $match[0]);
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
	expect($output)->toBe(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
	expect($output)->not->toContain('<svg', '<script', '<img');
	expect($GLOBALS['ping_error_lookup'][1])->toBe([$id]);
	expect($GLOBALS['ping_error_lookup'][0])->toContain('WHERE id = ?');
})->with([
	[7], ['42'], ['<svg onload=alert(1)>'], ['"><img src=x onerror=alert(1)>'],
	["'&<script>alert(1)</script>"], ['router-日本語'],
])->with([false, true]);
