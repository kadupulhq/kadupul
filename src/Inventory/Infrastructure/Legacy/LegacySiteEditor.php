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

final readonly class LegacySiteEditor implements SiteEditor
{
    public function __construct(private DatabaseConnection $database, private ConsoleAccess $access, private SiteWriteAudit $audit) {}

    public function find(int $id): ?Site
    {
        $query = $this->database->get()->prepare('SELECT ' . SiteRecord::COLUMNS . ' FROM sites WHERE id = ? AND id > 0');
        $query->execute([$id]);
        $row = $query->fetch();
        return $row ? SiteRecord::hydrate($row) : null;
    }

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
