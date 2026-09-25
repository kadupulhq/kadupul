<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;

/** The database a maintenance command works on, and the operator allowed to act there. */
final readonly class MaintenanceTarget
{
    public function __construct(private ConsoleOperator $operator, private DatabaseMaintenance $maintenance) {}

    /** @param ?string $operator account to act as; null means the admin_user setting */
    public function select(bool $local, ?string $operator, MaintenanceRealm $realm): MaintenanceScope
    {
        // select() reads '' as "no name" and would fall back to admin_user.
        if ($operator === '') {
            throw new InstallationAccessDenied();
        }
        // The cli/ scripts switched to the main database only on a remote
        // collector, and only when --local was not given; every other case,
        // including the primary with --local, stayed on the local connection.
        $target = !$local && $this->maintenance->isRemoteCollector() ? DatabaseTarget::Main : DatabaseTarget::Local;
        $this->operator->select($operator, $target === DatabaseTarget::Main ? OperatorDatabase::Main : OperatorDatabase::Local);
        $actor = $this->operator->actor();
        $allowed = $actor !== null && match ($realm) {
            MaintenanceRealm::Utilities => $this->operator->canAdministerInstallation($actor),
            MaintenanceRealm::Upgrade => $this->operator->canUpgradeInstallation($actor),
        };
        if (!$allowed) {
            throw new InstallationAccessDenied($actor?->id, $target);
        }

        return new MaintenanceScope($target, $actor);
    }
}
