<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceRemovalPolicy;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceRemovalSnapshot;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceRemovalDependencies;
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
$remotes = [];
/* Commits the main database first, then each collector. A collector that
   commits before the primary cannot be undone when the primary then fails; a
   collector that fails after it keeps stale rows until an operator resyncs. */
function device_removal_commit(array $remotes, array $ids)
{
    if (!db_commit_transaction()) {
        throw new RuntimeException('Commit failed');
    }

    foreach ($remotes as $pollerId => $remote) {
        if (!$remote->commit()) {
            cacti_log('ERROR: Devices ' . implode(',', $ids) . ' were removed from the main database but collector ' . $pollerId . ' did not commit; resync that collector', false, 'AUDIT');
        }
    }
}

/* Parses one removal command. Bounded length and depth keep a hostile payload
   away from the lifecycle below. */
function device_removal_command($input)
{
    if (strlen($input) > 16000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'selection', 'policy']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] <= 0
        || !is_array($command['selection'] ?? null) || !is_string($command['policy'] ?? null) || DeviceRemovalPolicy::tryFrom($command['policy']) === null) {
        throw new RuntimeException('Invalid command');
    }

    return $command;
}

try {
    $command = device_removal_command(stream_get_contents(STDIN, 16001));
    $selection = new DeviceSelection($command['selection']);
    $ids = array_keys($selection->revisions);
    $policy = DeviceRemovalPolicy::from($command['policy']);
    if ((int) ($config['poller_id'] ?? 0) !== 1 || !db_execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !db_begin_transaction()) {
        throw new RuntimeException('Primary transaction unavailable');
    }
    $transactionStarted = true;
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    // Revisions include descriptions; match the HTTP connection before reading
    // four-byte characters through the legacy connection's utf8mb3 default.
    if ($connection->exec('SET NAMES utf8mb4') === false) {
        throw new RuntimeException('Primary connection encoding unavailable');
    }
    $locked = (new \Kadupul\Inventory\Infrastructure\Legacy\DeviceMutationSelection())->lock(
        $connection,
        $command['actor'],
        $ids,
        static function (string $next) use (&$status): void {
            $status = $next;
        }
    );
    $associations = $locked['associations'];
    $rows = $locked['rows'];
    $pollers = $locked['pollers'];
    $read = $locked['read'];
    $remotes = [];
    $snapshots = [];
    $graphs = $data = $reviewed = [];
    // Every identity, association set and collector is checked before mutation.
    foreach ($rows as $index => $row) {
        if ((int) $row['site_id'] !== (int) $associations[$index]['site_id']) {
            throw new DeviceEditConflict('Device site changed');
        }
        $device = LegacyDeviceStates::state($row);
        $snapshot = DeviceRemovalSnapshot::read($connection, $device, true);
        $snapshot->assertRevision($selection->revisions[$device->id]);
        $snapshots[] = $snapshot;
        $reviewed[$device->id] = ['graphs' => $snapshot->graphIds, 'data_sources' => $snapshot->dataSourceIds, 'poller_id' => $device->pollerId];
        array_push($graphs, ...$snapshot->graphIds);
        array_push($data, ...$snapshot->dataSourceIds);
        if ($device->pollerId > 1) {
            if (!isset($remotes[$device->pollerId])) {
                if (!remote_poller_up($device->pollerId) || !(($remote = poller_connect_to_remote($device->pollerId)) instanceof PDO)) {
                    throw new RuntimeException('Collector unavailable');
                }
                if ($remote->inTransaction() || $remote->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false || !$remote->beginTransaction()) {
                    throw new RuntimeException('Collector transaction unavailable');
                }
                $remotes[$device->pollerId] = $remote;
            }
            $remoteRows = $read($remotes[$device->pollerId], "SELECT id, poller_id FROM host WHERE id = ? AND deleted = '' FOR UPDATE", [$device->id]);
            if (count($remoteRows) !== 1 || (int) $remoteRows[0]['poller_id'] !== $device->pollerId) {
                throw new RuntimeException('Collector device unavailable');
            }
        }
    }
    if ($policy === DeviceRemovalPolicy::Purge && !DeviceRemovalDependencies::exclusive($connection, $graphs, $data)) {
        $status = 'shared';
        throw new RuntimeException('Shared graph dependencies');
    }
    $primaryReceipt = $policy === DeviceRemovalPolicy::Purge
        ? \Kadupul\Inventory\Infrastructure\Legacy\DeviceRemovalDependencyReceipt::capture($connection, $graphs, $data)
        : null;
    $verifyReviewedScope = static function () use ($connection, $graphs, $data, $reviewed, $policy, $primaryReceipt): void {
        $primaryReceipt?->assertExclusive($connection);
        if (!DeviceRemovalDependencies::ownsRemaining($connection, $reviewed)) {
            throw new RuntimeException('Reviewed graph or data-source ownership changed');
        }
        if ($policy === DeviceRemovalPolicy::Purge && !DeviceRemovalDependencies::exclusive($connection, $graphs, $data)) {
            throw new RuntimeException('Graph data-source scope changed');
        }
    };
    $_SESSION['sess_user_id'] = $command['actor'];
    define('KADUPUL_THROW_DATABASE_ERRORS', true);
    $database_last_error = '';
    $verifier = new \Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorReplication();
    $reviewedRemoteTemplates = [];
    foreach ($snapshots as $snapshot) {
        if (isset($remotes[$snapshot->device->pollerId])) {
            $verifier->assertRemovalScope($remotes[$snapshot->device->pollerId], $snapshot);
            if (!DeviceRemovalDependencies::exclusive($remotes[$snapshot->device->pollerId], $snapshot->graphIds, $snapshot->dataSourceIds)) {
                throw new RuntimeException('Collector graph dependencies changed');
            }
            $reviewedRemoteTemplates[$snapshot->device->id] = $verifier->purgeReviewedDependents($remotes[$snapshot->device->pollerId], $snapshot);
        }
    }
    // The lifecycle API partitions remote cleanup while preserving one batch hook.
    api_device_remove_multi($ids, $policy === DeviceRemovalPolicy::Retain ? 1 : 2, [
        'graphs' => $graphs,
        'data_sources' => $data,
        'by_device' => $reviewed,
    ], $remotes, $verifyReviewedScope);
    if ($policy === DeviceRemovalPolicy::Purge && $data !== []) {
        // Graph removal already purged linked sources and invoked their hooks.
        // Only remaining ungraphed sources need the additional lifecycle call.
        $dataPlaceholders = implode(',', array_fill(0, count($data), '?'));
        $remainingData = $read($connection, "SELECT id FROM data_local WHERE id IN ($dataPlaceholders) ORDER BY id FOR UPDATE", $data);
        if ($remainingData !== []) {
            api_data_source_remove_multi(array_column($remainingData, 'id'), false, $verifyReviewedScope);
        }
    }
    $verifyRemoval = static function () use ($snapshots, $connection, $read, $policy, $remotes, $verifier, $reviewedRemoteTemplates, $primaryReceipt): void {
        $primaryReceipt?->assertPurged($connection);
        foreach ($snapshots as $snapshot) {
            $device = $snapshot->device;
            $remaining = $read($connection, 'SELECT deleted, poller_id FROM host WHERE id = ?', [$device->id]);
            if (($device->pollerId === 1 && $remaining !== [])
                || ($device->pollerId > 1 && (count($remaining) !== 1 || $remaining[0]['deleted'] !== 'on' || (int) $remaining[0]['poller_id'] !== $device->pollerId))) {
                throw new RuntimeException('Device removal could not be confirmed');
            }
            foreach (['host_graph', 'host_snmp_query', 'host_snmp_cache', 'poller_item', 'poller_reindex', 'graph_tree_items', 'reports_items'] as $table) {
                if ($read($connection, "SELECT host_id FROM $table WHERE host_id = ? LIMIT 1", [$device->id]) !== []) {
                    throw new RuntimeException('Device associations remain');
                }
            }
            foreach (['graph_local', 'data_local'] as $table) {
                if ($read($connection, "SELECT host_id FROM $table WHERE host_id = ? LIMIT 1 FOR UPDATE", [$device->id]) !== []) {
                    throw new RuntimeException('Unreviewed graph or data-source association remains');
                }
            }
            foreach (['graph_local' => $snapshot->graphIds, 'data_local' => $snapshot->dataSourceIds] as $table => $relatedIds) {
                foreach ($relatedIds as $id) {
                    $related = $read($connection, "SELECT host_id FROM $table WHERE id = ?", [$id]);
                    if (($policy === DeviceRemovalPolicy::Purge && $related !== [])
                        || ($policy === DeviceRemovalPolicy::Retain && (count($related) !== 1 || (int) $related[0]['host_id'] !== 0))) {
                        throw new RuntimeException('Graph or data-source removal could not be confirmed');
                    }
                }
            }
            if ($policy === DeviceRemovalPolicy::Retain) {
                foreach ($snapshot->dataSourceIds as $id) {
                    if ($read($connection, "SELECT id FROM data_template_data WHERE local_data_id = ? AND active != ''", [$id]) !== []) {
                        throw new RuntimeException('Retained data source remains enabled');
                    }
                }
            }
            if (isset($remotes[$device->pollerId])) {
                $verifier->verifyPurged($remotes[$device->pollerId], $device->id, $snapshot, $reviewedRemoteTemplates[$device->id]);
            }
        }
    };
    // Reject incomplete cleanup before callbacks can emit external side effects,
    // then verify again so callback mutations cannot escape final validation.
    $verifyRemoval();
    set_request_var('drp_action', '1');
    snmpagent_device_action_bottom(['1', $ids]);
    api_plugin_hook_function('device_action_bottom', ['1', $ids]);
    $verifyRemoval();
    if (db_error() !== '' || is_error_message() || !$connection->inTransaction()) {
        throw new RuntimeException('Device removal could not be confirmed');
    }
    $markers = ['time_last_change_device' => (string) time(), 'time_last_change_site_device' => (string) time()];
    foreach ($pollers as $pollerId) {
        $markers['poller_replicate_device_cache_crc_' . $pollerId] = bin2hex(random_bytes(20));
    }
    $query = $connection->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
    foreach ($markers as $name => $value) {
        if (!$query->execute([$name, $value, $value])) {
            throw new RuntimeException('Device cache invalidation failed');
        }
    }
    foreach ($remotes as $remote) {
        if (!$remote->inTransaction()) {
            throw new RuntimeException('Collector transaction changed');
        }
    }
    // A partial outcome is logged, not reported: the caller has no status for it.
    device_removal_commit($remotes, $ids);
    $transactionStarted = false;
    $status = 'ok';
    cacti_log('INVENTORY: User ' . $command['actor'] . ' removed devices ' . implode(',', $ids) . ' using policy ' . $policy->value, false, 'AUDIT');
} catch (DeviceEditConflict) {
    $status = 'conflict';
} catch (Throwable $error) {
    // Remote effects may survive a primary rollback; never report false success.
    cacti_log('WARNING: Device removal failed: ' . get_class($error) . ': ' . $error->getMessage(), false, 'AUDIT');
} finally {
    foreach ($remotes as $remote) {
        if ($remote->inTransaction()) {
            $remote->rollBack();
        }
    }
    if ($transactionStarted && $connection->inTransaction()) {
        db_rollback_transaction($connection);
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_REMOVE_RESULT=' . json_encode(['status' => $status]) . "\n";
exit($status === 'ok' ? 0 : 1);
