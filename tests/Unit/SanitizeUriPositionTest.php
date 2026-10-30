<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('URI sanitization distinguishes position zero from an absent match', function (string $input, string $expected): void {
	$process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/fixtures/sanitize-uri-client.php', dirname(__DIR__, 2)),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0)->and($error)->toBe('');
	expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
})->with(array(
	'relative graph page' => array('graph_view.php?filter=demo', 'graph_view.php?filter=demo&action=tree'),
	'absolute graph page' => array('/graph_view.php?filter=demo', '/graph_view.php?filter=demo&action=tree'),
	'graph page without query' => array('graph_view.php', 'graph_view.php?action=tree'),
	'existing action' => array('/graph_view.php?action=preview', '/graph_view.php?action=preview'),
	'action at zero' => array('action=preview&target=graph_view.php', 'action=preview&target=graph_view.php'),
	'query delimiter at zero' => array('?target=graph_view.php', '?target=graph_view.php&action=tree'),
	'non-graph page' => array('index.php?filter=demo', 'index.php?filter=demo'),
	'empty URI' => array('', ''),
));
