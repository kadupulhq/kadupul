<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use Kadupul\Inventory\Application\ReadModel\DeviceMaintenanceResult;
use PDO;

final class DeviceMaintenanceExecutor
{
    public function execute(PDO $primary, ?PDO $remote, DeviceMaintenanceState $state, DeviceMaintenanceRequest $request, #[\SensitiveParameter] array $host): DeviceMaintenanceResult
    {
        $id = $state->device->id;
        if (in_array($request->operation, ['enable-debug', 'disable-debug'], true)) {
            $enabled = $request->operation === 'enable-debug';
            foreach (array_filter([$primary, $remote]) as $database) {
                $this->setDebug($database, $id, $enabled);
            }
            return new DeviceMaintenanceResult(true, $enabled ? 'Device debug enabled.' : 'Device debug disabled.');
        }
        if ($request->operation === 'connectivity') {
            ob_start();
            try {
                api_device_ping_device($id);
                $output = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            return new DeviceMaintenanceResult(true, 'Connectivity check finished.', DeviceDiagnosticText::clean($output, $host));
        }
        if ($request->operation === 'refresh-cache') {
            push_out_host($id);
            if ($remote !== null) {
                $read = static function (PDO $db) use ($id): array {
                    $query = $db->prepare('SELECT local_data_id, poller_id, action, hostname, snmp_version, snmp_community, snmp_port, snmp_timeout, snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_engine_id, rrd_name FROM poller_item WHERE host_id = ? ORDER BY local_data_id, rrd_name');
                    $query->execute([$id]);
                    return $query->fetchAll(PDO::FETCH_ASSOC);
                };
                if ($read($primary) != $read($remote)) {
                    throw new \RuntimeException('Collector polling cache could not be confirmed');
                }
            }
            return new DeviceMaintenanceResult(true, 'Poller cache refreshed.');
        }
        $queries = $request->queryId > 0 ? [$request->queryId] : array_keys($state->queries);
        $completed = true;
        foreach ($queries as $queryId) {
            // A false legacy result is a failed discovery, not a successful reindex.
            $completed = run_data_query($id, $queryId) !== false && $completed;
        }
        $output = '';
        if ($request->operation === 'query-diagnostics') {
            $output = DeviceDiagnosticText::clean((string) debug_log_return('data_query'), $host);
        }
        return new DeviceMaintenanceResult($completed, 'Data queries reindexed.', $output);
    }

    private function setDebug(PDO $db, int $id, bool $enabled): void
    {
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction && !$db->beginTransaction()) {
                throw new \RuntimeException('Debug transaction unavailable');
            }
            $db->exec("INSERT IGNORE INTO settings (name,value) VALUES ('selective_device_debug','')");
            $query = $db->query("SELECT value FROM settings WHERE name='selective_device_debug' FOR UPDATE");
            $ids = array_filter(explode(',', (string) $query->fetchColumn()), static fn($value) => $value !== '' && $value !== (string) $id);
            if ($enabled) {
                $ids[] = (string) $id;
            }
            $ids = array_unique($ids);
            sort($ids, SORT_NUMERIC);
            $value = implode(',', $ids);
            $query = $db->prepare("UPDATE settings SET value = ? WHERE name='selective_device_debug'");
            if (!$query->execute([$value]) || (string) $db->query("SELECT value FROM settings WHERE name='selective_device_debug'")->fetchColumn() !== $value) {
                throw new \RuntimeException('Debug settings could not be confirmed');
            }
            if ($ownsTransaction && !$db->commit()) {
                throw new \RuntimeException('Debug commit failed');
            }
        } finally {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
        }
    }
}
