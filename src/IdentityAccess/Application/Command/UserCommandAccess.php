<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/**
 * Target database, operator and audit for the command-line user tools. The
 * web page for the same actions, user_admin.php, needs realm 1; the operator
 * additionally needs console access (realm 8), which actor() checks.
 */
final readonly class UserCommandAccess
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
     * unless --local was given; everywhere else they used the local one. A
     * refusal is audited, with the dry-run suffix when $apply is false, then
     * rethrown. A dry run passes the same check because it reads the same rows.
     *
     * @param ?string $operator account to act as; null means the admin_user setting
     * @throws UserManagementDenied
     */
    public function select(string $action, bool $local, ?string $operator, bool $apply): UserCommandScope
    {
        $database = !$local && $this->topology->isRemoteCollector() ? OperatorDatabase::Main : OperatorDatabase::Local;
        try {
            // select() reads '' as "no name" and would fall back to admin_user.
            if ($operator === '') {
                throw new UserManagementDenied(null, $database);
            }
            $this->operator->select($operator, $database);
            $actor = $this->operator->actor();
            if ($actor === null || !$this->operator->canManageUsers($actor)) {
                throw new UserManagementDenied($actor?->id, $database);
            }
        } catch (UserManagementDenied $denied) {
            $this->record($this->correlation(), $denied->actorId, $apply ? $action : $action . '.dry-run', 'database', $database === OperatorDatabase::Main ? 'main' : 'local', AuditEvent::DENIED, AuditEvent::DENIED);

            throw $denied;
        }

        return new UserCommandScope($database, $actor);
    }

    /**
     * One record for a change an applied run tried. $type and $id name the
     * target; a caller passes only closed names and ids, never raw operator input.
     */
    public function change(string $correlation, UserCommandScope $scope, string $action, string $type, string $id, bool $ok): void
    {
        $this->record($correlation, $scope->actor->id, $action, $type, ($scope->database === OperatorDatabase::Main ? 'main' : 'local') . ':' . $id, AuditEvent::ALLOWED, $ok ? AuditEvent::SUCCEEDED : AuditEvent::FAILED);
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
