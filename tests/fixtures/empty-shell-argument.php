<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

if (($argv[2] ?? '') === 'child') {
	echo json_encode(array_slice($argv, 3), JSON_THROW_ON_ERROR);
	exit(0);
}

$root = $argv[1];
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$config = array('cacti_server_os' => $input['os']);
$source = file_get_contents($root . '/lib/functions.php');
eval(test_php_function_source($source, 'cacti_escapeshellarg'));
$quoted = cacti_escapeshellarg($input['value'], $input['quote']);
$result = array('quoted' => $quoted);
if ($input['native']) {
	$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($root)
		. ' child ' . cacti_escapeshellarg('before') . ' ' . $quoted . ' ' . cacti_escapeshellarg('tail');
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($process)) throw new RuntimeException('Cannot start native argument consumer');
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	if (proc_close($process) !== 0 || $error !== '') throw new RuntimeException($error);
	$result['arguments'] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
echo json_encode($result, JSON_THROW_ON_ERROR);
