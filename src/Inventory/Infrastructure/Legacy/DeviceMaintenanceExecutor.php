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
    public function __construct(private readonly string $basePath = '/') {}

    public function execute(PDO $primary, ?PDO $remote, DeviceMaintenanceState $state, DeviceMaintenanceRequest $request, #[\SensitiveParameter] array $host): DeviceMaintenanceResult
    {
        $id = $state->device->id;
        $remoteHost = [];
        if ($remote !== null && in_array($request->operation, ['query-diagnostics', 'connectivity'], true)) {
            $query = $remote->prepare("SELECT snmp_community, snmp_username, snmp_password, snmp_priv_passphrase FROM host WHERE id = ? AND poller_id = ? AND deleted = ''");
            $query->execute([$id, $state->device->pollerId]);
            $remoteHost = $query->fetch(PDO::FETCH_ASSOC);
            if (!$remoteHost) {
                throw new \RuntimeException('Remote diagnostics credentials unavailable');
            }
        }
        $clean = static fn(string $text): string => DeviceDiagnosticText::clean($text, $host, $remoteHost);
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
                if ($remote === null) {
                    api_device_ping_device($id);
                } else {
                    echo $this->remoteProbe($primary, $state->device->pollerId, $id);
                }
                $output = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            return new DeviceMaintenanceResult(true, 'Connectivity check finished.', $clean($output));
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
        $output = '';
        try {
            foreach ($queries as $queryId) {
                $completed = run_data_query($id, $queryId) !== false && $completed;
            }
            if ($request->operation === 'query-diagnostics') {
                $output = $clean((string) debug_log_return('data_query'));
            }
        } finally {
            unset($_SESSION['debug_log']);
        }
        return new DeviceMaintenanceResult($completed, 'Data queries reindexed.', $output);
    }

    private function remoteProbe(PDO $primary, int $pollerId, int $deviceId): string
    {
        $query = $primary->prepare('SELECT hostname FROM poller WHERE id = ?');
        $query->execute([$pollerId]);
        $hostname = $query->fetchColumn();
        if (!is_string($hostname) || $hostname === '' || preg_match('/[\\\\\/\s@?#]/', $hostname)
            || !str_starts_with($this->basePath, '/') || str_starts_with($this->basePath, '//')
            || str_contains($this->basePath, '..') || preg_match('/[\\\\\s?#]/', $this->basePath)) {
            throw new \RuntimeException('Invalid remote collector address');
        }
        $url = get_url_type() . '://' . $hostname . rtrim($this->basePath, '/') . '/remote_agent.php?action=ping&host_id=' . $deviceId;
        $timeout = max(1, min(300, (int) read_config_option('remote_agent_timeout')));
        $output = cacti_http($url, $timeout, [$hostname]);
        if ($output === false) {
            throw new \RuntimeException('Remote collector probe failed');
        }
        return $output;
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
