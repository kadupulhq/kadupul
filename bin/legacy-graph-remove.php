<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals, plugin hooks and session messages from Symfony.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once $config['base_path'] . '/lib/api_data_source.php';
require_once $config['base_path'] . '/lib/api_graph.php';

$status = 'failed';
$done = 0;
try {
    $input = stream_get_contents(STDIN, 20001);
    if (strlen($input) > 20000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['database', 'graphs', 'keep_data_sources']) !== []
        || !in_array($command['database'] ?? null, ['local', 'main'], true)
        || !is_bool($command['keep_data_sources'] ?? null)
        || !is_array($command['graphs'] ?? null) || !array_is_list($command['graphs']) || $command['graphs'] === []) {
        throw new RuntimeException('Invalid command');
    }
    foreach ($command['graphs'] as $id) {
        if (!is_int($id) || $id < 1) {
            throw new RuntimeException('Invalid graph id');
        }
    }
    // The cli/ scripts worked on the main database from a remote collector; --local keeps the local one.
    if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
        db_switch_remote_to_main();
    }
    $ids = array_combine($command['graphs'], $command['graphs']);
    // Type 1 keeps the data sources; type 2 removes those the graphs reference, as remove_broken_graphs.php and remove_graphs.php did.
    api_delete_graphs($ids, $command['keep_data_sources'] ? '1' : '2');
    $done = count($command['graphs']);
    $status = 'ok';
} catch (Throwable $error) {
    cacti_log('ERROR: Graph removal worker failed: ' . get_class($error), false, 'WEBLOG');
}
ob_end_clean();
echo 'KADUPUL_GRAPH_REMOVE_RESULT=' . json_encode(['status' => $status, 'done' => $done], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
