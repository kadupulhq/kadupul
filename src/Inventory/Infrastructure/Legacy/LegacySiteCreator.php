<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteCreator;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\NewSite;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacySiteCreator implements SiteCreator
{
    public function __construct(private DatabaseConnection $database, private ConsoleAccess $access) {}

    public function create(int $userId, NewSite $site): int
    {
        $db = $this->database->get();
        $db->beginTransaction();
        try {
            $actor = $this->access->consoleActor();
            if ($actor === null || $actor->id !== $userId || !$this->access->canManageDevices($actor)) {
                throw new InventoryAccessDenied($actor === null);
            }
            $query = $db->prepare('INSERT INTO sites (name, address1, address2, city, state, postal_code, country, timezone, latitude, longitude, zoom, alternate_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute(array_values($site->fields));
            $id = (int) $db->lastInsertId();
            if ($id < 1) {
                throw new \RuntimeException('Site creation did not return an identifier.');
            }
            // Match the legacy creation effects, committing the cache markers with the site.
            $mark = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
            $now = (string) time();
            foreach (['time_last_change_site', 'time_last_change_site_device'] as $name) {
                $mark->execute([$name, $now, $now]);
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Site creation commit could not be confirmed.');
            }

            return $id;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
