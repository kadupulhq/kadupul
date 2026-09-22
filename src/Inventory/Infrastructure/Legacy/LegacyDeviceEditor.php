<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceEditor;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\Device;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyDeviceEditor implements DeviceEditor
{
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility, private string $projectDir) {}
    public function findVisible(int $userId, int $id): ?Device
    {
        $query = $this->database->get()->prepare("SELECT DISTINCT h.id, h.description, h.hostname, h.notes, h.disabled, h.location, h.external_id, h.site_id, h.device_threads, h.snmp_port, h.snmp_timeout, h.max_oids, h.bulk_walk_size, h.availability_method, h.ping_method, h.ping_port, h.ping_timeout, h.ping_retries, h.snmp_version, h.snmp_auth_protocol, h.snmp_priv_protocol, h.snmp_context, h.snmp_engine_id
            FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id
            WHERE h.id = ? AND h.deleted = '' AND (" . $this->visibility->predicate($userId) . ')');
        $query->execute([$id]);
        $row = $query->fetch();
        return $row ? new Device((int) $row['id'], $row['description'], (string) $row['hostname'], (string) $row['notes'], $row['disabled'] !== 'on', (string) $row['location'], (string) $row['external_id'], (int) $row['site_id'], array_intersect_key($row, \Kadupul\Inventory\Domain\DevicePolling::DEFAULTS), array_intersect_key($row, \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::PUBLIC_DEFAULTS)) : null;
    }
    public function save(int $userId, Device $device, string $expectedRevision): void
    {
        // Fixed executable and script, argument array, stdin payload: no shell.
        $process = new Process([PHP_BINDIR . '/php', $this->projectDir . '/bin/legacy-device-edit.php'], $this->projectDir);
        $process->setTimeout(60);
        $process->setInput(json_encode(['actor' => $userId, 'id' => $device->id, 'revision' => $expectedRevision,
            'description' => $device->description(), 'hostname' => $device->hostname(), 'notes' => $device->notes(), 'enabled' => $device->enabled(),
            'location' => $device->location(), 'external_id' => $device->externalId(), 'site_id' => $device->siteId(), 'polling' => $device->polling(), 'snmp' => $device->snmpChange()->fields], JSON_THROW_ON_ERROR));
        $process->run();
        if (!preg_match('/KADUPUL_EDIT_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException('Save outcome is unknown. Reload the device before retrying.');
        }
        $status = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['status'] ?? '';
        if ($status === 'invalid') {
            throw new \InvalidArgumentException('SNMP settings and stored credentials are incompatible. Replace credentials or review the selected settings.');
        }
        if ($status === 'conflict') {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if ($status === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if (!$process->isSuccessful() || $status !== 'ok') {
            throw new \RuntimeException('Save could not be completed. Reload the device before retrying.');
        }
    }
}
