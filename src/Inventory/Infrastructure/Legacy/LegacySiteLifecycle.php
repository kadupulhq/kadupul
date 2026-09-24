<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\SiteNotFound;
use Kadupul\Inventory\Application\Port\SiteLifecycle;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\NewSite;
use Kadupul\Inventory\Domain\Site;
use Kadupul\Inventory\Domain\SiteSelection;
use Kadupul\Inventory\Domain\SiteEditConflict;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacySiteLifecycle implements SiteLifecycle
{
    public function __construct(private DatabaseConnection $database, private ConsoleAccess $access, private SiteWriteAudit $audit) {}

    public function find(array $ids): array
    {
        return $this->load(SiteSelection::validateIds($ids), false);
    }

    private function load(array $ids, bool $lock): array
    {
        $query = $this->database->get()->prepare('SELECT ' . SiteRecord::COLUMNS . ' FROM sites WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute($ids);
        $rows = $query->fetchAll();
        if (count($rows) !== count($ids)) {
            throw new SiteNotFound();
        }
        return array_map(SiteRecord::hydrate(...), $rows);
    }

    public function delete(int $userId, SiteSelection $selection): void
    {
        $this->change($userId, $selection, null);
    }

    public function duplicate(int $userId, SiteSelection $selection, string $pattern): array
    {
        return $this->change($userId, $selection, $pattern);
    }

    private function change(int $userId, SiteSelection $selection, ?string $pattern): array
    {
        $db = $this->database->get();
        $ids = array_keys($selection->revisions);
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
            $sites = $this->load($ids, true);
            foreach ($sites as $site) {
                if (!hash_equals($site->revision(), $selection->revisions[$site->id])) {
                    throw new SiteEditConflict('This site changed. Reload it before saving.');
                }
            }
            $created = [];
            if ($pattern !== null) {
                // Validate every copy before inserting any of them.
                $copies = array_map(static fn(Site $site): NewSite => $site->duplicate($pattern), $sites);
                $insert = $db->prepare('INSERT INTO sites (' . implode(', ', array_keys(NewSite::DEFAULTS)) . ') VALUES (' . implode(', ', array_fill(0, count(NewSite::DEFAULTS), '?')) . ')');
                foreach ($copies as $copy) {
                    $insert->execute(array_values($copy->fields));
                    $id = (int) $db->lastInsertId();
                    if ($id < 1) {
                        throw new \RuntimeException('Site duplication could not be confirmed.');
                    }
                    $created[] = $id;
                }
            } else {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                // Preserve legacy semantics: only active devices are unassigned.
                $db->prepare("UPDATE host SET site_id = 0 WHERE deleted = '' AND site_id IN ($placeholders)")->execute($ids);
                $db->prepare("DELETE FROM sites WHERE id IN ($placeholders)")->execute($ids);
            }
            $mark = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
            $now = (string) time();
            foreach (['time_last_change_site', 'time_last_change_site_device'] as $name) {
                $mark->execute([$name, $now, $now]);
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Site operation commit could not be confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $created;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        } finally {
            // Duplication is recorded against each source site, deletion against each removed site.
            $this->audit->record($userId, $pattern === null ? 'inventory.site.delete' : 'inventory.site.duplicate', $ids, $decision, $outcome);
        }
    }
}
