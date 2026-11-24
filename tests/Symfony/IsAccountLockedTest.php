<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Port\AccountLockoutStatus;
use Kadupul\IdentityAccess\Application\Query\IsAccountLocked;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsAccountLockedTest extends TestCase
{
    public static function decisions(): iterable
    {
        yield 'disabled' => [0, true, false];
        yield 'negative disabled' => [-1, true, false];
        yield 'locked' => [3, true, true];
        yield 'unlocked or absent' => [3, false, false];
    }

    #[DataProvider('decisions')]
    public function testDisabledPolicySkipsLookupAndEnabledPolicyUsesExactIdentity(int $limit, bool $persistedLock, bool $expected): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $status->expects($this->once())->method('failureLimit')->willReturn($limit);
        $status->expects($limit > 0 ? $this->once() : $this->never())
            ->method('hasLockedEnabledAccount')->with('alice', '1003')->willReturn($persistedLock);
        self::assertSame($expected, (new IsAccountLocked($status))('alice', '1003'));
    }

    public function testReusedQueryReadsCurrentPolicyAndAccountStateOnEveryInvocation(): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $status->expects($this->exactly(3))->method('failureLimit')->willReturnOnConsecutiveCalls(3, 3, 0);
        $status->expects($this->exactly(2))->method('hasLockedEnabledAccount')->with('alice', 0)->willReturnOnConsecutiveCalls(false, true);
        $query = new IsAccountLocked($status);
        self::assertFalse($query('alice', 0));
        self::assertTrue($query('alice', 0));
        self::assertFalse($query('alice', 0));
    }

    public function testLookupExceptionCannotBecomeAnUnlockedResult(): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $status->method('failureLimit')->willReturn(3);
        $failure = new \RuntimeException('lookup unavailable');
        $status->method('hasLockedEnabledAccount')->willThrowException($failure);
        $this->expectExceptionObject($failure);
        (new IsAccountLocked($status))('alice', 0);
    }

    public function testUnavailablePolicyNeverPerformsLookup(): void
    {
        $status = $this->createMock(AccountLockoutStatus::class);
        $failure = new \RuntimeException('settings unavailable');
        $status->method('failureLimit')->willThrowException($failure);
        $status->expects($this->never())->method('hasLockedEnabledAccount');
        $this->expectExceptionObject($failure);
        (new IsAccountLocked($status))('alice', 0);
    }
}
