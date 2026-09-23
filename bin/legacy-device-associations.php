<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\DeviceAssociationChange;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssignmentLock;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationRecords;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationWriter;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceWriteAuthorization;

require __DIR__ . '/legacy-assignment-bootstrap.php';

$status = 'failed';
$connection = null;
$remote = null;
$writing = false;
try {
    $input = stream_get_contents(STDIN, 4097);
    if (strlen($input) > 4096) {
        throw new InvalidArgumentException('Invalid command');
    }
    $command = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'id', 'kind', 'operation', 'target', 'revision']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] < 1
        || !is_int($command['id'] ?? null) || $command['id'] < 1 || $command['id'] > 16777215
        || !is_string($command['revision'] ?? null)) {
        throw new InvalidArgumentException('Invalid command');
    }
    $change = new DeviceAssociationChange($command['kind'] ?? '', $command['operation'] ?? '', $command['target'] ?? 0);
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    if ($connection->exec('SET NAMES utf8mb4') === false || $connection->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')") === false) {
        throw new RuntimeException('Connection validation unavailable');
    }
    if ((int) ($config['poller_id'] ?? 0) !== 1 || !db_execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !db_begin_transaction()) {
        throw new RuntimeException('Primary transaction unavailable');
    }
    if (!(new DeviceWriteAuthorization())->allows($connection, $command['actor'])) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
    }
    $row = DeviceAssignmentLock::findVisible($connection, $command['actor'], $command['id']);
    if (!$row) {
        $status = 'denied';
        throw new RuntimeException('Device unavailable');
    }
    $records = new DeviceAssociationRecords();
    $device = $records->snapshot($connection, $row, $change->kind, true);
    $device->assertChange($change, $command['revision']);
    if ($change->operation === 'add' && !array_key_exists($change->targetId, $records->available($connection, $change->kind, true))) {
        throw new InvalidArgumentException('Invalid association target');
    }
    $query = $connection->prepare('SELECT id FROM poller WHERE id = ? FOR UPDATE');
    $query->execute([$device->pollerId]);
    if (!$query->fetchColumn()) {
        throw new RuntimeException('Collector unavailable');
    }
    $remote = null;
    if ($device->pollerId > 1) {
        if (!remote_poller_up($device->pollerId) || !(($remote = poller_connect_to_remote($device->pollerId)) instanceof PDO)) {
            throw new RuntimeException('Collector unavailable');
        }
        if ($remote->inTransaction() || !$remote->beginTransaction()) {
            throw new RuntimeException('Remote transaction unavailable');
        }
        $query = $remote->prepare("SELECT id FROM host WHERE id = ? AND poller_id = ? AND deleted = '' FOR UPDATE");
        $query->execute([$device->id, $device->pollerId]);
        if (!$query->fetchColumn()) {
            throw new RuntimeException('Remote device unavailable');
        }
        if ($change->operation !== 'remove') {
            $query = $remote->prepare('SELECT id FROM graph_templates WHERE id = ? FOR UPDATE');
            $query->execute([$change->targetId]);
            if (!$query->fetchColumn()) {
                throw new RuntimeException('Remote association target unavailable');
            }
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
    $writer = new DeviceAssociationWriter();
    $writer->apply($connection, $remote, $device, $change);
    if (db_error() !== '' || is_error_message() || !$connection->inTransaction() || ($remote !== null && !$remote->inTransaction())) {
        throw new RuntimeException('Association change failed');
    }
    $writer->verify($connection, $remote, $device, $change);
    $mark = $connection->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
    foreach (['time_last_change_device' => (string) time(), 'poller_replicate_device_cache_crc_' . $device->pollerId => bin2hex(random_bytes(20))] as $name => $value) {
        if (!$mark->execute([$name, $value, $value])) {
            throw new RuntimeException('Cache invalidation failed');
        }
    }
    if ($remote !== null && !$remote->commit()) {
        throw new RuntimeException('Remote commit failed');
    }
    if (!db_commit_transaction()) {
        throw new RuntimeException('Commit failed');
    }
    $status = 'ok';
    cacti_log('INVENTORY: User ' . $command['actor'] . ' changed ' . $change->kind . ' association for device ' . $device->id, false, 'AUDIT');
} catch (DeviceEditConflict) {
    $status = 'conflict';
} catch (InvalidArgumentException) {
    $status = $writing ? 'failed' : 'invalid';
} catch (Throwable) {
    // Remote effects can survive rollback; return no success without verification.
} finally {
    if ($remote instanceof PDO && $remote->inTransaction()) {
        $remote->rollBack();
    }
    if ($connection instanceof PDO && $connection->inTransaction()) {
        db_rollback_transaction($connection);
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_ASSOCIATIONS_RESULT=' . json_encode(['status' => $status]) . "\n";
exit($status === 'ok' ? 0 : 1);
