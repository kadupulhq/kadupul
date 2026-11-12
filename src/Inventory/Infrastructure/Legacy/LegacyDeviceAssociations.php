<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceAssociationStore;
use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Inventory\Domain\DeviceAssociationChange;
use Kadupul\Platform\Contract\DatabaseConnection;

/**
 * Reads device associations from the legacy tables and applies changes through the assignment worker process.
 */
final readonly class LegacyDeviceAssociations implements DeviceAssociationStore
{
    /**
     * Binds the adapter to the database, the visibility rules, the association reader and the project directory that holds the worker script.
     */
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility, private DeviceAssociationRecords $records, private string $projectDir) {}
    /**
     * Returns the device's associations of the given kind, or null when it does not exist, is deleted or is not visible to the actor.
     */
    public function findVisible(int $actorId, int $id, string $kind): ?DeviceAssociations
    {
        $query = $this->database->get()->prepare("SELECT DISTINCT h.id, h.description, h.site_id, h.poller_id, h.host_template_id, h.snmp_version FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND h.deleted = '' AND (" . $this->visibility->predicate($actorId) . ')');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->records->snapshot($this->database->get(), $row, $kind) : null;
    }
    /**
     * Returns the configured reindex method (0 to 3), or 1 when none is saved or the saved value is invalid.
     */
    public function defaultReindexMethod(): int
    {
        $query = $this->database->get()->query("SELECT value FROM settings WHERE name = 'reindex_method'");
        $value = $query->fetchColumn();
        // Matches the legacy configuration default when no setting was saved.
        return in_array((string) $value, ['0', '1', '2', '3'], true) ? (int) $value : 1;
    }
    /**
     * Lists the associable graph templates or data queries, with names keyed by id.
     *
     * @return array Map from int to string.
     */
    public function available(string $kind, int $snmpVersion): array
    {
        return $this->records->available($this->database->get(), $kind, false, $snmpVersion);
    }
    /**
     * Applies the change in the assignment worker.
     *
     * @throws \InvalidArgumentException When the worker rejects the change as invalid.
     * @throws \Kadupul\Inventory\Domain\DeviceEditConflict When the device changed since $revision was issued.
     * @throws \Kadupul\Inventory\Application\Query\InventoryAccessDenied When the worker denies the actor.
     * @throws \RuntimeException When the outcome is unknown or could not be confirmed.
     */
    public function change(int $actorId, int $id, DeviceAssociationChange $change, string $revision): void
    {
        DeviceAssignmentProcess::run($this->database->get(), $this->projectDir, 'associations', ['actor' => $actorId, 'id' => $id, 'kind' => $change->kind, 'operation' => $change->operation, 'target' => $change->targetId, 'revision' => $revision, 'reindex' => $change->reindexMethod]);
    }
}
