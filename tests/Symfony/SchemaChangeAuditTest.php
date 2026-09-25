<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\Command\MaintenanceRealm;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Tests\Fixtures\MaintenanceOperator;
use PHPUnit\Framework\TestCase;

final class SchemaChangeAuditTest extends TestCase
{
    use MaintenanceOperator;

    /** An operator who holds Settings/Utilities (realm 15) but not Installation/Upgrades (realm 26). */
    private function utilitiesOnly(): MaintenanceTarget
    {
        return $this->maintenanceTarget(false, false, true, new Actor(7, 'ops'));
    }

    public function testTheDefaultRealmIsStillInstallationUpgrades(): void
    {
        // The five-argument callers, ConvertTables and WidenIdColumns, must
        // keep requiring realm 26.
        try {
            $this->recordingAudit()->select($this->utilitiesOnly(), 'database.convert-tables', false, null, true);
            self::fail('A realm-15 operator passed the realm-26 check.');
        } catch (InstallationAccessDenied $denied) {
            self::assertSame(7, $denied->actorId);
        }
        self::assertCount(1, $this->events);
        self::assertSame(['database.convert-tables', 'database', 'local', AuditEvent::DENIED], [$this->events[0]->action, $this->events[0]->targetType, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testACallerCanNameTheUtilitiesRealm(): void
    {
        $scope = $this->recordingAudit()->select($this->utilitiesOnly(), 'database.audit', false, null, false, MaintenanceRealm::Utilities);

        self::assertSame([DatabaseTarget::Local, 7], [$scope->target, $scope->actor->id]);
        self::assertSame([], $this->events);
    }

    public function testAStepRecordsOneMaintenanceEvent(): void
    {
        $audit = $this->recordingAudit();
        $correlation = $audit->correlation();

        $audit->step($correlation, 7, 'database.audit', DatabaseTarget::Main, 'upgrade', true);
        $audit->step($correlation, 7, 'database.audit', DatabaseTarget::Local, 'audit-schema-export', false);

        self::assertSame([
            [$correlation, 7, 'database.audit', 'database-maintenance', 'main:upgrade', AuditEvent::ALLOWED, AuditEvent::SUCCEEDED],
            [$correlation, 7, 'database.audit', 'database-maintenance', 'local:audit-schema-export', AuditEvent::ALLOWED, AuditEvent::FAILED],
        ], array_map(static fn(AuditEvent $event): array => [$event->correlationId, $event->actorId, $event->action, $event->targetType, $event->targetId, $event->decision, $event->outcome], $this->events));
    }

    public function testAStepNameTheAuditCannotCarrySurfaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->recordingAudit()->step($this->recordingAudit()->correlation(), 7, 'database.audit', DatabaseTarget::Local, 'not a step', true);
    }
}
