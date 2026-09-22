<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceStates;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Platform\Contract\DatabaseConnection;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals, plugin hooks and poller effects from Symfony HTTP.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
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
$transactionStarted = false;
try {
    $input = stream_get_contents(STDIN, 16001);
    if (strlen($input) > 16000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'selection', 'enabled']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] <= 0
        || !is_array($command['selection'] ?? null) || !is_bool($command['enabled'] ?? null)) {
        throw new RuntimeException('Invalid command');
    }
    $selection = new DeviceSelection($command['selection']);
    $ids = array_keys($selection->revisions);
    $enabled = $command['enabled'];
    if ((int) ($config['poller_id'] ?? 0) !== 1 || !db_execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !db_begin_transaction()) {
        throw new RuntimeException('Primary transaction unavailable');
    }
    $transactionStarted = true;
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    if (!(new \Kadupul\Inventory\Infrastructure\Legacy\DeviceWriteAuthorization())->allows($connection, $command['actor'])) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    $read = static function (PDO $db, string $sql, array $parameters): array {
        $query = $db->prepare($sql);
        if (!$query->execute($parameters)) {
            throw new RuntimeException('Device query failed');
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    };
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $associations = $read($connection, "SELECT id, site_id FROM host WHERE id IN ($placeholders) AND deleted = '' ORDER BY id", $ids);
    if (count($associations) !== count($ids)) {
        $status = 'missing';
        throw new RuntimeException('Devices unavailable');
    }
    $sites = array_unique(array_map(static fn($row) => (int) $row['site_id'], $associations));
    sort($sites, SORT_NUMERIC);
    foreach ($sites as $siteId) {
        if ($siteId > 0) {
            $read($connection, 'SELECT id FROM sites WHERE id = ? FOR UPDATE', [$siteId]);
        }
    }
    $rows = $read($connection, "SELECT id, description, hostname, disabled, status, site_id, poller_id, host_template_id FROM host WHERE id IN ($placeholders) AND deleted = '' ORDER BY id FOR UPDATE", $ids);
    if (count($rows) !== count($ids)) {
        $status = 'missing';
        throw new RuntimeException('Devices unavailable');
    }
    $provider = new class ($connection) implements DatabaseConnection {
        public function __construct(private PDO $connection) {}
        public function get(): PDO
        {
            return $this->connection;
        }
    };
    $predicate = (new LegacyDeviceVisibility($provider))->predicate($command['actor'], true);
    $visible = $read($connection, "SELECT DISTINCT h.id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id IN ($placeholders) AND ($predicate) ORDER BY h.id LOCK IN SHARE MODE", $ids);
    if (count($visible) !== count($ids)) {
        $status = 'missing';
        throw new RuntimeException('Devices unavailable');
    }
    $pollers = array_unique(array_map(static fn($row) => (int) $row['poller_id'], $rows));
    sort($pollers, SORT_NUMERIC);
    foreach ($pollers as $pollerId) {
        $read($connection, 'SELECT id FROM poller WHERE id = ? FOR UPDATE', [$pollerId]);
    }
    $changed = [];
    $remotes = [];
    // Validate the entire selection before any local or remote writes.
    foreach ($rows as $index => $row) {
        if ((int) $row['site_id'] !== (int) $associations[$index]['site_id']) {
            throw new DeviceEditConflict('Selected devices changed. Reload the confirmation before saving.');
        }
        $device = LegacyDeviceStates::state($row);
        $device->assertRevision($selection->revisions[$device->id]);
        $remoteMatches = true;
        if ($device->pollerId > 1) {
            if (!isset($remotes[$device->pollerId])) {
                if (!remote_poller_up($device->pollerId) || !(($remote = poller_connect_to_remote($device->pollerId)) instanceof PDO)) {
                    throw new RuntimeException('Collector unavailable');
                }
                $remotes[$device->pollerId] = $remote;
            }
            $remoteRows = $read($remotes[$device->pollerId], "SELECT id, poller_id, disabled FROM host WHERE id = ? AND deleted = ''", [$device->id]);
            if (count($remoteRows) !== 1 || (int) $remoteRows[0]['poller_id'] !== $device->pollerId) {
                throw new RuntimeException('Collector device unavailable');
            }
            $remoteMatches = ($remoteRows[0]['disabled'] !== 'on') === $enabled;
        }
        if ($device->enabled === $enabled && $remoteMatches && ($enabled || (int) $row['status'] === 0)) {
            continue;
        }
        $changed[$device->id] = $device;
    }
    if ($changed !== []) {
        define('KADUPUL_THROW_DATABASE_ERRORS', true);
        $_SESSION['sess_user_id'] = $command['actor'];
        // Legacy SQL helpers retain their last error even after later successes.
        // Check it without exposing diagnostics or credentials to the parent.
        $database_last_error = '';
        if ($enabled) {
            api_device_enable_devices(array_keys($changed));
        } elseif (!api_device_disable_devices(array_keys($changed))) {
            throw new RuntimeException('Disabling devices failed');
        }
        $action = $enabled ? '2' : '3';
        set_request_var('drp_action', $action);
        snmpagent_device_action_bottom([$action, $ids]);
        api_plugin_hook_function('device_action_bottom', [$action, $ids]);
        if (db_error() !== '' || is_error_message() || !$connection->inTransaction()) {
            throw new RuntimeException('Device operation could not be confirmed');
        }
        $markers = ['time_last_change_device' => (string) time()];
        foreach ($changed as $device) {
            $markers['poller_replicate_device_cache_crc_' . $device->pollerId] = bin2hex(random_bytes(20));
        }
        $query = $connection->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
        foreach ($markers as $name => $value) {
            if (!$query->execute([$name, $value, $value])) {
                throw new RuntimeException('Device cache invalidation failed');
            }
        }
    }
    // Confirm every selected copy even when preflight found no changes.
    foreach ($rows as $row) {
        $device = LegacyDeviceStates::state($row);
        $verify = $read($connection, "SELECT disabled, status, site_id, poller_id, host_template_id FROM host WHERE id = ? AND deleted = ''", [$device->id]);
        if (count($verify) !== 1 || ($verify[0]['disabled'] !== 'on') !== $enabled
            || (int) $verify[0]['site_id'] !== $device->siteId || (int) $verify[0]['poller_id'] !== $device->pollerId
            || (int) $verify[0]['host_template_id'] !== $device->templateId
            || (!$enabled && isset($changed[$device->id]) && (int) $verify[0]['status'] !== 0)) {
            throw new RuntimeException('Device state could not be confirmed');
        }
        if (isset($remotes[$device->pollerId])) {
            $verify = $read($remotes[$device->pollerId], "SELECT disabled, poller_id FROM host WHERE id = ? AND deleted = ''", [$device->id]);
            if (count($verify) !== 1 || ($verify[0]['disabled'] !== 'on') !== $enabled || (int) $verify[0]['poller_id'] !== $device->pollerId) {
                throw new RuntimeException('Collector state could not be confirmed');
            }
        }
    }
    if (!db_commit_transaction()) {
        throw new RuntimeException('Commit failed');
    }
    $transactionStarted = false;
    $status = 'ok';
    cacti_log('INVENTORY: User ' . $command['actor'] . ' confirmed ' . ($enabled ? 'enabled' : 'disabled') . ' state for devices ' . implode(',', $ids), false, 'AUDIT');
} catch (DeviceEditConflict) {
    $status = 'conflict';
} catch (Throwable) {
    // Remote effects may survive a primary rollback; never report false success.
} finally {
    if ($transactionStarted && $connection->inTransaction()) {
        db_rollback_transaction($connection);
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_STATE_RESULT=' . json_encode(['status' => $status]) . "\n";
exit($status === 'ok' ? 0 : 1);
