<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\ApplyLocalLoginPolicy;
use Kadupul\IdentityAccess\Application\Port\LocalLoginPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplyLocalLoginPolicyTest extends TestCase
{
    public static function decisions(): iterable
    {
        yield 'disabled complexity and activity' => [false, false, [], false, true, ['required', 'tracks']];
        yield 'disabled complexity records activity' => [false, false, [], true, true, ['required', 'tracks', 'record']];
        yield 'compliant password does not require change permission' => [true, true, [], true, true, ['required', 'complexity', 'tracks', 'record']];
        yield 'permitted change completes handoff before activity' => [true, false, ['id' => '0042', 'password_change' => 'on'], true, true, ['required', 'complexity', 'require-change', 'tracks', 'record']];
        yield 'native boolean permission' => [true, false, ['id' => '0042', 'password_change' => true], false, true, ['required', 'complexity', 'require-change', 'tracks']];
        yield 'permitted change without activity' => [true, false, ['id' => '0042', 'password_change' => 'on'], false, true, ['required', 'complexity', 'require-change', 'tracks']];
        yield 'prohibited change refuses before activity lookup' => [true, false, ['password_change' => ''], true, false, ['required', 'complexity', 'deny']];
        yield 'missing permission refuses' => [true, false, [], true, false, ['required', 'complexity', 'deny']];
        yield 'null permission refuses' => [true, false, ['password_change' => null], true, false, ['required', 'complexity', 'deny']];
        yield 'case-sensitive native permission' => [true, false, ['password_change' => 'ON'], true, false, ['required', 'complexity', 'deny']];
    }

    #[DataProvider('decisions')]
    public function testPolicyOrderingAndOutcome(bool $required, bool $compliant, array $account, bool $tracks, bool $expected, array $sequence): void
    {
        $events = [];
        $policy = $this->createMock(LocalLoginPolicy::class);
        $policy->method('requiresComplexity')->willReturnCallback(static function () use (&$events, $required): bool {
            $events[] = 'required';
            return $required;
        });
        $policy->method('satisfiesComplexity')->willReturnCallback(static function (string $password) use (&$events, $compliant): bool {
            self::assertSame('verified-input', $password);
            $events[] = 'complexity';
            return $compliant;
        });
        $policy->method('requirePasswordChange')->willReturnCallback(static function (mixed $id) use (&$events): void {
            self::assertSame('0042', $id);
            $events[] = 'require-change';
        });
        $policy->method('denyComplexity')->willReturnCallback(static function (string $username) use (&$events): void {
            self::assertSame('alice', $username);
            $events[] = 'deny';
        });
        $policy->method('tracksLastLogin')->willReturnCallback(static function () use (&$events, $tracks): bool {
            $events[] = 'tracks';
            return $tracks;
        });
        $policy->method('recordLastLogin')->willReturnCallback(static function (string $username) use (&$events): void {
            self::assertSame('alice', $username);
            $events[] = 'record';
        });
        self::assertSame($expected, (new ApplyLocalLoginPolicy($policy))('alice', 'verified-input', $account));
        self::assertSame($sequence, $events);
    }

    public static function failures(): iterable
    {
        yield ['requiresComplexity'];
        yield ['satisfiesComplexity'];
        yield ['requirePasswordChange'];
        yield ['tracksLastLogin'];
        yield ['recordLastLogin'];
    }

    #[DataProvider('failures')]
    public function testDependencyFailureCannotReportPolicyCompletion(string $stage): void
    {
        $policy = $this->createMock(LocalLoginPolicy::class);
        if ($stage !== 'requiresComplexity') {
            $policy->method('requiresComplexity')->willReturn(true);
        }
        if ($stage !== 'tracksLastLogin') {
            $policy->method('tracksLastLogin')->willReturn(true);
        }
        if ($stage !== 'recordLastLogin') {
            $policy->expects($this->never())->method('recordLastLogin');
        }
        $failure = new \RuntimeException('native dependency unavailable');
        $policy->method($stage)->willThrowException($failure);
        $this->expectExceptionObject($failure);
        (new ApplyLocalLoginPolicy($policy))('alice', 'verified-input', ['id' => 42, 'password_change' => 'on']);
    }
}
