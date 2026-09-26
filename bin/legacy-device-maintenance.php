<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssignmentLock;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceMaintenanceRecords;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceMaintenanceExecutor;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceWriteAuthorization;

require __DIR__ . '/legacy-assignment-bootstrap.php';
require_once __DIR__ . '/../lib/api_automation.php';
require_once __DIR__ . '/../lib/sort.php';

$status = 'failed';
$connection = null;
$result = null;
$writing = false;
try {
    $input = stream_get_contents(STDIN, 4097);
    if (strlen($input) > 4096) {
        throw new InvalidArgumentException('Invalid command');
    }
    $command = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'id', 'operation', 'query', 'revision']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] < 1
        || !is_int($command['id'] ?? null) || $command['id'] < 1 || $command['id'] > 16777215
        || !is_int($command['query'] ?? null) || !is_string($command['operation'] ?? null)
        || !is_string($command['revision'] ?? null)) {
        throw new InvalidArgumentException('Invalid command');
    }
    $request = new DeviceMaintenanceRequest($command['operation'], $command['query']);
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    if ((int) ($config['poller_id'] ?? 0) !== 1 || !db_execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !db_begin_transaction()) {
        throw new RuntimeException('Primary transaction unavailable');
    }
    if (!(new DeviceWriteAuthorization())->allows($connection, $command['actor'])) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    if (DeviceAssignmentLock::findVisible($connection, $command['actor'], $command['id']) === null) {
        $status = 'denied';
        throw new RuntimeException('Device unavailable');
    }
    $query = $connection->prepare("SELECT * FROM host WHERE id = ? AND deleted = '' FOR UPDATE");
    $query->execute([$command['id']]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Device unavailable');
    }
    $records = new DeviceMaintenanceRecords();
    $state = $records->snapshot($connection, $row, true);
    $state->assertRequest($request, $command['revision']);
    if (in_array($request->operation, ['reindex', 'reload-query', 'query-diagnostics'], true) && (int) $row['status'] === HOST_DOWN) {
        throw new InvalidArgumentException('Device is down');
    }
    $query = $connection->prepare('SELECT id FROM poller WHERE id = ? FOR UPDATE');
    $query->execute([$state->device->pollerId]);
    if (!$query->fetchColumn()) {
        throw new RuntimeException('Collector unavailable');
    }
    $remote = null;
    if ($state->device->pollerId > 1) {
        if (!remote_poller_up($state->device->pollerId) || !(($remote = poller_connect_to_remote($state->device->pollerId)) instanceof PDO)) {
            throw new RuntimeException('Collector unavailable');
        }
        $query = $remote->prepare("SELECT id FROM host WHERE id = ? AND poller_id = ? AND deleted = ''");
        $query->execute([$state->device->id, $state->device->pollerId]);
        if (!$query->fetchColumn()) {
            throw new RuntimeException('Remote device unavailable');
        }
    }
    foreach (array_filter([$connection, $remote]) as $database) {
        if ($database->exec('SET NAMES utf8mb4') === false || $database->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')") === false) {
            throw new RuntimeException('Connection validation unavailable');
        }
    }
    define('KADUPUL_THROW_DATABASE_ERRORS', true);
    $_SESSION['sess_user_id'] = $command['actor'];
    $database_last_error = '';
    $writing = true;
    $result = (new DeviceMaintenanceExecutor($config['url_path']))->execute($connection, $remote, $state, $request, $row);
    if (db_error() !== '' || is_error_message() || !$connection->inTransaction()) {
        throw new RuntimeException('Maintenance failed');
    }
    $query = $connection->prepare("SELECT * FROM host WHERE id = ? AND deleted = ''");
    $query->execute([$state->device->id]);
    $after = $query->fetch(PDO::FETCH_ASSOC);
    if (!$after || $records->snapshot($connection, $after)->device->revision() !== $state->device->revision()) {
        throw new RuntimeException('Device identity changed');
    }
    if ($result->completed) {
        if (!db_commit_transaction()) {
            throw new RuntimeException('Commit failed');
        }
    } else {
        db_rollback_transaction($connection);
    }
    $status = 'ok';
    cacti_log('INVENTORY: User ' . $command['actor'] . ' ran ' . $request->operation . ' for device ' . $state->device->id . ($result->completed ? ' completed' : ' incomplete'), false, 'AUDIT');
} catch (DeviceEditConflict) {
    $status = 'conflict';
} catch (InvalidArgumentException) {
    $status = $writing ? 'failed' : 'invalid';
} catch (Throwable) {
    // Remote effects can survive rollback; no success without verified results.
} finally {
    unset($_SESSION['debug_log'], $config['debug_log']);
    if ($connection instanceof PDO && $connection->inTransaction()) {
        db_rollback_transaction($connection);
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_MAINTENANCE_RESULT=' . json_encode(['status' => $status, 'completed' => $status === 'ok' && $result->completed, 'message' => $status === 'ok' ? $result->message : '', 'output' => $status === 'ok' ? $result->output : '']) . "\n";
exit($status === 'ok' ? 0 : 1);
