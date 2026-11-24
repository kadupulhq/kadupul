<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\RecoverExpiredLockout;
use Kadupul\IdentityAccess\Application\Port\AccountLockoutRecovery;
use Kadupul\IdentityAccess\Application\Port\AccountLockoutStatus;
use Kadupul\IdentityAccess\Application\Port\Clock;
use PHPUnit\Framework\TestCase;

final class RecoverExpiredLockoutTest extends TestCase
{
    public function testExpiredLockoutClearsOnlyAfterTheStrictBoundary(): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $status->method('failureLimit')->willReturn(3);
        $recovery = $this->createMock(AccountLockoutRecovery::class);
        $recovery->method('find')->with('alice', 0)->willReturn(['lastfail' => 100, 'failed_attempts' => 3]);
        $recovery->method('unlockMinutes')->willReturn(1);
        $recovery->expects(self::once())->method('recordCheck')->with('alice', 61, 60);
        $recovery->expects(self::once())->method('clear')->with('alice', 0, 100, 3);
        $clock = new class implements Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@161');
            }
        };

        (new RecoverExpiredLockout($status, $recovery, $clock))('alice', 0);
    }

    public function testDisabledFailurePolicyDoesNotReadOrMutateAccount(): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $status->method('failureLimit')->willReturn(0);
        $recovery = $this->createMock(AccountLockoutRecovery::class);
        $recovery->expects(self::never())->method('find');
        $recovery->expects(self::never())->method('clear');
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::never())->method('now');

        (new RecoverExpiredLockout($status, $recovery, $clock))('alice', 0);
    }
}
