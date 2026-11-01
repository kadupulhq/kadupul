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
// The libraries cli/add_data_query.php loaded, because run_data_query() and its plugin hooks reach into them.
foreach (['api_automation_tools', 'api_automation', 'api_data_source', 'api_graph', 'api_device', 'data_query', 'poller', 'snmp', 'sort', 'template', 'utility'] as $library) {
    require_once $config['base_path'] . '/lib/' . $library . '.php';
}

$status = 'failed';
try {
    $input = stream_get_contents(STDIN, 2001);
    if (strlen($input) > 2000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['database', 'host_id', 'data_query_id', 'reindex_method']) !== []
        || !in_array($command['database'] ?? null, ['local', 'main'], true)
        || !is_int($command['host_id'] ?? null) || $command['host_id'] < 1
        || !is_int($command['data_query_id'] ?? null) || $command['data_query_id'] < 1) {
        throw new RuntimeException('Invalid command');
    }
    // A number the operator typed in another spelling, such as 1.5, went into the SQL as typed; nothing but a number reaches it.
    $method = $command['reindex_method'] ?? null;
    if (!(is_int($method) && $method >= 0 && $method <= 3)
        && !(is_string($method) && preg_match('/^[0-9eE.+\s-]{1,40}$/', $method) === 1 && is_numeric($method) && $method >= 0 && $method <= 3)) {
        throw new RuntimeException('Invalid command');
    }
    // The cli/ scripts worked on the main database from a remote collector; --local keeps the local one.
    if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
        db_switch_remote_to_main();
    }
    $host = $command['host_id'];
    $query = $command['data_query_id'];
    db_execute('REPLACE INTO host_snmp_query (host_id,snmp_query_id,reindex_method) VALUES (' . $host . ',' . $query . ',' . $method . ')');
    /* recache snmp data */
    run_data_query($host, $query);
    if (!is_error_message()) {
        $status = 'ok';
    }
} catch (Throwable $error) {
    cacti_log('ERROR: Data query associate worker failed: ' . get_class($error), false, 'WEBLOG');
}
ob_end_clean();
echo 'KADUPUL_DATA_QUERY_ASSOCIATE_RESULT=' . json_encode(['status' => $status], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
