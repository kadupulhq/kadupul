<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceTemplateAssignments;
use Kadupul\Inventory\Domain\DeviceTemplateAssignment;
use Kadupul\Platform\Contract\DatabaseConnection;

/**
 * Reads device template assignments from the legacy tables and saves changes through the assignment worker process.
 */
final readonly class LegacyDeviceTemplateAssignments implements DeviceTemplateAssignments
{
    /**
     * Binds the adapter to the database, the visibility rules and the project directory that holds the worker script.
     */
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility, private string $projectDir) {}
    /**
     * Returns the device's template assignment, or null when it does not exist, is deleted or is not visible to the actor.
     */
    public function findVisible(int $actorId, int $deviceId): ?DeviceTemplateAssignment
    {
        $query = $this->database->get()->prepare("SELECT DISTINCT h.id, h.description, h.host_template_id, h.poller_id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND h.deleted = '' AND (" . $this->visibility->predicate($actorId) . ')');
        $query->execute([$deviceId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row ? new DeviceTemplateAssignment((int) $row['id'], $row['description'], (int) $row['host_template_id'], (int) $row['poller_id']) : null;
    }
    /**
     * Lists the device templates, with names keyed by id and ordered by name.
     */
    public function templates(): array
    {
        return $this->database->get()->query('SELECT id, name FROM host_template WHERE id > 0 ORDER BY name, id')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
    /**
     * Saves the assignment in the assignment worker.
     *
     * @throws \InvalidArgumentException When the worker rejects the assignment as invalid.
     * @throws \Kadupul\Inventory\Domain\DeviceEditConflict When the device changed since $revision was issued.
     * @throws \Kadupul\Inventory\Application\Query\InventoryAccessDenied When the worker denies the actor.
     * @throws \RuntimeException When the outcome is unknown or could not be confirmed.
     */
    public function save(int $actorId, DeviceTemplateAssignment $assignment, string $revision): void
    {
        DeviceAssignmentProcess::run($this->database->get(), $this->projectDir, 'template', [
            'correlation_id' => bin2hex(random_bytes(16)),
            'actor' => $actorId,
            'id' => $assignment->id,
            'template_id' => $assignment->templateId(),
            'revision' => $revision,
        ]);
    }
}
