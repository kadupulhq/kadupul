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

final readonly class LegacyDeviceAssociations implements DeviceAssociationStore
{
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility, private DeviceAssociationRecords $records, private string $projectDir) {}
    public function findVisible(int $actorId, int $id, string $kind): ?DeviceAssociations
    {
        $query = $this->database->get()->prepare("SELECT DISTINCT h.id, h.description, h.site_id, h.poller_id, h.host_template_id, h.snmp_version FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id = ? AND h.deleted = '' AND (" . $this->visibility->predicate($actorId) . ')');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->records->snapshot($this->database->get(), $row, $kind) : null;
    }
    public function defaultReindexMethod(): int
    {
        $query = $this->database->get()->query("SELECT value FROM settings WHERE name = 'reindex_method'");
        $value = $query->fetchColumn();
        // Matches the legacy configuration default when no setting was saved.
        return in_array((string) $value, ['0', '1', '2', '3'], true) ? (int) $value : 1;
    }
    public function available(string $kind): array
    {
        return $this->records->available($this->database->get(), $kind);
    }
    public function change(int $actorId, int $id, DeviceAssociationChange $change, string $revision): void
    {
        DeviceAssignmentProcess::run($this->database->get(), $this->projectDir, 'associations', ['actor' => $actorId, 'id' => $id, 'kind' => $change->kind, 'operation' => $change->operation, 'target' => $change->targetId, 'revision' => $revision, 'reindex' => $change->reindexMethod]);
    }
}
