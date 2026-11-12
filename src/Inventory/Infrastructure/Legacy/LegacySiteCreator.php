<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteCreator;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\NewSite;
use Kadupul\Platform\Contract\DatabaseConnection;

/**
 * Creates sites in the legacy tables and records the attempt in the audit trail.
 */
final readonly class LegacySiteCreator implements SiteCreator
{
    /**
     * Binds the creator to the database, console access and the site write audit.
     */
    public function __construct(private DatabaseConnection $database, private ConsoleAccess $access, private SiteWriteAudit $audit) {}

    /**
     * Inserts the site in one transaction and returns its id.
     * The actor is rechecked inside the transaction and must be the signed-in user. The site-change cache markers are committed with the site. An audit event is recorded whether or not the write succeeds.
     *
     * @throws InventoryAccessDenied When nobody is signed in, the user is not $userId, or the user may not manage devices.
     * @throws \RuntimeException When the insert or commit cannot be confirmed.
     */
    public function create(int $userId, NewSite $site): int
    {
        $db = $this->database->get();
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        $target = 'new';
        try {
            $db->beginTransaction();
            $actor = $this->access->consoleActor();
            if ($actor === null || $actor->id !== $userId || !$this->access->canManageDevices($actor)) {
                throw new InventoryAccessDenied($actor === null);
            }
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
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
            $outcome = AuditEvent::SUCCEEDED;
            $target = $id;

            return $id;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        } finally {
            $this->audit->record($userId, 'inventory.site.create', [$target], $decision, $outcome);
        }
    }
}
