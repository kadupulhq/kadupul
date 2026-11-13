<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\RecordLocalLoginFailure;
use Kadupul\IdentityAccess\Application\Port\AccountLockoutFailure;
use Kadupul\IdentityAccess\Application\Port\AccountLockoutStatus;
use PHPUnit\Framework\TestCase;

final class RecordLocalLoginFailureTest extends TestCase
{
    public function testDisabledPolicySkipsPersistence(): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $status->expects(self::once())->method('failureLimit')->willReturn(0);
        $failure = $this->createMock(AccountLockoutFailure::class);
        $failure->expects(self::never())->method('find');

        self::assertNull((new RecordLocalLoginFailure($status, $failure))('alice', 0, 123, '192.0.2.1'));
    }

    public function testUnknownAccountHasNoCounterOrAuditMutation(): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $status->method('failureLimit')->willReturn(3);
        $failure = $this->createMock(AccountLockoutFailure::class);
        $failure->expects(self::once())->method('find')->with('alice', '01')->willReturn(null);
        $failure->expects(self::never())->method('increment');
        $failure->expects(self::never())->method('recordAudit');

        $result = (new RecordLocalLoginFailure($status, $failure))('alice', '01', 123, '192.0.2.1');
        self::assertNotNull($result);
        self::assertFalse($result->accountFound);
    }

    public function testThresholdLocksBeforeWritingAuditAndReturnsLockedOutcome(): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $status->method('failureLimit')->willReturn(3);
        $events = [];
        $failure = $this->createMock(AccountLockoutFailure::class);
        $failure->expects(self::once())->method('find')->with('alice', 0)->willReturn(['id' => 42, 'enabled' => 'on', 'locked' => '']);
        $failure->expects(self::once())->method('increment')->with('alice', 0, 123)->willReturnCallback(static function () use (&$events): void {
            $events[] = 'increment';
        });
        $failure->expects(self::once())->method('failedAttempts')->with('alice', 0)->willReturnCallback(static function () use (&$events): int {
            $events[] = 'count';
            return 3;
        });
        $failure->expects(self::once())->method('recordProgress')->with('alice', 3, 3)->willReturnCallback(static function () use (&$events): void {
            $events[] = 'progress';
        });
        $failure->expects(self::once())->method('lock')->with('alice', 0)->willReturnCallback(static function () use (&$events): void {
            $events[] = 'lock';
        });
        $failure->expects(self::once())->method('recordAudit')->with('alice', 42, '192.0.2.1')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'audit';
        });

        $result = (new RecordLocalLoginFailure($status, $failure))('alice', 0, 123, '192.0.2.1');
        self::assertSame(['increment', 'count', 'progress', 'lock', 'audit'], $events);
        self::assertNotNull($result);
        self::assertTrue($result->locked);
        self::assertSame(3, $result->failedAttempts);
    }

    public function testDisabledAccountStillIncrementsButSkipsFailureAudit(): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $status->method('failureLimit')->willReturn(3);
        $failure = $this->createMock(AccountLockoutFailure::class);
        $failure->method('find')->willReturn(['id' => 42, 'enabled' => '', 'locked' => '']);
        $failure->expects(self::once())->method('increment')->with('alice', 0, 123);
        $failure->expects(self::never())->method('failedAttempts');
        $failure->expects(self::never())->method('recordProgress');
        $failure->expects(self::never())->method('recordAudit');

        $result = (new RecordLocalLoginFailure($status, $failure))('alice', 0, 123, '192.0.2.1');
        self::assertNotNull($result);
        self::assertTrue($result->disabled);
    }
}
