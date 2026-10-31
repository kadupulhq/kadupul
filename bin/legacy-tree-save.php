<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals, plugin hooks and session messages from Symfony.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once $config['base_path'] . '/lib/api_tree.php';

$status = 'failed';
$id = 0;
try {
    $input = stream_get_contents(STDIN, 4097);
    if (strlen($input) > 4096) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    $count = static fn(mixed $value, int $minimum): bool => is_int($value) && $value >= $minimum;
    $valid = is_array($command) && in_array($command['database'] ?? null, ['local', 'main'], true);
    if ($valid && ($command['kind'] ?? null) === 'tree') {
        $valid = array_diff(array_keys($command), ['database', 'kind', 'name', 'sort_type']) === []
            && is_string($command['name'] ?? null) && $command['name'] !== '' && $count($command['sort_type'] ?? null, 1);
    } elseif ($valid && ($command['kind'] ?? null) === 'node') {
        $valid = array_diff(array_keys($command), ['database', 'kind', 'tree_id', 'item_type', 'parent_id', 'title', 'graph_id', 'host_id', 'site_id', 'grouping_type', 'sort_type']) === []
            && $count($command['tree_id'] ?? null, 1) && $count($command['item_type'] ?? null, 1) && $count($command['parent_id'] ?? null, 0)
            && is_string($command['title'] ?? null) && $count($command['graph_id'] ?? null, 0) && $count($command['host_id'] ?? null, 0)
            && $count($command['site_id'] ?? null, 0) && $count($command["grouping_type"] ?? null, 0) && $count($command['sort_type'] ?? null, 0);
    } else {
        $valid = false;
    }
    if (!$valid) {
        throw new RuntimeException('Invalid command');
    }
    // The cli/ scripts worked on the main database from a remote collector; --local keeps the local one.
    if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
        db_switch_remote_to_main();
    }
    if ($command['kind'] === 'tree') {
        // Zero means create a new tree rather than save over an existing one.
        $saved = sql_save(['id' => 0, 'name' => $command['name'], 'sort_type' => $command['sort_type']], 'graph_tree');
        if ($saved) {
            api_tree_sort_branch(0, $saved);
        }
    } else {
        $saved = api_tree_item_save(0, $command['tree_id'], $command['item_type'], $command['parent_id'], $command['title'], $command['graph_id'], $command['host_id'], $command['site_id'], $command['grouping_type'], $command['sort_type'], false);
    }
    if ($saved !== false && (int) $saved > 0) {
        $id = (int) $saved;
        $status = 'ok';
    }
} catch (Throwable $error) {
    cacti_log('ERROR: Tree save worker failed: ' . get_class($error), false, 'WEBLOG');
}
ob_end_clean();
echo 'KADUPUL_TREE_SAVE_RESULT=' . json_encode(['status' => $status, 'id' => $id], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
