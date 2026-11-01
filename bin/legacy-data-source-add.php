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
// The libraries cli/add_datasource.php loaded, because change_data_template() and push_out_host() reach into them.
foreach (['api_data_source', 'poller', 'utility', 'template'] as $library) {
    require_once $config['base_path'] . '/lib/' . $library . '.php';
}

$status = 'failed';
$id = 0;
try {
    $input = stream_get_contents(STDIN, 2001);
    if (strlen($input) > 2000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['database', 'host_id', 'data_template_id', 'push_out']) !== []
        || !in_array($command['database'] ?? null, ['local', 'main'], true)
        || !is_int($command['host_id'] ?? null) || $command['host_id'] < 0
        || !is_int($command['data_template_id'] ?? null) || $command['data_template_id'] < 0
        || !is_bool($command['push_out'] ?? null)) {
        throw new RuntimeException('Invalid command');
    }
    // The cli/ scripts worked on the main database from a remote collector; --local keeps the local one.
    if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
        db_switch_remote_to_main();
    }
    $host = $command['host_id'];
    $template = $command['data_template_id'];
    // Copied from data_sources.php, function form_save, save_component_data_source_new, as the script did.
    $saved = sql_save(['id' => '0', 'data_template_id' => $template, 'host_id' => $host], 'data_local');
    if ($saved && (int) $saved > 0) {
        $id = (int) $saved;
        change_data_template($id, $template);
        /* update the title cache */
        update_data_source_title_cache($id);
        /* update host data */
        if ($command['push_out']) {
            push_out_host($host, $id);
        }
        $status = 'ok';
    }
} catch (Throwable $error) {
    cacti_log('ERROR: Data source add worker failed: ' . get_class($error), false, 'WEBLOG');
}
ob_end_clean();
echo 'KADUPUL_DATA_SOURCE_ADD_RESULT=' . json_encode(['status' => $status, 'id' => $id], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
