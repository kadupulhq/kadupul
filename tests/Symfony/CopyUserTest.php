<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\CopyUser;
use Kadupul\IdentityAccess\Application\Command\UserCommandAccess;
use Kadupul\IdentityAccess\Application\Command\UserCopyOutcome;
use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Application\Port\UserCopier;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use PHPUnit\Framework\TestCase;

final class CopyUserTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];
    /** @var list<string> */
    private array $copies = [];

    /**
     * @param list<string> $existing usernames present before the copy
     * @param bool $copyResult what the legacy copy returns
     * @param bool $createsUser whether a successful copy really leaves the new account behind
     */
    private function useCase(array $existing, bool $copyResult = true, bool $createsUser = true, bool $manage = true, bool $collector = false): CopyUser
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
        $copier = new class ($existing, $copyResult, $createsUser, $this->copies) implements UserCopier {
            /** @param list<string> $users @param list<string> $copies */
            public function __construct(private array $users, private bool $result, private bool $creates, private array &$copies) {}

            public function localUserExists(OperatorDatabase $database, string $username): bool
            {
                return in_array($username, $this->users, true);
            }

            public function copy(OperatorDatabase $database, string $template, string $new): bool
            {
                $this->copies[] = $database->name . ':' . $template . '>' . $new;
                if ($this->creates) {
                    $this->users[] = $new;
                }

                return $this->result;
            }
        };

        return new CopyUser(new UserCommandAccess($operator, $topology, $trail), $copier);
    }

    public function testItCopiesAndAuditsTheNewAccountByHash(): void
    {
        $report = ($this->useCase(['tpl']))('tpl', 'new user', false, null, true);

        self::assertSame(UserCopyOutcome::Copied, $report->outcome);
        self::assertSame(OperatorDatabase::Local, $report->database);
        self::assertFalse($report->dryRun);
        self::assertSame(['Local:tpl>new user'], $this->copies);
        self::assertSame('local:' . hash('sha256', 'new user'), $this->events[0]->targetId);
        self::assertSame(AuditEvent::SUCCEEDED, $this->events[0]->outcome);
    }

    public function testAMissingTemplateChangesNothing(): void
    {
        self::assertSame(UserCopyOutcome::TemplateMissing, ($this->useCase([]))('tpl', 'new', false, null, true)->outcome);
        self::assertSame([], $this->copies);
        self::assertSame([], $this->events);
    }

    public function testAnExistingNewUserIsNotOverwritten(): void
    {
        self::assertSame(UserCopyOutcome::NewUserExists, ($this->useCase(['tpl', 'new']))('tpl', 'new', false, null, true)->outcome);
        self::assertSame([], $this->copies);
    }

    public function testADryRunPlansWithoutCopyingOrAuditing(): void
    {
        self::assertSame(UserCopyOutcome::Planned, ($this->useCase(['tpl']))('tpl', 'new', false, null, false)->outcome);
        self::assertSame([], $this->copies);
        self::assertSame([], $this->events);
    }

    public function testAFailedCopyIsReportedAndAuditedAsFailed(): void
    {
        self::assertSame(UserCopyOutcome::NotCopied, ($this->useCase(['tpl'], false, false))('tpl', 'new', false, null, true)->outcome);
        self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
    }

    public function testASuccessWithoutTheNewAccountIsNotACopy(): void
    {
        // The original re-read the account after user_copy() and failed when it was absent.
        self::assertSame(UserCopyOutcome::NotCopied, ($this->useCase(['tpl'], true, false))('tpl', 'new', false, null, true)->outcome);
    }

    public function testACollectorCopiesOnTheMainDatabaseUnlessLocal(): void
    {
        ($this->useCase(['tpl'], collector: true))('tpl', 'a', false, null, true);
        ($this->useCase(['tpl'], collector: true))('tpl', 'b', true, null, true);

        self::assertSame(['Main:tpl>a', 'Local:tpl>b'], $this->copies);
    }

    public function testWithoutRealm1NothingIsReadOrCopied(): void
    {
        try {
            ($this->useCase(['tpl'], manage: false))('tpl', 'new', false, null, true);
            self::fail('The refusal was not raised.');
        } catch (UserManagementDenied) {
        }

        self::assertSame([], $this->copies);
        self::assertSame(AuditEvent::DENIED, $this->events[0]->outcome);
    }
}
