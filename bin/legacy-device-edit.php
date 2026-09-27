<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\Device;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceSiteWriter;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuditTrail;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyWorkerAudit;
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
$audit = new LegacyWorkerAudit(new LegacyAuditTrail(dirname(__DIR__)), 'inventory.device.edit', 'device', 'unknown');
try {
    $input = stream_get_contents(STDIN, 500001);
    if (strlen($input) > 500000) {
        throw new InvalidArgumentException('Payload too large');
    }
    $command = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['correlation_id', 'actor', 'id', 'revision', 'description', 'hostname', 'notes', 'enabled', 'location', 'external_id', 'site_id', 'polling', 'snmp']) !== []
        || !is_array($command['polling'] ?? null) || !is_array($command['snmp'] ?? null)
        || !is_int($command['site_id'] ?? null) || $command['site_id'] < 0 || $command['site_id'] > 4294967295
        || !is_bool($command['enabled'] ?? null) || !is_int($command['actor'] ?? null) || !is_int($command['id'] ?? null) || $command['actor'] <= 0 || $command['id'] <= 0) {
        throw new InvalidArgumentException('Invalid command');
    }
    $audit->correlate($command['correlation_id'] ?? null);
    $audit->actorId = $command['actor'];
    $audit->targetId = (string) $command['id'];
    foreach (['revision', 'description', 'hostname', 'notes', 'location', 'external_id'] as $field) {
        if (!is_string($command[$field] ?? null)) {
            throw new InvalidArgumentException('Invalid command');
        }
    }
    if (!db_begin_transaction()) {
        throw new RuntimeException('Transaction unavailable');
    }
    $transactionStarted = true;
    $actor = db_fetch_row_prepared('SELECT id, enabled, locked FROM user_auth WHERE id = ? FOR UPDATE', [$command['actor']]);
    if (!$actor || $actor['enabled'] !== 'on' || $actor['locked'] === 'on' || (int) get_guest_account() === $command['actor']
        || !cacti_authorize_has_realm($command['actor'], 8) || !cacti_authorize_has_realm($command['actor'], 3)) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    // Lock site before host, matching site deletion and legacy device saves.
    // Recheck the association after acquiring the host lock; the first read
    // only discovers which site to lock and cannot authorize a write.
    $association = db_fetch_row_prepared("SELECT site_id FROM host WHERE id = ? AND deleted = ''", [$command['id']]);
    if (!$association) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    $provider = new class ($connection) implements DatabaseConnection {
        public function __construct(private PDO $connection) {}
        public function get(): PDO
        {
            return $this->connection;
        }
    };
    $visibility = new LegacyDeviceVisibility($provider);
    $initiallyAllowed = db_fetch_cell_prepared("SELECT h.id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND (" . $visibility->predicate($command['actor']) . ') LIMIT 1', [$command['id']]);
    if (!$initiallyAllowed) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    $audit->decision = AuditEvent::ALLOWED;
    $audit->outcome = AuditEvent::FAILED;
    $siteIds = array_unique([(int) $association['site_id'], $command['site_id']]);
    sort($siteIds, SORT_NUMERIC);
    foreach ($siteIds as $siteId) {
        if ($siteId === $command['site_id']) {
            LegacyDeviceSiteWriter::lockSite($connection, $siteId);
        } elseif ($siteId > 0) {
            // A missing historical source can be repaired by choosing a valid target.
            $lock = $connection->prepare('SELECT id FROM sites WHERE id = ? FOR UPDATE');
            if (!$lock->execute([$siteId])) {
                throw new RuntimeException('Source site lock failed.');
            }
            $lock->fetchColumn();
        }
    }
    $row = db_fetch_row_prepared("SELECT * FROM host WHERE id = ? AND deleted = '' FOR UPDATE", [$command['id']]);
    if ($row && (int) $row['site_id'] !== (int) $association['site_id']) {
        throw new DeviceEditConflict('Device site changed. Reload before saving.');
    }
    $allowed = db_fetch_cell_prepared("SELECT h.id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND (" . $visibility->predicate($command['actor']) . ') LIMIT 1', [$command['id']]);
    if (!$allowed) {
        $status = 'denied';
        $audit->decision = AuditEvent::DENIED;
        $audit->outcome = AuditEvent::DENIED;
        throw new RuntimeException('Access denied');
    }
    if (!$row) {
        throw new RuntimeException('Device disappeared during save.');
    }
    $device = new Device((int) $row['id'], $row['description'], (string) $row['hostname'], (string) $row['notes'], $row['disabled'] !== 'on', (string) $row['location'], (string) $row['external_id'], (int) $row['site_id'], array_intersect_key($row, \Kadupul\Inventory\Domain\DevicePolling::DEFAULTS), array_intersect_key($row, \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::PUBLIC_DEFAULTS));
    $device->revise($command['description'], $command['hostname'], $command['notes'], $command['enabled'], $command['location'], $command['external_id'], $command['revision'], $command['site_id'], $command['polling'], $command['snmp']);
    try {
        $snmp = $device->snmpChange()->resolve($row);
    } catch (InvalidArgumentException) {
        $status = 'snmp_invalid';
        throw new RuntimeException('SNMP settings and stored credentials are incompatible.');
    }
    $row = array_replace($row, $device->polling(), $snmp);
    $row['site_id'] = $device->siteId();
    $row['expected_site_id'] = $device->siteId();
    $row['description'] = $device->description();
    $row['hostname'] = $device->hostname();
    $row['notes'] = $device->notes();
    $row['location'] = $device->location();
    $row['external_id'] = $device->externalId();
    $row['disabled'] = $device->enabled() ? '' : 'on';
    $row['device_template_id'] = $row['host_template_id'];
    $_SESSION['sess_user_id'] = $command['actor'];
    $arguments = [];
    foreach ((new ReflectionFunction('api_device_save'))->getParameters() as $parameter) {
        $arguments[] = $row[$parameter->getName()] ?? ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : '');
    }
    $saved = api_device_save(...$arguments);
    if ((int) $saved !== $device->id || is_error_message()) {
        throw new RuntimeException('Legacy save failed');
    }
    api_plugin_hook_function('host_save', ['host_id' => $saved]);
    if (!$connection->inTransaction()) {
        throw new RuntimeException('Device transaction was lost.');
    }
    $verify = $connection->prepare('SELECT site_id FROM host WHERE id = ?');
    if (!$verify->execute([$device->id]) || ($savedSite = $verify->fetchColumn()) === false || (int) $savedSite !== $device->siteId()) {
        throw new RuntimeException('Site assignment could not be confirmed.');
    }
    if ((int) $association['site_id'] !== $device->siteId()) {
        $mark = $connection->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
        $now = (string) time();
        foreach (['time_last_change_device', 'time_last_change_site_device'] as $name) {
            if (!$mark->execute([$name, $now, $now])) {
                throw new RuntimeException('Site assignment cache invalidation failed.');
            }
        }
    }
    if (!db_commit_transaction()) {
        throw new RuntimeException('Commit failed');
    }
    $transactionStarted = false;
    $status = 'ok';
    $audit->outcome = AuditEvent::SUCCEEDED;
    cacti_log('INVENTORY: User ' . $command['actor'] . ' edited device ' . $device->id, false, 'AUDIT');
} catch (DeviceEditConflict) {
    $status = 'conflict';
} catch (Throwable) {
    // The parent receives only stable error codes, never credentials or plugin output.
} finally {
    $audit->recordAfter($transactionStarted ? db_rollback_transaction(...) : null);
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_EDIT_RESULT=' . json_encode(['status' => $status]) . "\n";
exit($status === 'ok' ? 0 : 1);
