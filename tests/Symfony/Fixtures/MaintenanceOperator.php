<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Command\SchemaChangeAudit;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;

/** A maintenance use case's operator and audit, as stubs the test controls. */
trait MaintenanceOperator
{
    /** @var list<AuditEvent> */
    private array $events = [];

    /**
     * $upgrade is Installation/Upgrades (realm 26) and $utilities is
     * Settings/Utilities (realm 15). A null actor is an operator nobody knows.
     */
    private function maintenanceTarget(bool $collector = false, bool $upgrade = true, bool $utilities = true, ?Actor $actor = new Actor(1, 'admin')): MaintenanceTarget
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn($actor);
        $operator->method('canUpgradeInstallation')->willReturn($upgrade);
        $operator->method('canAdministerInstallation')->willReturn($utilities);
        $maintenance = $this->createStub(DatabaseMaintenance::class);
        $maintenance->method('isRemoteCollector')->willReturn($collector);

        return new MaintenanceTarget($operator, $maintenance);
    }

    private function recordingAudit(): SchemaChangeAudit
    {
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return new SchemaChangeAudit($trail);
    }
}
