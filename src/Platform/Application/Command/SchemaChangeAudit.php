<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Application\Port\DatabaseTarget;

/**
 * Audit events for command-line schema changes: one per table an applied run
 * tried to change, one per refusal. AuditEvent is closed, so the target
 * database goes into the target id and a dry run into the action name. A dry
 * run tries no change, so only a refusal can carry the dry-run suffix.
 */
final readonly class SchemaChangeAudit
{
    public function __construct(private AuditTrail $trail) {}

    /** One identifier ties together the records of a single run. */
    public function correlation(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The target and operator for a schema change, with realm 26 required. A
     * refusal is audited, with the dry-run suffix when $apply is false, then
     * rethrown. A dry run passes the same check: it reads the same schema.
     */
    public function select(MaintenanceTarget $target, string $action, bool $local, ?string $operator, bool $apply): MaintenanceScope
    {
        try {
            return $target->select($local, $operator, MaintenanceRealm::Upgrade);
        } catch (InstallationAccessDenied $denied) {
            $this->denied($action, $denied, !$apply);

            throw $denied;
        }
    }

    public function denied(string $action, InstallationAccessDenied $denied, bool $dryRun): void
    {
        $this->record(
            $this->correlation(),
            $denied->actorId,
            $dryRun ? $action . '.dry-run' : $action,
            'database',
            $denied->target?->value ?? 'unselected',
            AuditEvent::DENIED,
            AuditEvent::DENIED,
        );
    }

    public function statement(string $correlation, int $actorId, string $action, DatabaseTarget $target, string $table, bool $ok): void
    {
        // A name the audit id cannot carry is recorded by its hash, so no
        // attempt goes unrecorded and the id stays within AuditEvent's rules.
        [$type, $id] = preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $table) === 1
            ? ['database-table', $table]
            : ['database-table-sha256', hash('sha256', $table)];
        $this->record($correlation, $actorId, $action, $type, $target->value . ':' . $id, AuditEvent::ALLOWED, $ok ? AuditEvent::SUCCEEDED : AuditEvent::FAILED);
    }

    private function record(string $correlation, ?int $actorId, string $action, string $type, string $target, string $decision, string $outcome): void
    {
        // Built outside the try: an event this class cannot express is a bug
        // and must surface, not vanish into the best-effort catch.
        $event = new AuditEvent($correlation, $actorId, $action, $type, $target, $decision, $outcome);
        try {
            $this->trail->record($event);
        } catch (\Throwable) {
            // As in SiteWriteAudit: the transitional sink must not replace a
            // statement's result.
        }
    }
}
