<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\Command\MaintenanceRealm;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaintenanceTargetTest extends TestCase
{
    private function maintenance(bool $collector): DatabaseMaintenance
    {
        $maintenance = $this->createStub(DatabaseMaintenance::class);
        $maintenance->method('isRemoteCollector')->willReturn($collector);

        return $maintenance;
    }

    private function operator(?Actor $actor, bool $utilities, bool $upgrade): ConsoleOperator
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn($actor);
        $operator->method('canAdministerInstallation')->willReturn($utilities);
        $operator->method('canUpgradeInstallation')->willReturn($upgrade);

        return $operator;
    }

    #[DataProvider('grants')]
    public function testEachRealmChecksOnlyItsOwnGrant(MaintenanceRealm $realm, bool $utilities, bool $upgrade, bool $allowed): void
    {
        $target = new MaintenanceTarget($this->operator(new Actor(4, 'ops'), $utilities, $upgrade), $this->maintenance(false));
        if (!$allowed) {
            $this->expectException(InstallationAccessDenied::class);
        }
        $scope = $target->select(false, 'ops', $realm);
        self::assertSame([DatabaseTarget::Local, 4], [$scope->target, $scope->actor->id]);
    }

    /** @return iterable<string, array{MaintenanceRealm, bool, bool, bool}> */
    public static function grants(): iterable
    {
        yield 'utilities with realm 15' => [MaintenanceRealm::Utilities, true, false, true];
        yield 'utilities with only realm 26' => [MaintenanceRealm::Utilities, false, true, false];
        yield 'upgrade with realm 26' => [MaintenanceRealm::Upgrade, false, true, true];
        yield 'upgrade with only realm 15' => [MaintenanceRealm::Upgrade, true, false, false];
    }

    public function testACollectorOperatorIsCheckedOnMain(): void
    {
        $operator = $this->createMock(ConsoleOperator::class);
        $operator->expects(self::once())->method('select')->with('ops', OperatorDatabase::Main);
        $operator->method('actor')->willReturn(new Actor(4, 'ops'));
        $operator->method('canUpgradeInstallation')->willReturn(true);
        $scope = (new MaintenanceTarget($operator, $this->maintenance(true)))->select(false, 'ops', MaintenanceRealm::Upgrade);
        self::assertSame(DatabaseTarget::Main, $scope->target);
    }

    public function testARefusalCarriesTheActorAndTarget(): void
    {
        $target = new MaintenanceTarget($this->operator(new Actor(4, 'ops'), true, false), $this->maintenance(true));
        try {
            $target->select(false, 'ops', MaintenanceRealm::Upgrade);
            self::fail('Expected a refusal');
        } catch (InstallationAccessDenied $denied) {
            self::assertSame([4, DatabaseTarget::Main], [$denied->actorId, $denied->target]);
        }
    }

    public function testAnUnknownOperatorIsRefusedWithoutAnActor(): void
    {
        $target = new MaintenanceTarget($this->operator(null, true, true), $this->maintenance(false));
        try {
            $target->select(true, 'nobody', MaintenanceRealm::Upgrade);
            self::fail('Expected a refusal');
        } catch (InstallationAccessDenied $denied) {
            self::assertSame([null, DatabaseTarget::Local], [$denied->actorId, $denied->target]);
        }
    }

    public function testAnEmptyOperatorIsRefusedBeforeAnyLookup(): void
    {
        $operator = $this->createMock(ConsoleOperator::class);
        $operator->expects(self::never())->method(self::anything());
        $maintenance = $this->createMock(DatabaseMaintenance::class);
        $maintenance->expects(self::never())->method(self::anything());
        try {
            (new MaintenanceTarget($operator, $maintenance))->select(false, '', MaintenanceRealm::Upgrade);
            self::fail('Expected a refusal');
        } catch (InstallationAccessDenied $denied) {
            self::assertNull($denied->target);
        }
    }
}
