<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\NewDevice;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceCreationCredentials;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceCreationVerifier;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals, plugin hooks and poller effects from Symfony HTTP.
ob_start();
// SQL statements and development diagnostics can contain SNMP credentials.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
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
    // Lock current policy and every grant used to authorize the write. A plain
    // UNION read can authorize against a concurrently revoked grant.
    $policy = $connection->query("SELECT name, value FROM settings WHERE name IN ('auth_method', 'guest_user') LOCK IN SHARE MODE")->fetchAll(PDO::FETCH_KEY_PAIR);
    $query = $connection->prepare('SELECT id, username, enabled, locked FROM user_auth WHERE id = ? FOR UPDATE');
    $query->execute([$command['actor']]);
    $actor = $query->fetch(PDO::FETCH_ASSOC);
    $hasRealm = static function (int $realm) use ($connection, $command): bool {
        $query = $connection->prepare('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ? LOCK IN SHARE MODE');
        $query->execute([$command['actor'], $realm]);
        if ($query->fetchColumn() !== false) {
            return true;
        }
        $query = $connection->prepare("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1 LOCK IN SHARE MODE");
        $query->execute([$command['actor'], $realm]);
        return $query->fetchColumn() !== false;
    };
    $guest = $policy['guest_user'] ?? '0';
    if (!$actor || $actor['enabled'] !== 'on' || $actor['locked'] === 'on'
        || !in_array((int) ($policy['auth_method'] ?? 1), [1, 2, 3, 4], true)
        || (int) $guest === $command['actor'] || $guest === $actor['username']
        || !$hasRealm(8) || !$hasRealm(3)) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    $fields = $device->fields;
    if (((int) $fields['host_template_id'] !== 0 && !db_fetch_cell_prepared('SELECT id FROM host_template WHERE id = ? LOCK IN SHARE MODE', [$fields['host_template_id']]))
        || ((int) $fields['site_id'] !== 0 && !db_fetch_cell_prepared('SELECT id FROM sites WHERE id = ? LOCK IN SHARE MODE', [$fields['site_id']]))
        || !db_fetch_cell_prepared("SELECT id FROM poller WHERE id = ? AND disabled = '' LOCK IN SHARE MODE", [$fields['poller_id']])) {
        throw new InvalidArgumentException('Invalid reference');
    }
    $fields = (new DeviceCreationCredentials())->resolve($device, 'read_config_option')->fields;
    $remote = null;
    if ((int) $fields['poller_id'] > 1) {
        if (!remote_poller_up((int) $fields['poller_id'])) {
            throw new RuntimeException('Selected collector is unavailable');
        }
        $remote = poller_connect_to_remote((int) $fields['poller_id']);
        if (!$remote instanceof PDO || $remote->exec('SET NAMES utf8mb4') === false
            || $remote->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')") === false) {
            throw new RuntimeException('Collector connection validation unavailable');
        }
        // Legacy connection setup can also change the primary session's modes.
        if ($connection->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')") === false) {
            throw new RuntimeException('Primary connection validation unavailable');
        }
    }
    $fields['id'] = 0;
    $fields['create_only'] = true;
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
    if ($remote !== null) {
        $columns = 'description, hostname, notes, location, external_id, host_template_id, site_id, poller_id, disabled, snmp_version, snmp_community, snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_engine_id, snmp_port, snmp_timeout, device_threads, availability_method, ping_method, ping_port, ping_timeout, ping_retries, max_oids, bulk_walk_size';
        $query = $connection->prepare('SELECT ' . $columns . ' FROM host WHERE id = ?');
        $query->execute([(int) $saved]);
        $primaryRow = $query->fetch(PDO::FETCH_ASSOC);
        $query = $remote->prepare('SELECT ' . $columns . ' FROM host WHERE id = ?');
        $query->execute([(int) $saved]);
        $remoteRow = $query->fetch(PDO::FETCH_ASSOC);
        if (!$primaryRow || !$remoteRow || $primaryRow != $remoteRow) {
            throw new RuntimeException('Collector replication could not be confirmed');
        }
    }
    (new DeviceCreationVerifier())->verify($connection, $remote, (int) $saved, (int) $fields['host_template_id']);
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
