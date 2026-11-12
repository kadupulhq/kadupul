<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\SiteNotFound;
use Kadupul\Inventory\Application\Port\SiteEditor;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\Site;
use Kadupul\Platform\Contract\DatabaseConnection;

/**
 * Reads sites from the legacy tables for editing and saves them with an audit record.
 */
final readonly class LegacySiteEditor implements SiteEditor
{
    /**
     * Binds the editor to the database, console access and the site write audit.
     */
    public function __construct(private DatabaseConnection $database, private ConsoleAccess $access, private SiteWriteAudit $audit) {}

    /**
     * Returns the site, or null when it does not exist.
     */
    public function find(int $id): ?Site
    {
        $query = $this->database->get()->prepare('SELECT ' . SiteRecord::COLUMNS . ' FROM sites WHERE id = ? AND id > 0');
        $query->execute([$id]);
        $row = $query->fetch();
        return $row ? SiteRecord::hydrate($row) : null;
    }

    /**
     * Applies the edit to the locked stored site in one transaction.
     * The actor is rechecked inside the transaction and must be the signed-in user. An audit event is recorded whether or not the write succeeds.
     *
     * @throws InventoryAccessDenied When nobody is signed in, the user is not $userId, or the user may not manage devices.
     * @throws SiteNotFound When the site no longer exists.
     * @throws \Kadupul\Inventory\Domain\SiteEditConflict When the site changed since $expectedRevision was issued.
     * @throws \InvalidArgumentException When a value is invalid.
     * @throws \RuntimeException When the commit cannot be confirmed.
     */
    public function save(int $userId, Site $site, string $expectedRevision): void
    {
        $db = $this->database->get();
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $db->beginTransaction();
            $actor = $this->access->consoleActor();
            if ($actor === null || $actor->id !== $userId || !$this->access->canManageDevices($actor)) {
                throw new InventoryAccessDenied($actor === null);
            }
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
            $query = $db->prepare('SELECT ' . SiteRecord::COLUMNS . ' FROM sites WHERE id = ? AND id > 0 FOR UPDATE');
            $query->execute([$site->id]);
            $row = $query->fetch();
            if (!$row) {
                throw new SiteNotFound();
            }
            $current = SiteRecord::hydrate($row);
            $current->revise($site->name(), $site->notes(), $expectedRevision, $site->fields());
            $assignments = implode(', ', array_map(static fn(string $key): string => $key . ' = ?', array_keys($current->fields())));
            $update = $db->prepare('UPDATE sites SET ' . $assignments . ' WHERE id = ?');
            $update->execute([...array_values($current->fields()), $current->id]);
            if (!$db->commit()) {
                throw new \RuntimeException('Site commit could not be confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        } finally {
            $this->audit->record($userId, 'inventory.site.edit', [$site->id], $decision, $outcome);
        }
    }
}
