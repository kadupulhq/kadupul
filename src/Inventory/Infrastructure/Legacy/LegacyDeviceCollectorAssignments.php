<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceCollectorAssignments;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceCollectorAssignment;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyDeviceCollectorAssignments implements DeviceCollectorAssignments
{
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility, private string $projectDir) {}
    public function findVisible(int $actorId, int $deviceId): ?DeviceCollectorAssignment
    {
        $query = $this->database->get()->prepare("SELECT DISTINCT h.id, h.description, h.poller_id, h.host_template_id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND h.deleted = '' AND (" . $this->visibility->predicate($actorId) . ')');
        $query->execute([$deviceId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row ? new DeviceCollectorAssignment((int) $row['id'], $row['description'], (int) $row['poller_id'], (int) $row['host_template_id']) : null;
    }
    public function collectors(): array
    {
        return $this->database->get()->query("SELECT id, COALESCE(name, '') AS name FROM poller WHERE id > 0 AND disabled = '' ORDER BY name, id")->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
    public function save(int $actorId, DeviceCollectorAssignment $assignment, string $revision): void
    {
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-device-collector.php'], $this->projectDir);
        $process->setTimeout(120);
        $process->setInput(json_encode(['actor' => $actorId, 'id' => $assignment->id, 'collector_id' => $assignment->collectorId(), 'revision' => $revision], JSON_THROW_ON_ERROR));
        $process->run();
        if (!preg_match('/KADUPUL_COLLECTOR_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException('Collector assignment outcome is unknown.');
        }
        try {
            $status = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR)['status'] ?? '';
        } catch (\JsonException) {
            throw new \RuntimeException('Collector assignment outcome is unknown.');
        }
        if ($status === 'conflict') {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if ($status === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if ($status === 'invalid') {
            throw new \InvalidArgumentException('Select a valid device collector.');
        }
        if (!$process->isSuccessful() || $status !== 'ok') {
            throw new \RuntimeException('Collector assignment could not be confirmed.');
        }
    }
}
