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
require_once $config['base_path'] . '/lib/api_automation.php';

$status = 'failed';
try {
    $input = stream_get_contents(STDIN, 2001);
    if (strlen($input) > 2000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['database', 'host_id', 'graph_template_id']) !== []
        || !in_array($command['database'] ?? null, ['local', 'main'], true)
        || !is_int($command['host_id'] ?? null) || $command['host_id'] < 1
        || !is_int($command['graph_template_id'] ?? null) || $command['graph_template_id'] < 1) {
        throw new RuntimeException('Invalid command');
    }
    // The cli/ scripts worked on the main database from a remote collector; --local keeps the local one.
    if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
        db_switch_remote_to_main();
    }
    $host = $command['host_id'];
    $template = $command['graph_template_id'];
    db_execute_prepared('REPLACE INTO host_graph (host_id, graph_template_id) VALUES (?, ?)', [$host, $template]);
    automation_hook_graph_template($host, $template);
    api_plugin_hook_function('add_graph_template_to_host', ['host_id' => $host, 'graph_template_id' => $template]);
    $status = 'ok';
} catch (Throwable $error) {
    cacti_log('ERROR: Graph template attach worker failed: ' . get_class($error), false, 'WEBLOG');
}
ob_end_clean();
echo 'KADUPUL_GRAPH_TEMPLATE_ATTACH_RESULT=' . json_encode(['status' => $status], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
