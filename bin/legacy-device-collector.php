<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\DeviceCollectorAssignment;
use Kadupul\Inventory\Domain\DeviceEditConflict;

require __DIR__ . '/legacy-assignment-bootstrap.php';

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
    $row = \Kadupul\Inventory\Infrastructure\Legacy\DeviceAssignmentLock::findVisible($connection, $command['actor'], $command['id']);
    if ($row === null) {
        $status = 'denied';
        throw new RuntimeException('Access denied');
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
        (new \Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorTransfer())->apply($connection, $connections, $assignment->id, $previous, $target);
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
