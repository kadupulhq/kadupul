<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\SiteNotFound;
use Kadupul\Inventory\Application\Port\SiteEditor;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\Site;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacySiteEditor implements SiteEditor
{
    public function __construct(private DatabaseConnection $database, private ConsoleAccess $access) {}

    public function find(int $id): ?Site
    {
        $query = $this->database->get()->prepare('SELECT id, name, notes FROM sites WHERE id = ? AND id > 0');
        $query->execute([$id]);
        $row = $query->fetch();
        return $row ? new Site((int) $row['id'], $row['name'], $row['notes'] ?? '') : null;
    }

    public function save(int $userId, Site $site, string $expectedRevision): void
    {
        $db = $this->database->get();
        $db->beginTransaction();
        try {
            $actor = $this->access->consoleActor();
            if ($actor === null || $actor->id !== $userId || !$this->access->canManageDevices($actor)) {
                throw new InventoryAccessDenied($actor === null);
            }
            $query = $db->prepare('SELECT id, name, notes FROM sites WHERE id = ? AND id > 0 FOR UPDATE');
            $query->execute([$site->id]);
            $row = $query->fetch();
            if (!$row) {
                throw new SiteNotFound();
            }
            $current = new Site((int) $row['id'], $row['name'], $row['notes'] ?? '');
            $current->revise($site->name(), $site->notes(), $expectedRevision);
            // Only migrated fields are updated. No procedural page or external
            // plugin effects exist in the legacy update-site workflow.
            $update = $db->prepare('UPDATE sites SET name = ?, notes = ? WHERE id = ?');
            $update->execute([$current->name(), $current->notes(), $current->id]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
