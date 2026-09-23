<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\DevicePlacement;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceStates;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceWriteAuthorization;
use Kadupul\Platform\Contract\DatabaseConnection;

require __DIR__ . '/legacy-assignment-bootstrap.php';
require_once __DIR__ . '/../lib/sort.php';

$status = 'failed';
$connection = null;
try {
    $input = stream_get_contents(STDIN, 32769);
    if (strlen($input) > 32768) {
        throw new InvalidArgumentException('Invalid command');
    }
    $command = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'selection', 'kind', 'destination', 'timespan', 'alignment']) !== [] || !is_int($command['actor'] ?? null) || $command['actor'] < 1 || !is_array($command['selection'] ?? null) || !is_string($command['kind'] ?? null) || !is_string($command['destination'] ?? null) || !is_int($command['timespan'] ?? null) || !is_int($command['alignment'] ?? null)) {
        throw new InvalidArgumentException('Invalid command');
    }
    $selection = new DeviceSelection($command['selection']);
    $placement = new DevicePlacement($command['kind'], $command['destination'], $command['timespan'], $command['alignment']);
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    if ((int) ($config['poller_id'] ?? 0) !== 1 || !db_execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !db_begin_transaction()) {
        throw new RuntimeException('Primary transaction unavailable');
    }
    if (!(new DeviceWriteAuthorization())->allows($connection, $command['actor'])) {
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
    $ids = array_keys($selection->revisions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $read = static function (string $sql, array $parameters) use ($connection): array {
        $query = $connection->prepare($sql);
        $query->execute($parameters);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    };
    $before = $read("SELECT id, site_id FROM host WHERE id IN ($placeholders) AND deleted = '' ORDER BY id", $ids);
    if (count($before) !== count($ids)) {
        $status = 'denied';
        throw new RuntimeException('Devices unavailable');
    }
    $sites = array_unique(array_column($before, 'site_id'));
    sort($sites, SORT_NUMERIC);
    foreach ($sites as $siteId) {
        if ((int) $siteId > 0) {
            $read('SELECT id FROM sites WHERE id = ? FOR UPDATE', [$siteId]);
        }
    }
    $fields = implode(', ', \Kadupul\Inventory\Infrastructure\Legacy\DeviceMaintenanceRecords::PUBLIC_FIELDS);
    $rows = $read("SELECT $fields FROM host WHERE id IN ($placeholders) AND deleted = '' ORDER BY id FOR UPDATE", $ids);
    $predicate = (new LegacyDeviceVisibility($provider))->predicate($command['actor'], true);
    $visible = $read("SELECT DISTINCT h.id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id IN ($placeholders) AND ($predicate) ORDER BY h.id LOCK IN SHARE MODE", $ids);
    if (count($rows) !== count($ids) || count($visible) !== count($ids)) {
        $status = 'denied';
        throw new RuntimeException('Devices unavailable');
    }
    foreach ($rows as $index => $row) {
        if ((int) $row['site_id'] !== (int) $before[$index]['site_id']) {
            throw new DeviceEditConflict('Selected devices changed. Reload the confirmation before saving.');
        }
        $device = LegacyDeviceStates::state($row);
        $device->assertRevision($selection->revisions[$device->id]);
    }
    if ($connection->exec('SET NAMES utf8mb4') === false || $connection->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')") === false) {
        throw new RuntimeException('Connection validation unavailable');
    }
    define('KADUPUL_THROW_DATABASE_ERRORS', true);
    $_SESSION['sess_user_id'] = $command['actor'];
    $database_last_error = '';
    $access = new \Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyResourceAccess($provider);
    if ($placement->kind === 'tree') {
        $owner = new \Kadupul\Graphing\Infrastructure\Legacy\LegacyDeviceTreePlacement($provider, $access);
        $owner->place($command['actor'], $ids, $placement->targetId, $placement->parentId);
        $action = 'tr_' . $placement->targetId;
    } else {
        $owner = new \Kadupul\Reporting\Infrastructure\Legacy\LegacyDeviceReportPlacement($provider, $access);
        $owner->place($command['actor'], $ids, $placement->targetId, $placement->timespan, $placement->alignment);
        $action = '8';
    }
    snmpagent_device_action_bottom([$action, $ids]);
    api_plugin_hook_function('device_action_bottom', [$action, $ids]);
    if (db_error() !== '' || is_error_message() || !$connection->inTransaction() || !db_commit_transaction()) {
        throw new RuntimeException('Placement failed');
    }
    $status = 'ok';
    cacti_log('INVENTORY: User ' . $command['actor'] . ' placed devices ' . implode(',', $ids) . ' in ' . $placement->kind . ' ' . $placement->targetId, false, 'AUDIT');
} catch (DeviceEditConflict) {
    $status = 'conflict';
} catch (Throwable) {
    // Keep database diagnostics and plugin output inside the isolated worker.
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        db_rollback_transaction($connection);
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_PLACEMENT_RESULT=' . json_encode(['status' => $status]) . "\n";
exit($status === 'ok' ? 0 : 1);
