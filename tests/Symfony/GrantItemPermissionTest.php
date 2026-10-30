<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\GrantItemPermission;
use Kadupul\IdentityAccess\Application\Command\GrantOutcome;
use Kadupul\IdentityAccess\Application\Command\UserCommandAccess;
use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Domain\ItemPermissionRequest;
use Kadupul\IdentityAccess\Domain\ItemType;
use Kadupul\Tests\Fixtures\InMemoryItemPermissions;
use PHPUnit\Framework\TestCase;

final class GrantItemPermissionTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];

    private function useCase(InMemoryItemPermissions $permissions, bool $manage = true, bool $collector = false): GrantItemPermission
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn(new Actor(3, 'ops'));
        $operator->method('canManageUsers')->willReturn($manage);
        $topology = $this->createStub(CollectorTopology::class);
        $topology->method('isRemoteCollector')->willReturn($collector);
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return new GrantItemPermission(new UserCommandAccess($operator, $topology, $trail), $permissions);
    }

    private function request(?string $user = '7', ?ItemType $type = ItemType::Host, ?string $item = '5'): ItemPermissionRequest
    {
        return ItemPermissionRequest::parse($user, $type, $item);
    }

    public function testItGrantsAndAuditsTheGrant(): void
    {
        $permissions = new InMemoryItemPermissions();
        $report = ($this->useCase($permissions))($this->request(), false, null, true);

        self::assertSame(GrantOutcome::Granted, $report->outcome);
        self::assertSame(['Local:7:3:5'], $permissions->grants);
        self::assertSame(['permission.grant', 'user-permission', 'local:7:3:5', AuditEvent::SUCCEEDED], [$this->events[0]->action, $this->events[0]->targetType, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    /**
     * Each request has one fault, or two where the original reported the first.
     *
     * @return iterable<string, array{ItemPermissionRequest, GrantOutcome}>
     */
    public static function refusals(): iterable
    {
        yield 'an unknown user' => [ItemPermissionRequest::parse('8', ItemType::Host, '5'), GrantOutcome::UserInvalid];
        yield 'a user that is not a number' => [ItemPermissionRequest::parse('abc', ItemType::Host, '5'), GrantOutcome::UserInvalid];
        yield 'no item type' => [ItemPermissionRequest::parse('7', null, '5'), GrantOutcome::ItemTypeMissing];
        yield 'no item id' => [ItemPermissionRequest::parse('7', ItemType::Host, null), GrantOutcome::ItemIdMissing];
        yield 'an item that does not exist' => [ItemPermissionRequest::parse('7', ItemType::Host, '6'), GrantOutcome::ItemInvalid];
        yield 'an item of another type' => [ItemPermissionRequest::parse('7', ItemType::Tree, '5'), GrantOutcome::ItemInvalid];
        yield 'an item that is not a number' => [ItemPermissionRequest::parse('7', ItemType::Host, 'abc'), GrantOutcome::ItemInvalid];
        yield 'a bad user hides a missing type' => [ItemPermissionRequest::parse('8', null, null), GrantOutcome::UserInvalid];
        yield 'a missing type hides a missing id' => [ItemPermissionRequest::parse('7', null, null), GrantOutcome::ItemTypeMissing];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testItReportsTheFirstFaultAndGrantsNothing(ItemPermissionRequest $request, GrantOutcome $expected): void
    {
        $permissions = new InMemoryItemPermissions();

        self::assertSame($expected, ($this->useCase($permissions))($request, false, null, true)->outcome);
        self::assertSame([], $permissions->grants);
        self::assertSame([], $this->events);
    }

    public function testWithoutAUserItValidatesTheItemAndGrantsNothing(): void
    {
        $permissions = new InMemoryItemPermissions();
        $report = ($this->useCase($permissions))($this->request(null), false, null, true);

        self::assertSame(GrantOutcome::NoUser, $report->outcome);
        self::assertTrue($report->succeeded());
        self::assertSame([], $permissions->grants);
        self::assertSame(GrantOutcome::ItemInvalid, ($this->useCase($permissions))($this->request(null, ItemType::Host, '6'), false, null, true)->outcome);
    }

    public function testADryRunChecksEverythingAndWritesNothing(): void
    {
        $permissions = new InMemoryItemPermissions();
        $report = ($this->useCase($permissions))($this->request(), false, null, false);

        self::assertSame([GrantOutcome::Planned, true], [$report->outcome, $report->dryRun]);
        self::assertSame([], $permissions->grants);
        self::assertSame([], $this->events);
    }

    public function testARefusedWriteIsReportedAndAuditedAsFailed(): void
    {
        $report = ($this->useCase(new InMemoryItemPermissions(result: false)))($this->request(), false, null, true);

        self::assertSame(GrantOutcome::NotGranted, $report->outcome);
        self::assertFalse($report->succeeded());
        self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
    }

    public function testACollectorGrantsOnTheMainDatabaseUnlessLocal(): void
    {
        $permissions = new InMemoryItemPermissions();
        ($this->useCase($permissions, collector: true))($this->request(), false, null, true);
        ($this->useCase($permissions, collector: true))($this->request(), true, null, true);

        self::assertSame(['Main:7:3:5', 'Local:7:3:5'], $permissions->grants);
    }

    public function testWithoutRealm1NothingIsReadOrGranted(): void
    {
        $permissions = new InMemoryItemPermissions();
        try {
            ($this->useCase($permissions, manage: false))($this->request(), false, null, true);
            self::fail('The refusal was not raised.');
        } catch (UserManagementDenied) {
        }

        self::assertSame([], $permissions->grants);
        self::assertSame(AuditEvent::DENIED, $this->events[0]->outcome);
    }
}
