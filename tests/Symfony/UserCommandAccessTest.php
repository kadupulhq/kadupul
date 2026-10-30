<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\UserCommandAccess;
use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserCommandAccessTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];

    private function access(bool $collector = false, bool $manage = true, ?Actor $actor = new Actor(7, 'admin'), ?ConsoleOperator &$operator = null): UserCommandAccess
    {
        $operator = $this->createMock(ConsoleOperator::class);
        $operator->method('actor')->willReturn($actor);
        $operator->method('canManageUsers')->willReturn($manage);
        $topology = $this->createStub(CollectorTopology::class);
        $topology->method('isRemoteCollector')->willReturn($collector);
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return new UserCommandAccess($operator, $topology, $trail);
    }

    /** @return iterable<string, array{bool, bool, OperatorDatabase}> */
    public static function targets(): iterable
    {
        yield 'primary' => [false, false, OperatorDatabase::Local];
        yield 'primary with --local' => [false, true, OperatorDatabase::Local];
        yield 'collector' => [true, false, OperatorDatabase::Main];
        yield 'collector with --local' => [true, true, OperatorDatabase::Local];
    }

    #[DataProvider('targets')]
    public function testItWorksOnTheDatabaseTheOriginalScriptsUsed(bool $collector, bool $local, OperatorDatabase $expected): void
    {
        $scope = $this->access($collector, operator: $operator)->select('user.copy', $local, null, true);

        self::assertSame($expected, $scope->database);
        self::assertSame(7, $scope->actor->id);
        self::assertSame([], $this->events);
    }

    public function testItSelectsTheOperatorOnTheTargetDatabase(): void
    {
        $access = $this->access(true, operator: $operator);
        $operator->expects(self::once())->method('select')->with('ops', OperatorDatabase::Main);

        $access->select('user.copy', false, 'ops', true);
    }

    public function testAnOperatorWithoutUserManagementIsRefusedAndAudited(): void
    {
        $access = $this->access(manage: false);

        try {
            $access->select('user.copy', false, null, true);
            self::fail('The refusal was not raised.');
        } catch (UserManagementDenied $denied) {
            self::assertSame(7, $denied->actorId);
        }
        self::assertCount(1, $this->events);
        self::assertSame(['user.copy', 'database', 'local', 'denied', 'denied', 7], $this->fields($this->events[0]));
    }

    public function testADryRunRefusalCarriesTheDryRunSuffix(): void
    {
        try {
            $this->access(manage: false)->select('user.copy', false, null, false);
        } catch (UserManagementDenied) {
        }

        self::assertSame('user.copy.dry-run', $this->events[0]->action);
    }

    public function testAnUnknownOperatorIsRefusedWithoutAnActor(): void
    {
        try {
            $this->access(true, actor: null)->select('user.copy', false, 'nobody', true);
            self::fail('The refusal was not raised.');
        } catch (UserManagementDenied $denied) {
            self::assertNull($denied->actorId);
        }
        self::assertNull($this->events[0]->actorId);
        self::assertSame('main', $this->events[0]->targetId);
    }

    public function testAnEmptyOperatorNameIsRefusedInsteadOfFallingBackToTheAdminSetting(): void
    {
        $access = $this->access(operator: $operator);
        $operator->expects(self::never())->method('select');

        $this->expectException(UserManagementDenied::class);
        $access->select('user.copy', false, '', true);
    }

    public function testAChangeIsAuditedAgainstTheTargetDatabase(): void
    {
        $access = $this->access(true);
        $scope = $access->select('user.copy', false, null, true);

        $access->change($access->correlation(), $scope, 'user.copy', 'user', 'abc123', false);

        self::assertSame('main:abc123', $this->events[0]->targetId);
        self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
    }

    /** @return list<int|string|null> */
    private function fields(AuditEvent $event): array
    {
        return [$event->action, $event->targetType, $event->targetId, $event->decision, $event->outcome, $event->actorId];
    }
}
