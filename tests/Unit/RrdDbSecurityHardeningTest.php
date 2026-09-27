<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$rrdSource      = file_get_contents(dirname(__DIR__, 2) . '/lib/rrd.php');
$dbSource       = file_get_contents(dirname(__DIR__, 2) . '/lib/database.php');
$htmlUtilSource = file_get_contents(dirname(__DIR__, 2) . '/lib/html_utility.php');

// --- lib/rrd.php: rrdtool_function_create ---

test('rrdtool_function_create does not use shell_exec with chown', function () use ($rrdSource) {
	$start = strpos($rrdSource, 'function rrdtool_function_create(');
	expect($start)->not->toBeFalse();

	$end  = strpos($rrdSource, "\nfunction ", $start + 10);
	$body = substr($rrdSource, $start, $end - $start);

	// Must not shell out to chown
	expect(str_contains($body, 'shell_exec'))
		->toBeFalse();
});

test('rrdtool_function_create sets ownership through the native-call helper', function () use ($rrdSource) {
	$start = strpos($rrdSource, 'function rrdtool_function_create(');
	expect($start)->not->toBeFalse();

	$end  = strpos($rrdSource, "\nfunction ", $start + 10);
	$body = substr($rrdSource, $start, $end - $start);

	expect(str_contains($body, 'rrdtool_set_rrd_ownership('))
		->toBeTrue();
});

test('the RRD ownership helper uses native lchown() and lchgrp()', function () use ($rrdSource) {
	$start = strpos($rrdSource, 'function rrdtool_set_rrd_ownership(');
	expect($start)->not->toBeFalse();

	$end  = strpos($rrdSource, "\nfunction ", $start + 10);
	$body = substr($rrdSource, $start, $end - $start);

	// Native calls that never follow a symbolic link, not a shell command.
	expect(str_contains($body, 'lchown('))->toBeTrue()
		->and(str_contains($body, 'lchgrp('))->toBeTrue();
});

// --- lib/database.php: db_dump_data ---

test('db_dump_data uses proc_open instead of exec', function () use ($dbSource) {
	$start = strpos($dbSource, 'function db_dump_data(');
	expect($start)->not->toBeFalse();

	$end  = strpos($dbSource, "\nfunction ", $start + 10);
	$body = substr($dbSource, $start, $end - $start);

	expect(str_contains($body, 'proc_open'))
		->toBeTrue();
});

test('db_dump_data does not use exec() directly', function () use ($dbSource) {
	$start = strpos($dbSource, 'function db_dump_data(');
	expect($start)->not->toBeFalse();

	$end  = strpos($dbSource, "\nfunction ", $start + 10);
	$body = substr($dbSource, $start, $end - $start);

	// Should not call exec() for command execution
	expect((bool) preg_match('/\bexec\s*\(/', $body))
		->toBeFalse();
});

test('db_dump_data uses cacti_escapeshellarg on command elements', function () use ($dbSource) {
	$start = strpos($dbSource, 'function db_dump_data(');
	expect($start)->not->toBeFalse();

	$end  = strpos($dbSource, "\nfunction ", $start + 10);
	$body = substr($dbSource, $start, $end - $start);

	expect(str_contains($body, 'cacti_escapeshellarg'))
		->toBeTrue();
});

// --- lib/database.php: db_qstr fallback ---

test('db_qstr fallback escaping handles newline character', function () use ($dbSource) {
	$start = strpos($dbSource, 'function db_qstr(');
	expect($start)->not->toBeFalse();

	$end  = strpos($dbSource, "\nfunction ", $start + 10);
	$body = substr($dbSource, $start, $end - $start);

	expect(str_contains($body, '\\n'))
		->toBeTrue();
});

test('db_qstr fallback escaping handles carriage return', function () use ($dbSource) {
	$start = strpos($dbSource, 'function db_qstr(');
	expect($start)->not->toBeFalse();

	$end  = strpos($dbSource, "\nfunction ", $start + 10);
	$body = substr($dbSource, $start, $end - $start);

	expect(str_contains($body, '\\r'))
		->toBeTrue();
});

test('db_qstr fallback escaping handles SUB character 0x1a', function () use ($dbSource) {
	$start = strpos($dbSource, 'function db_qstr(');
	expect($start)->not->toBeFalse();

	$end  = strpos($dbSource, "\nfunction ", $start + 10);
	$body = substr($dbSource, $start, $end - $start);

	expect(str_contains($body, "\\x1a"))
		->toBeTrue();
});

// --- lib/html_utility.php: validate_redirect_url ---

test('validate_redirect_url prefers SERVER_NAME over HTTP_HOST', function () use ($htmlUtilSource) {
	// SERVER_NAME must be checked before HTTP_HOST
	$serverNamePos = strpos($htmlUtilSource, "SERVER_NAME");
	$httpHostPos   = strpos($htmlUtilSource, "HTTP_HOST");

	expect($serverNamePos)->not->toBeFalse();
	expect($httpHostPos)->not->toBeFalse();
	expect($serverNamePos)->toBeLessThan($httpHostPos);
});

test('validate_redirect_url uses strtolower for scheme check', function () use ($htmlUtilSource) {
	// strtolower is used to normalize the URL before checking for bad schemes
	expect(str_contains($htmlUtilSource, 'strtolower('))
		->toBeTrue();

	// The lowered value is used for scheme checks like javascript:
	expect(str_contains($htmlUtilSource, "'javascript:'"))
		->toBeTrue();
});
