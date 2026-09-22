<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\DeviceCollectorAssignment;
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
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'id', 'collector_id', 'revision']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] <= 0
        || !is_int($command['id'] ?? null) || $command['id'] <= 0
        || !is_int($command['collector_id'] ?? null) || $command['collector_id'] < 1 || $command['collector_id'] > 16777215
        || !is_string($command['revision'] ?? null)) {
        throw new InvalidArgumentException('Invalid command');
    }
    // Preserve four-byte text and reject truncation on legacy connections.
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    if ($connection->exec('SET NAMES utf8mb4') === false
        || $connection->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')") === false) {
        throw new RuntimeException('Primary connection validation unavailable');
    }
    if ((int) ($config['poller_id'] ?? 0) !== 1 || !db_execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !db_begin_transaction()) {
        throw new RuntimeException('Primary transaction unavailable');
    }
    $transactionStarted = true;
    if (!(new \Kadupul\Inventory\Infrastructure\Legacy\DeviceWriteAuthorization())->allows($connection, $command['actor'])) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
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
    $assignment = new DeviceCollectorAssignment((int) $row['id'], $row['description'], (int) $row['poller_id'], (int) $row['host_template_id']);
    $assignment->assign($command['collector_id'], $command['revision']);
    $previous = (int) $row['poller_id'];
    $target = $assignment->collectorId();
    $pollers = array_unique([$previous, $target]);
    sort($pollers, SORT_NUMERIC);
    $connections = [];
    // Take write locks up front: statistics updates must not upgrade shared
    // collector locks after remote effects have already started.
    foreach ($pollers as $pollerId) {
        $query = $connection->prepare('SELECT id, disabled FROM poller WHERE id = ? FOR UPDATE');
        if (!$query->execute([$pollerId]) || !($poller = $query->fetch(PDO::FETCH_ASSOC))
            || ($pollerId === $target && $poller['disabled'] !== '')) {
            throw new InvalidArgumentException('Invalid collector');
        }
        if ($previous !== $target && $pollerId > 1) {
            if (!remote_poller_up($pollerId) || !(($remote = poller_connect_to_remote($pollerId)) instanceof PDO)) {
                throw new RuntimeException('Collector unavailable');
            }
            if ($remote->exec('SET NAMES utf8mb4') === false
                || $remote->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')") === false) {
                throw new RuntimeException('Collector connection validation unavailable');
            }
            $connections[$pollerId] = $remote;
        }
    }
    // Opening a legacy remote connection can reset the primary SQL modes.
    if ($connection->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')") === false) {
        throw new RuntimeException('Primary connection validation unavailable');
    }
    if ($previous !== $target) {
        define('KADUPUL_THROW_DATABASE_ERRORS', true);
        $_SESSION['sess_user_id'] = $command['actor'];
        $writeStarted = true;
        // A queued purge from an earlier move must not delete a returning device.
        $query = $connection->prepare('DELETE FROM poller_command WHERE poller_id = ? AND action = ? AND SUBSTRING_INDEX(command, ":", 1) = ?');
        if (!$query->execute([$target, POLLER_COMMAND_PURGE, (string) $assignment->id])) {
            throw new RuntimeException('Stale collector cleanup could not be cancelled');
        }
        if ($target > 1) {
            // A previous purge may already have reached the destination queue.
            $query = $connections[$target]->prepare('DELETE FROM poller_command WHERE action = ? AND SUBSTRING_INDEX(command, ":", 1) = ?');
            if (!$query->execute([POLLER_COMMAND_PURGE, (string) $assignment->id])) {
                throw new \RuntimeException('Destination purge cancellation failed');
            }
            api_device_replicate_out($assignment->id, $target);
            // Legacy bulk replication omits host_graph; preserve those associations too.
            $query = $connection->prepare('SELECT * FROM host_graph WHERE host_id = ?');
            $query->execute([$assignment->id]);
            $graphs = $query->fetchAll(PDO::FETCH_ASSOC);
            replicate_table_to_poller($connections[$target], $graphs, 'host_graph');
        } else {
            foreach (['host' => 'id', 'poller_item' => 'host_id'] as $table => $column) {
                $query = $connection->prepare("UPDATE $table SET poller_id = ? WHERE $column = ?");
                if (!$query->execute([$target, $assignment->id])) {
                    throw new RuntimeException('Primary collector assignment failed');
                }
            }
        }
        api_plugin_hook_function('host_save', ['host_id' => $assignment->id]);
        if (is_error_message() || !$connection->inTransaction()) {
            throw new RuntimeException('Collector assignment failed');
        }
        $query = $connection->prepare('SELECT poller_id FROM host WHERE id = ?');
        $query->execute([$assignment->id]);
        if ((int) $query->fetchColumn() !== $target) {
            throw new RuntimeException('Collector identity mismatch');
        }
        $query = $connection->prepare('SELECT COUNT(*) FROM poller_item WHERE host_id = ? AND poller_id != ?');
        $query->execute([$assignment->id, $target]);
        if ((int) $query->fetchColumn() !== 0) {
            throw new RuntimeException('Polling ownership mismatch');
        }
        $verifier = new \Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorReplication();
        if ($target > 1) {
            $verifier->verifyTarget($connection, $connections[$target], $assignment->id);
        }
        // Verify the target before removing the old collector's polling state.
        if ($previous > 1) {
            $verifier->purgeDependents($connections[$previous], $assignment->id);
            api_device_purge_from_remote($assignment->id, $previous);
            $verifier->verifyPurged($connections[$previous], $assignment->id);
        }
        foreach ($pollers as $pollerId) {
            $stats = $connection->prepare('UPDATE poller SET snmp = (SELECT COUNT(*) FROM poller_item WHERE poller_id = ? AND action = 0), script = (SELECT COUNT(*) FROM poller_item WHERE poller_id = ? AND action = 1), server = (SELECT COUNT(*) FROM poller_item WHERE poller_id = ? AND action = 2) WHERE id = ?');
            if (!$stats->execute([$pollerId, $pollerId, $pollerId, $pollerId])) {
                throw new RuntimeException('Collector statistics update failed');
            }
        }
        $mark = $connection->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
        $markers = ['time_last_change_device' => (string) time()];
        foreach ($pollers as $pollerId) {
            $markers['poller_replicate_device_cache_crc_' . $pollerId] = bin2hex(random_bytes(20));
        }
        foreach ($markers as $name => $value) {
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
    cacti_log('INVENTORY: User ' . $command['actor'] . ' assigned device collector for device ' . $assignment->id, false, 'AUDIT');
} catch (DeviceEditConflict) {
    $status = 'conflict';
} catch (InvalidArgumentException) {
    $status = $writeStarted ? 'failed' : 'invalid';
} catch (Throwable) {
    // Side effects may already have reached a collector; report failure, never success.
} finally {
    if ($transactionStarted && $connection->inTransaction()) {
        db_rollback_transaction();
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_COLLECTOR_RESULT=' . json_encode(['status' => $status]) . "\n";
exit($status === 'ok' ? 0 : 1);
