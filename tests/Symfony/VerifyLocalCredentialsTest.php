<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\VerifyLocalCredentials;
use Kadupul\IdentityAccess\Application\Port\LocalCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerifyLocalCredentialsTest extends TestCase
{
    public static function decisions(): iterable
    {
        $verified = ['id' => '42', 'password' => 'verified'];
        $current = $verified + ['username' => 'alice', 'realm' => '0', 'plugin_field' => 'retained'];
        yield 'confirmed with unchanged native fields' => [$verified, false, $current, true, $current, ['verify', 'failed', 'reload', 'rehash']];
        yield 'eligibility remains with the session issuer' => [$verified, false, $current + ['enabled' => ''], true, $current + ['enabled' => ''], ['verify', 'failed', 'reload', 'rehash']];
        yield 'empty native result' => [[], false, [], true, [], ['verify']];
        yield 'native policy already denied' => [$verified, true, $current, true, $verified, ['verify', 'failed']];
        yield 'account removed after verification' => [$verified, false, [], true, [], ['verify', 'failed', 'reload', 'deny']];
        yield 'credential changed after verification' => [$verified, false, ['password' => 'replacement'], true, [], ['verify', 'failed', 'reload', 'deny']];
        yield 'case-only credential change' => [$verified, false, ['password' => 'Verified'], true, [], ['verify', 'failed', 'reload', 'deny']];
        yield 'trailing-space credential change' => [$verified, false, ['password' => 'verified '], true, [], ['verify', 'failed', 'reload', 'deny']];
        yield 'unconfirmed rehash' => [$verified, false, $current, false, [], ['verify', 'failed', 'reload', 'rehash', 'deny']];
        yield 'missing verified hash' => [['id' => '42'], false, $current, true, [], ['verify', 'failed', 'reload', 'deny']];
        yield 'malformed verified hash' => [['password' => ['invalid']], false, $current, true, [], ['verify', 'failed', 'reload', 'deny']];
        yield 'malformed current hash' => [$verified, false, ['password' => null], true, [], ['verify', 'failed', 'reload', 'deny']];
    }

    #[DataProvider('decisions')]
    public function testVerificationAndRehashKeepTheNativeOutcome(array $verified, bool $failed, array $current, bool $rehash, array $expected, array $sequence): void
    {
        $events = [];
        $backend = $this->createMock(LocalCredentials::class);
        $backend->method('verifyPassword')->willReturnCallback(static function (string $username) use (&$events, $verified): array {
            self::assertSame('alice', $username);
            $events[] = 'verify';
            return $verified;
        });
        $backend->method('verificationFailed')->willReturnCallback(static function () use (&$events, $failed): bool {
            $events[] = 'failed';
            return $failed;
        });
        $backend->method('reload')->willReturnCallback(static function (string $username) use (&$events, $current): array {
            self::assertSame('alice', $username);
            $events[] = 'reload';
            return $current;
        });
        $backend->method('rehashIfNeeded')->willReturnCallback(static function (array $account, string $hash) use (&$events, $current, $rehash): bool {
            self::assertSame($current, $account);
            self::assertSame('verified', $hash);
            $events[] = 'rehash';
            return $rehash;
        });
        $backend->method('deny')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'deny';
        });
        self::assertSame($expected, (new VerifyLocalCredentials($backend))('alice'));
        self::assertSame($sequence, $events);
    }

    public static function failedStages(): iterable
    {
        yield ['verifyPassword'];
        yield ['reload'];
        yield ['rehashIfNeeded'];
    }

    #[DataProvider('failedStages')]
    public function testDependencyExceptionsCannotProduceAnAccount(string $stage): void
    {
        $backend = $this->createMock(LocalCredentials::class);
        if ($stage !== 'verifyPassword') {
            $backend->method('verifyPassword')->willReturn(['password' => 'verified']);
        }
        if ($stage !== 'reload') {
            $backend->method('reload')->willReturn(['password' => 'verified']);
        }
        $failure = new \RuntimeException('unavailable');
        $backend->method($stage)->willThrowException($failure);
        $backend->expects($this->never())->method('deny');
        $this->expectExceptionObject($failure);
        (new VerifyLocalCredentials($backend))('alice');
    }
}
