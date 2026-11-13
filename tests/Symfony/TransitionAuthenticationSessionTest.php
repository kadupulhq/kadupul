<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\TransitionAuthenticationSession;
use Kadupul\IdentityAccess\Application\Port\AuthenticationTransitionAccounts;
use Kadupul\IdentityAccess\Application\Port\AuthenticationTransitionSession;
use PHPUnit\Framework\TestCase;

final class TransitionAuthenticationSessionTest extends TestCase
{
    public function testLoginChecksEligibilityBeforeRotatingAndBinding(): void
    {
        $events = [];
        $accounts = $this->createMock(AuthenticationTransitionAccounts::class);
        $accounts->expects(self::once())->method('find')->with(42)->willReturn(['enabled' => 'on', 'locked' => '', 'password' => 'stored']);
        $session = $this->createMock(AuthenticationTransitionSession::class);
        $session->expects(self::once())->method('takeCookieCredential')->willReturnCallback(static function () use (&$events): null {
            $events[] = 'take';
            return null;
        });
        $session->expects(self::once())->method('isActive')->willReturnCallback(static function () use (&$events): bool {
            $events[] = 'active';
            return true;
        });
        $session->expects(self::once())->method('regenerate')->willReturnCallback(static function () use (&$events): bool {
            $events[] = 'regenerate';
            return true;
        });
        $session->expects(self::once())->method('clearPermissionCaches')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'clear';
        });
        $session->expects(self::once())->method('bindCredential')->with(42)->willReturnCallback(static function () use (&$events): void {
            $events[] = 'bind';
        });
        $session->expects(self::once())->method('logCompleted')->with(42, 'login')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'log';
        });

        self::assertTrue((new TransitionAuthenticationSession($accounts, $session))(42, 'login'));
        self::assertSame(['take', 'active', 'regenerate', 'clear', 'bind', 'log'], $events);
    }

    public function testLockedAccountIsRefusedBeforeAnySessionMutation(): void
    {
        $accounts = $this->createMock(AuthenticationTransitionAccounts::class);
        $accounts->method('find')->willReturn(['enabled' => 'on', 'locked' => 'on', 'password' => 'stored']);
        $session = $this->createMock(AuthenticationTransitionSession::class);
        $session->method('takeCookieCredential')->willReturn(null);
        $session->expects(self::once())->method('logBlocked')->with(42, 'basic_auth');
        $session->expects(self::never())->method('regenerate');
        $session->expects(self::never())->method('clearPermissionCaches');
        $session->expects(self::never())->method('bindCredential');

        self::assertFalse((new TransitionAuthenticationSession($accounts, $session))(42, 'basic_auth'));
    }

    public function testDisabledGuestRetainsTheNativeException(): void
    {
        $accounts = $this->createMock(AuthenticationTransitionAccounts::class);
        $accounts->method('find')->willReturn(['enabled' => '', 'locked' => '', 'password' => '']);
        $accounts->method('guestId')->willReturn(7);
        $session = $this->createMock(AuthenticationTransitionSession::class);
        $session->method('takeCookieCredential')->willReturn(null);
        $session->method('isActive')->willReturn(false);
        $session->expects(self::never())->method('regenerate');
        $session->expects(self::once())->method('bindCredential')->with(7);

        self::assertTrue((new TransitionAuthenticationSession($accounts, $session))(7, 'guest'));
    }

    public function testCookieRestoreRequiresTheExactAdmittedBindingAndKeepsIt(): void
    {
        $generation = str_repeat('a', 64);
        $accounts = $this->createMock(AuthenticationTransitionAccounts::class);
        $accounts->method('find')->willReturn(['enabled' => 'on', 'locked' => '', 'password' => 'stored']);
        $accounts->expects(self::once())->method('credentialGeneration')->with(42, 'stored')->willReturn($generation);
        $session = $this->createMock(AuthenticationTransitionSession::class);
        $session->method('takeCookieCredential')->willReturn(['user_id' => 42, 'generation' => $generation]);
        $session->method('isActive')->willReturn(true);
        $session->expects(self::once())->method('regenerate')->willReturn(true);
        $session->expects(self::once())->method('bindRestoredCredential')->with($generation);
        $session->expects(self::never())->method('bindCredential');

        self::assertTrue((new TransitionAuthenticationSession($accounts, $session))(42, 'cookie_restore'));
    }

    public function testCookieCredentialMismatchRefusesBeforeRotation(): void
    {
        $accounts = $this->createMock(AuthenticationTransitionAccounts::class);
        $accounts->method('find')->willReturn(['enabled' => 'on', 'locked' => '', 'password' => 'stored']);
        $accounts->expects(self::never())->method('credentialGeneration');
        $session = $this->createMock(AuthenticationTransitionSession::class);
        $session->method('takeCookieCredential')->willReturn(['user_id' => 9, 'generation' => str_repeat('a', 64)]);
        $session->expects(self::never())->method('regenerate');
        $session->expects(self::never())->method('clearPermissionCaches');

        self::assertFalse((new TransitionAuthenticationSession($accounts, $session))(42, 'cookie_restore'));
    }

    public function testFailedSessionRegenerationDoesNotBindAnIdentity(): void
    {
        $accounts = $this->createMock(AuthenticationTransitionAccounts::class);
        $accounts->method('find')->willReturn(['enabled' => 'on', 'locked' => '', 'password' => 'stored']);
        $session = $this->createMock(AuthenticationTransitionSession::class);
        $session->method('takeCookieCredential')->willReturn(null);
        $session->method('isActive')->willReturn(true);
        $session->method('regenerate')->willReturn(false);
        $session->expects(self::once())->method('logTransitionFailure')->with(42, 'login');
        $session->expects(self::never())->method('clearPermissionCaches');
        $session->expects(self::never())->method('bindCredential');
        $session->expects(self::never())->method('logCompleted');

        self::assertFalse((new TransitionAuthenticationSession($accounts, $session))(42, 'login'));
    }
}
