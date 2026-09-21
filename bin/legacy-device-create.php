<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\NewDevice;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals, plugin hooks and poller effects from Symfony HTTP.
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/api_automation_tools.php';
require_once __DIR__ . '/../lib/api_device.php';
require_once __DIR__ . '/../lib/api_data_source.php';
require_once __DIR__ . '/../lib/api_graph.php';
require_once __DIR__ . '/../lib/api_tree.php';
require_once __DIR__ . '/../lib/data_query.php';
require_once __DIR__ . '/../lib/poller.php';
require_once __DIR__ . '/../lib/snmp.php';
require_once __DIR__ . '/../lib/template.php';
require_once __DIR__ . '/../lib/utility.php';

$status = 'failed';
$id = null;
$transactionStarted = false;
$writeStarted = false;
try {
    $input = stream_get_contents(STDIN, 500001);
    if (strlen($input) > 500000) {
        throw new InvalidArgumentException('Payload too large');
    }
    $command = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'fields']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] <= 0 || !is_array($command['fields'] ?? null)) {
        throw new InvalidArgumentException('Invalid command');
    }
    $device = new NewDevice($command['fields']);
    // The legacy connection defaults to utf8mb3 and permissive SQL modes.
    // Preserve validated Unicode and fail instead of silently truncating input.
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    if ($connection->exec('SET NAMES utf8mb4') === false
        || $connection->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')") === false) {
        throw new RuntimeException('Connection validation unavailable');
    }
    if ((int) ($config['poller_id'] ?? 0) !== 1 || !db_begin_transaction()) {
        throw new RuntimeException('Primary installation transaction unavailable');
    }
    $transactionStarted = true;
    $actor = db_fetch_row_prepared('SELECT id, enabled, locked FROM user_auth WHERE id = ? FOR UPDATE', [$command['actor']]);
    if (!$actor || $actor['enabled'] !== 'on' || $actor['locked'] === 'on' || (int) get_guest_account() === $command['actor']
        || !cacti_authorize_has_realm($command['actor'], 8) || !cacti_authorize_has_realm($command['actor'], 3)) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    $fields = $device->fields;
    if (((int) $fields['host_template_id'] !== 0 && !db_fetch_cell_prepared('SELECT id FROM host_template WHERE id = ? LOCK IN SHARE MODE', [$fields['host_template_id']]))
        || ((int) $fields['site_id'] !== 0 && !db_fetch_cell_prepared('SELECT id FROM sites WHERE id = ? LOCK IN SHARE MODE', [$fields['site_id']]))
        || !db_fetch_cell_prepared("SELECT id FROM poller WHERE id = ? AND disabled = '' LOCK IN SHARE MODE", [$fields['poller_id']])) {
        throw new InvalidArgumentException('Invalid reference');
    }
    if ($fields['use_default_credentials']) {
        foreach (['snmp_community', 'snmp_password', 'snmp_priv_passphrase'] as $key) {
            $fields[$key] = (string) read_config_option($key);
        }
        $fields['use_default_credentials'] = false;
        $fields = (new NewDevice($fields))->fields;
    }
    $fields['id'] = 0;
    $fields['device_template_id'] = $fields['host_template_id'];
    $fields['disabled'] = $fields['enabled'] ? '' : 'on';
    $_SESSION['sess_user_id'] = $command['actor'];
    $arguments = [];
    foreach ((new ReflectionFunction('api_device_save'))->getParameters() as $parameter) {
        $arguments[] = $fields[$parameter->getName()] ?? ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : '');
    }
    $writeStarted = true;
    $saved = api_device_save(...$arguments);
    if ((int) $saved <= 0 || is_error_message()) {
        throw new RuntimeException('Legacy save failed');
    }
    api_plugin_hook_function('host_save', ['host_id' => $saved]);
    if (!db_commit_transaction()) {
        throw new RuntimeException('Commit failed');
    }
    $transactionStarted = false;
    $id = (int) $saved;
    $status = 'ok';
    cacti_log('INVENTORY: User ' . $command['actor'] . ' created device ' . $id, false, 'AUDIT');
} catch (InvalidArgumentException) {
    $status = $writeStarted ? 'failed' : 'invalid';
} catch (Throwable) {
    // Return stable codes only; discard credentials, diagnostics and plugin output.
} finally {
    if ($transactionStarted) {
        db_rollback_transaction();
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_CREATE_RESULT=' . json_encode(['status' => $status, 'id' => $id]) . "\n";
exit($status === 'ok' ? 0 : 1);
