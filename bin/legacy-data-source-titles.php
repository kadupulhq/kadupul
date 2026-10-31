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
require_once $config['base_path'] . '/lib/utility.php';

$status = 'failed';
$titles = [];
try {
    $input = stream_get_contents(STDIN, 20001);
    if (strlen($input) > 20000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['database', 'data_sources']) !== []
        || !in_array($command['database'] ?? null, ['local', 'main'], true)
        || !is_array($command['data_sources'] ?? null) || !array_is_list($command['data_sources'])) {
        throw new RuntimeException('Invalid command');
    }
    foreach ($command['data_sources'] as $id) {
        if (!is_int($id) || $id < 1) {
            throw new RuntimeException('Invalid data source id');
        }
    }
    // The cli/ scripts worked on the main database from a remote collector; --local keeps the local one.
    if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
        db_switch_remote_to_main();
    }
    foreach ($command['data_sources'] as $id) {
        api_reapply_suggested_data_source_data($id);
        update_data_source_title_cache($id);
        $titles[] = (string) get_data_source_title($id);
    }
    $status = 'ok';
} catch (Throwable $error) {
    cacti_log('ERROR: Data source title worker failed: ' . get_class($error), false, 'WEBLOG');
}
ob_end_clean();
echo 'KADUPUL_DATA_SOURCE_TITLES_RESULT=' . json_encode(['status' => $status, 'titles' => $titles], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
