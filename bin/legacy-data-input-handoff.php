<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}
define('KADUPUL_REDACT_DATABASE_LOGS', true);
define('KADUPUL_THROW_DATABASE_ERRORS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once __DIR__ . '/../lib/api_data_source.php';
require_once __DIR__ . '/../lib/poller.php';
require_once __DIR__ . '/../lib/template.php';
require_once __DIR__ . '/../lib/utility.php';
require_once __DIR__ . '/../lib/data_input_worker.php';
$command = [];
$db = null;
$status = 'partial';
try {
    $raw = stream_get_contents(STDIN, 1025);
    $command = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    if (strlen($raw) > 1024 || !is_array($command) || array_diff(array_keys($command), ['actor', 'id', 'nonce']) !== [] || !is_int($command['actor'] ?? null) || $command['actor'] < 1 || !is_int($command['id'] ?? null) || $command['id'] < 1 || $command['id'] > 99999999 || !is_string($command['nonce'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $command['nonce'])) {
        throw new InvalidArgumentException('Invalid handoff.');
    }
    $db = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$db instanceof PDO || (int) ($config['poller_id'] ?? 0) !== 1) {
        throw new RuntimeException('Primary database required.');
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    dataInputWorkerAuthorize($db, $command['actor']);
    dataInputWorkerState($db, $command['id']);
    if (!$db->commit()) {
        throw new RuntimeException('Authorization was not confirmed.');
    }
    $_SESSION['sess_user_id'] = $command['actor'];
    $database_last_error = '';
    // This leaf performs only collector handoff; it never repeats a primary
    // save, duplicate, delete, or replication-CRC update.
    push_out_data_input_method($command['id']);
    if (db_error() === '' && !is_error_message() && array_filter($_SESSION['sess_messages'] ?? [], static fn(array $message): bool => ($message['level'] ?? 0) >= MESSAGE_LEVEL_WARN) === []) {
        $status = 'ok';
    }
} catch (Throwable) {
    // Local persistence was already confirmed by the supervising worker.
    // Reauthorization failures, errors, and warnings remain explicit partials.
} finally {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_DATA_INPUT_HANDOFF_RESULT=' . json_encode(['actor' => $command['actor'] ?? 0, 'id' => $command['id'] ?? 0, 'nonce' => $command['nonce'] ?? '', 'phase' => 'propagate', 'status' => $status], JSON_THROW_ON_ERROR) . "\n";
exit($status === 'ok' ? 0 : 1);
