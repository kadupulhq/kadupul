<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceCollectorAssignments;
use Kadupul\Inventory\Domain\DeviceCollectorAssignment;
use Kadupul\Platform\Contract\DatabaseConnection;

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
        DeviceAssignmentProcess::run($this->database->get(), $this->projectDir, 'collector', [
            'actor' => $actorId,
            'id' => $assignment->id,
            'collector_id' => $assignment->collectorId(),
            'revision' => $revision,
        ]);
    }
}
