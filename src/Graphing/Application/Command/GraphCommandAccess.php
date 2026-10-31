<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/**
 * Target database, operator and audit for the command-line graph tools. The
 * web pages for the same actions need realm 5 (graphs.php and graphs_new.php) or
 * realm 3 (host.php), see ManagedArea; the operator additionally needs console
 * access (realm 8), which actor() checks.
 */
final readonly class GraphCommandAccess
{
    public function __construct(
        private ConsoleOperator $operator,
        private CollectorTopology $topology,
        private AuditTrail $trail,
    ) {}

    /** One identifier ties together the records of a single run. */
    public function correlation(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The cli/ scripts switched to the main database on a remote collector
     * unless --local was given. A refusal is audited, with the dry-run suffix
     * when $apply is false, then rethrown. A dry run passes the same check
     * because it reads the same rows.
     *
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param ManagedArea $area the realm the matching web page needs besides console access
     * @throws GraphManagementDenied
     */
    public function select(string $action, bool $local, ?string $operator, bool $apply, ManagedArea $area = ManagedArea::Graphs): GraphCommandScope
    {
        $database = !$local && $this->topology->isRemoteCollector() ? OperatorDatabase::Main : OperatorDatabase::Local;
        try {
            // select() reads '' as "no name" and would fall back to admin_user.
            if ($operator === '') {
                throw new GraphManagementDenied(null, $database);
            }
            $this->operator->select($operator, $database);
            $actor = $this->operator->actor();
            if ($actor === null || !match ($area) {
                ManagedArea::Devices => $this->operator->canManageDevices($actor),
                ManagedArea::Trees => $this->operator->canManageTrees($actor),
                ManagedArea::Graphs => $this->operator->canManageGraphs($actor),
            }) {
                throw new GraphManagementDenied($actor?->id, $database);
            }
        } catch (GraphManagementDenied $denied) {
            $this->record($this->correlation(), $denied->actorId, $apply ? $action : $action . '.dry-run', 'database', $this->name($database), AuditEvent::DENIED, AuditEvent::DENIED);

            throw $denied;
        }

        return new GraphCommandScope($database, $actor);
    }

    /**
     * One record for a change an applied run tried. $type and $id name the
     * target; a caller passes only closed names and ids, never raw operator input.
     */
    public function change(string $correlation, GraphCommandScope $scope, string $action, string $type, string $id, bool $ok): void
    {
        $this->record($correlation, $scope->actor->id, $action, $type, $this->name($scope->database) . ':' . $id, AuditEvent::ALLOWED, $ok ? AuditEvent::SUCCEEDED : AuditEvent::FAILED);
    }

    private function name(OperatorDatabase $database): string
    {
        return $database === OperatorDatabase::Main ? 'main' : 'local';
    }

    private function record(string $correlation, ?int $actorId, string $action, string $type, string $target, string $decision, string $outcome): void
    {
        // Built outside the try: an event this class cannot express is a bug.
        $event = new AuditEvent($correlation, $actorId, $action, $type, $target, $decision, $outcome);
        try {
            $this->trail->record($event);
        } catch (\Throwable) {
            // As in SchemaChangeAudit: the sink must not replace a result.
        }
    }
}
