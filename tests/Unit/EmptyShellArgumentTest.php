<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

function empty_shell_argument_probe(string $os, mixed $value, bool $quote = true, bool $native = false): array {
	$process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/empty-shell-argument.php', dirname(__DIR__, 2)),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($process)) throw new RuntimeException('Cannot start production quoting fixture');
	fwrite($pipes[0], json_encode(array('os' => $os, 'value' => $value, 'quote' => $quote, 'native' => $native), JSON_THROW_ON_ERROR));
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	if (proc_close($process) !== 0 || $error !== '') throw new RuntimeException($error . $output);
	return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

test('quoted empty strings use the configured platform quotation', function (string $os, string $expected): void {
	expect(empty_shell_argument_probe($os, '')['quoted'])->toBe($expected);
})->with(array('unix' => array('unix', "''"), 'windows' => array('win32', '""')));

test('unquoted empty and legacy non-string sentinels retain their values', function (string $os, mixed $value, bool $quote): void {
	expect(empty_shell_argument_probe($os, $value, $quote)['quoted'])->toBe($value);
})->with(array('unix', 'win32'))->with(array(
	'empty without quotation' => array('', false),
	'null with quotation' => array(null, true),
	'false with quotation' => array(false, true),
	'null without quotation' => array(null, false),
	'false without quotation' => array(false, false),
));

test('a native shell consumer receives an empty middle argument without shifting later arguments', function (string $input): void {
	$os = PHP_OS_FAMILY === 'Windows' ? 'win32' : 'unix';
	$result = empty_shell_argument_probe($os, $input, true, true);
	expect($result['arguments'])->toBe(array('before', '', 'tail'));
})->with(array('', "\n\r"));

test('native shell quoting preserves ordinary argument contents', function (string $input): void {
	$os = PHP_OS_FAMILY === 'Windows' ? 'win32' : 'unix';
	$result = empty_shell_argument_probe($os, $input, true, true);
	expect($result['arguments'])->toBe(array('before', $input, 'tail'));
})->with(array('0', 'two words', 'literal & punctuation'));
