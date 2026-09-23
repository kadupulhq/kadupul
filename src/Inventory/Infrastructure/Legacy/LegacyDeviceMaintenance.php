<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceMaintenance;
use Kadupul\Inventory\Application\ReadModel\DeviceMaintenanceResult;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyDeviceMaintenance implements DeviceMaintenance
{
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility, private DeviceMaintenanceRecords $records, private string $projectDir) {}
    public function findVisible(int $actorId, int $id): ?DeviceMaintenanceState
    {
        $query = $this->database->get()->prepare("SELECT DISTINCT h.id, h.description, h.hostname, h.disabled, h.site_id, h.poller_id, h.host_template_id, h.location, h.device_threads, h.snmp_port, h.snmp_timeout, h.max_oids, h.bulk_walk_size, h.availability_method, h.ping_method, h.ping_port, h.ping_timeout, h.ping_retries, h.snmp_version, h.snmp_auth_protocol, h.snmp_priv_protocol, h.snmp_context, h.snmp_engine_id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND h.deleted = '' AND (" . $this->visibility->predicate($actorId) . ')');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->records->snapshot($this->database->get(), $row) : null;
    }
    public function execute(int $actorId, int $id, DeviceMaintenanceRequest $request, string $revision): DeviceMaintenanceResult
    {
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-device-maintenance.php'], $this->projectDir);
        $process->setTimeout(120);
        $process->setInput(json_encode(['actor' => $actorId, 'id' => $id, 'operation' => $request->operation, 'query' => $request->queryId, 'revision' => $revision], JSON_THROW_ON_ERROR));
        $process->run();
        if (!preg_match('/KADUPUL_MAINTENANCE_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException('Device maintenance outcome is unknown.');
        }
        try {
            $result = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Device maintenance outcome is unknown.');
        }
        $status = $result['status'] ?? '';
        if ($status === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if ($status === 'conflict') {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if ($status === 'invalid') {
            throw new \InvalidArgumentException('Select a valid device maintenance action and query.');
        }
        if (!$process->isSuccessful() || $status !== 'ok' || !is_bool($result['completed'] ?? null) || !is_string($result['message'] ?? null) || !is_string($result['output'] ?? null) || strlen($result['output']) > 65536) {
            throw new \RuntimeException('Device maintenance could not be confirmed.');
        }
        return new DeviceMaintenanceResult($result['completed'], $result['message'], $result['output']);
    }
}
