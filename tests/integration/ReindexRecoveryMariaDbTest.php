<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('reindex preserves native database state and retry outcomes', function ($scenario) {
	$process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/reindex-recovery-native.php', $scenario),
		array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	\PHPUnit\Framework\Assert::assertSame(0, proc_close($process), $output . $error);
	$result = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
	expect($result['scenario'])->toBe($scenario)->and($result['checks'])->toBeGreaterThan(11);
})->with(array(
	'wrapper-down', 'wrapper-disabled', 'wrapper-missing', 'guard-missing-connection', 'guard-unknown-table',
    'guard-no-database', 'unlock-exception', 'unlock-refused', 'cache-silent-failure',
    'mapping-empty', 'mapping-no-order', 'mapping-recovered', 'mapping-orphan', 'mapping-transient',
    'cache-success', 'cache-empty', 'cache-empty-types', 'cache-first-failure', 'cache-late-failure',
	'cache-borrowed-success', 'cache-borrowed-failure', 'cache-commit', 'cache-release', 'cache-begin',
	'cache-missing-host', 'cache-myisam', 'cache-temporary', 'cache-commit-false',
    'cache-mark-failure', 'cache-delete-failure', 'cache-preserve-failure', 'graphs-batch', 'graphs-shared',
	'remap-forward', 'remap-reverse', 'remap-index-failure', 'remap-value-failure', 'remap-type-failure', 'remap-graph-failure',
	'policy-numeric', 'policy-alpha', 'policy-alphabetic', 'policy-alphanumeric', 'xml-source-index',
	'lock-success', 'lock-false', 'lock-exception', 'pipeline-success', 'pipeline-cache-failure', 'pipeline-reindex-failure',
));


test('poller command acknowledgement retains unfinished and replaced work', function ($scenario) {
	$process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/reindex-command-native.php', $scenario),
		array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w')), $pipes);
	expect(is_resource($process))->toBeTrue(); fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	\PHPUnit\Framework\Assert::assertSame(0, proc_close($process), $output . $error);
	$result = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
	expect($result['results'][0]['scenario'])->toBe($scenario)->and($result['results'][0]['checks'])->toBeGreaterThan(0);
})->with(array('success','failure','exception','deadline','arrival','replacement','same-second','ack-failure'));
