<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\DeviceTemplateAssignment;
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
$writeStarted = false;
try {
    $input = stream_get_contents(STDIN, 4097);
    if (strlen($input) > 4096) {
        throw new InvalidArgumentException('Payload too large');
    }
    $command = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'id', 'template_id', 'revision']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] <= 0
        || !is_int($command['id'] ?? null) || $command['id'] <= 0
        || !is_int($command['template_id'] ?? null) || $command['template_id'] < 0 || $command['template_id'] > 16777215
        || !is_string($command['revision'] ?? null)) {
        throw new InvalidArgumentException('Invalid command');
    }
    if ((int) ($config['poller_id'] ?? 0) !== 1 || !db_execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !db_begin_transaction()) {
        throw new RuntimeException('Primary transaction unavailable');
    }
    $transactionStarted = true;
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    if (!(new \Kadupul\Inventory\Infrastructure\Legacy\DeviceWriteAuthorization())->allows($connection, $command['actor'])) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    if ($command['template_id'] > 0 && !db_fetch_cell_prepared('SELECT id FROM host_template WHERE id = ? LOCK IN SHARE MODE', [$command['template_id']])) {
        throw new InvalidArgumentException('Invalid template');
    }
    // Match the site-before-host lock order used by site deletion and device saves.
    $site = db_fetch_row_prepared("SELECT site_id FROM host WHERE id = ? AND deleted = ''", [$command['id']]);
    if (!$site) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    if ((int) $site['site_id'] > 0) {
        $lock = $connection->prepare('SELECT id FROM sites WHERE id = ? FOR UPDATE');
        if (!$lock->execute([(int) $site['site_id']])) {
            throw new RuntimeException('Site lock unavailable');
        }
        $lock->fetchColumn();
    }
    $row = db_fetch_row_prepared("SELECT id, description, host_template_id, poller_id, site_id FROM host WHERE id = ? AND deleted = '' FOR UPDATE", [$command['id']]);
    $provider = new class ($connection) implements DatabaseConnection {
        public function __construct(private PDO $connection) {}
        public function get(): PDO
        {
            return $this->connection;
        }
    };
    $visibility = new LegacyDeviceVisibility($provider);
    $allowed = db_fetch_cell_prepared('SELECT h.id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND (' . $visibility->predicate($command['actor'], true) . ') LIMIT 1 LOCK IN SHARE MODE', [$command['id']]);
    if (!$row || !$allowed) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    if ((int) $row['site_id'] !== (int) $site['site_id']) {
        throw new DeviceEditConflict('Device site changed');
    }
    $assignment = new DeviceTemplateAssignment((int) $row['id'], $row['description'], (int) $row['host_template_id'], (int) $row['poller_id']);
    $assignment->assign($command['template_id'], $command['revision']);
    if ((int) $row['host_template_id'] !== $assignment->templateId()) {
        $remote = null;
        if ($assignment->pollerId > 1) {
            if (!remote_poller_up($assignment->pollerId)) {
                throw new RuntimeException('Collector unavailable');
            }
            $remote = poller_connect_to_remote($assignment->pollerId);
            if (!$remote instanceof PDO) {
                throw new RuntimeException('Collector unavailable');
            }
            $query = $remote->prepare("SELECT id FROM host WHERE id = ? AND deleted = ''");
            if (!$query->execute([$assignment->id]) || !$query->fetchColumn()) {
                throw new RuntimeException('Collector device unavailable');
            }
        }
        $_SESSION['sess_user_id'] = $command['actor'];
        $writeStarted = true;
        if ($assignment->templateId() === 0) {
            // Legacy unassignment keeps existing graph/query associations.
            foreach (array_filter([$connection, $remote]) as $database) {
                $query = $database->prepare("UPDATE host SET host_template_id = 0 WHERE id = ? AND deleted = '' AND poller_id = ?");
                if (!$query->execute([$assignment->id, $assignment->pollerId])) {
                    throw new RuntimeException('Unassignment failed');
                }
            }
        } else {
            api_device_update_host_template($assignment->id, $assignment->templateId());
        }
        api_plugin_hook_function('host_save', ['host_id' => $assignment->id]);
        if (is_error_message() || !$connection->inTransaction()) {
            throw new RuntimeException('Template save failed');
        }
        foreach (array_filter([$connection, $remote]) as $database) {
            $query = $database->prepare("SELECT host_template_id FROM host WHERE id = ? AND deleted = '' AND poller_id = ?");
            if (!$query->execute([$assignment->id, $assignment->pollerId]) || ($saved = $query->fetchColumn()) === false || (int) $saved !== $assignment->templateId()) {
                throw new RuntimeException('Template assignment could not be confirmed');
            }
        }
        (new \Kadupul\Inventory\Infrastructure\Legacy\DeviceCreationVerifier())->verify($connection, $remote, $assignment->id, $assignment->templateId(), true);
        $mark = $connection->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
        foreach (['time_last_change_device' => (string) time(), 'poller_replicate_device_cache_crc_' . $assignment->pollerId => bin2hex(random_bytes(20))] as $name => $value) {
            if (!$mark->execute([$name, $value, $value])) {
                throw new RuntimeException('Device cache invalidation failed');
            }
        }
    }
    if (!db_commit_transaction()) {
        throw new RuntimeException('Commit failed');
    }
    $transactionStarted = false;
    $status = 'ok';
    cacti_log('INVENTORY: User ' . $command['actor'] . ' confirmed template ' . $assignment->templateId() . ' for device ' . $assignment->id, false, 'AUDIT');
} catch (DeviceEditConflict) {
    $status = 'conflict';
} catch (InvalidArgumentException) {
    $status = $writeStarted ? 'failed' : 'invalid';
} catch (Throwable) {
    // Side effects may already have reached a collector; report failure, never success.
} finally {
    if ($transactionStarted) {
        db_rollback_transaction();
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_TEMPLATE_RESULT=' . json_encode(['status' => $status]) . "\n";
exit($status === 'ok' ? 0 : 1);
