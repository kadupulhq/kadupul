<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceTemplateAssignments;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceTemplateAssignment;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyDeviceTemplateAssignments implements DeviceTemplateAssignments
{
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility, private string $projectDir) {}
    public function findVisible(int $actorId, int $deviceId): ?DeviceTemplateAssignment
    {
        $query = $this->database->get()->prepare("SELECT DISTINCT h.id, h.description, h.host_template_id, h.poller_id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND h.deleted = '' AND (" . $this->visibility->predicate($actorId) . ')');
        $query->execute([$deviceId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row ? new DeviceTemplateAssignment((int) $row['id'], $row['description'], (int) $row['host_template_id'], (int) $row['poller_id']) : null;
    }
    public function templates(): array
    {
        return $this->database->get()->query('SELECT id, name FROM host_template WHERE id > 0 ORDER BY name, id')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
    public function save(int $actorId, DeviceTemplateAssignment $assignment, string $revision): void
    {
        $process = new Process([PHP_BINDIR . '/php', $this->projectDir . '/bin/legacy-device-template.php'], $this->projectDir);
        $process->setTimeout(120);
        $process->setInput(json_encode(['actor' => $actorId, 'id' => $assignment->id, 'template_id' => $assignment->templateId(), 'revision' => $revision], JSON_THROW_ON_ERROR));
        $process->run();
        if (!preg_match('/KADUPUL_TEMPLATE_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException('Template assignment outcome is unknown.');
        }
        $status = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR)['status'] ?? '';
        if ($status === 'conflict') {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if ($status === 'denied') {
            throw new InventoryAccessDenied(false);
        }
        if ($status === 'invalid') {
            throw new \InvalidArgumentException('Select a valid device template.');
        }
        if (!$process->isSuccessful() || $status !== 'ok') {
            throw new \RuntimeException('Template assignment could not be confirmed.');
        }
    }
}
