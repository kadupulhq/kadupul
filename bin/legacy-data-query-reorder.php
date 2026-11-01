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
require_once $config['base_path'] . '/lib/snmp.php';
require_once $config['base_path'] . '/lib/data_query.php';

$status = 'failed';
$indexOn = [];
$unresolved = false;
try {
    $input = stream_get_contents(STDIN, 20001);
    if (strlen($input) > 20000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['database', 'items']) !== []
        || !in_array($command['database'] ?? null, ['local', 'main'], true)
        || !is_array($command['items'] ?? null) || !array_is_list($command['items'])) {
        throw new RuntimeException('Invalid command');
    }
    foreach ($command['items'] as $item) {
        if (!is_array($item) || !array_is_list($item) || count($item) !== 5 || !is_int($item[0]) || !is_int($item[1]) || !is_string($item[2])
            || !($item[3] === null || is_int($item[3])) || !($item[4] === null || is_string($item[4]))) {
            throw new RuntimeException('Invalid item');
        }
    }
    // The cli/ script ran on the main data collector, whose database is the local one.
    if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
        db_switch_remote_to_main();
    }
    foreach ($command['items'] as [$hostId, $queryId, $index, $templateDataId, $outputType]) {
        /* fetch current index_order from data_query XML definition and put it into host_snmp_query */
        update_data_query_sort_cache($hostId, $queryId);
        $field = get_best_data_query_index_type($hostId, $queryId);
        if ($field === false || $field === '') {
            $unresolved = true;
            break;
        }
        update_snmp_index_order([
            'host_id' => $hostId,
            'snmp_query_id' => $queryId,
            'snmp_index' => $index,
            'data_template_data_id' => $templateDataId,
            /* as the output_type is requested, 'value' gives the snmp_query_graph_id */
            'snmp_query_graph_id' => $outputType,
            'snmp_index_on' => $field,
        ]);
        $indexOn[] = (string) $field;
    }
    $status = 'ok';
} catch (Throwable $error) {
    cacti_log('ERROR: Data query reorder worker failed: ' . get_class($error), false, 'WEBLOG');
}
ob_end_clean();
echo 'KADUPUL_DATA_QUERY_REORDER_RESULT=' . json_encode(['status' => $status, 'index_on' => $indexOn, 'unresolved' => $unresolved], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
