<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\IdentityAccess;

use Kadupul\IdentityAccess\Application\Command\AuthenticateDirectoryUser;
use Kadupul\IdentityAccess\Application\Command\AuthenticateLocalUser;
use Kadupul\IdentityAccess\Application\Command\ChangePassword;
use Kadupul\IdentityAccess\Application\Command\DirectoryLoginOutcome;
use Kadupul\IdentityAccess\Application\Command\LocalAuthenticationResult;
use Kadupul\IdentityAccess\Application\Command\PasswordChangeResult;
use Kadupul\IdentityAccess\Application\Command\RevokeAllUserSessions;
use Kadupul\IdentityAccess\Application\Port\AuthDomainRepository;
use Kadupul\IdentityAccess\Application\Port\Clock;
use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticationResult;
use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticator;
use Kadupul\IdentityAccess\Application\Port\LocalPasswordAccount;
use Kadupul\IdentityAccess\Application\Port\LoginRateLimitDecision;
use Kadupul\IdentityAccess\Application\Port\LoginRateLimiter;
use Kadupul\IdentityAccess\Application\Port\PasswordCredentialStore;
use Kadupul\IdentityAccess\Application\Port\PasswordVerifier;
use Kadupul\IdentityAccess\Application\Port\SessionCredentialRevoker;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Domain\AuthDomain;
use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\DirectoryType;
use Kadupul\IdentityAccess\Domain\LdapDirectoryConfig;
use Kadupul\IdentityAccess\Domain\PasswordComplexity;
use Kadupul\IdentityAccess\Domain\Policy\LockoutPolicy;
use Kadupul\IdentityAccess\Domain\Policy\PasswordComplexityPolicy;
use Kadupul\IdentityAccess\Domain\User;
use PHPUnit\Framework\TestCase;

final class ApplicationUseCasesTest extends TestCase
{
    public function testAuthenticateDirectoryUserRespectsRateLimitAndDomainRealm(): void
    {
        $domain = new AuthDomain(
            id: 4,
            name: 'Corp',
            type: DirectoryType::Ldap,
            ldapConfig: new LdapDirectoryConfig(server: 'ldap.example.internal', dnTemplate: 'uid=<username>,dc=example'),
        );

        $domains = $this->createStub(AuthDomainRepository::class);
        $domains->method('findEnabledById')->with(4)->willReturn($domain);

        $authenticator = $this->createMock(DirectoryAuthenticator::class);
        $authenticator->expects($this->never())->method('authenticate');

        $deniedLimiter = $this->createStub(LoginRateLimiter::class);
        $deniedLimiter->method('consume')->willReturn(LoginRateLimitDecision::deny(LoginRateLimitDecision::BUCKET_ADDRESS, 45));

        $useCase = new AuthenticateDirectoryUser($domains, $authenticator, $deniedLimiter);
        $blocked = $useCase('alice', 'secret', '192.0.2.5', 4);
        $this->assertTrue($blocked->isRateLimited());
        $this->assertSame(1004, $blocked->accountRealm);
        $this->assertSame(45, $blocked->retryAfterSeconds);

        $allowLimiter = $this->createMock(LoginRateLimiter::class);
        $allowLimiter->expects($this->once())->method('consume')->with('192.0.2.5', 'alice', 1004)->willReturn(LoginRateLimitDecision::allow());
        $allowLimiter->expects($this->once())->method('resetAccount')->with('alice', 1004);

        $okAuthenticator = $this->createMock(DirectoryAuthenticator::class);
        $okAuthenticator->expects($this->once())->method('authenticate')
            ->with($domain->ldapConfig, 'alice', 'secret')
            ->willReturn(DirectoryAuthenticationResult::authenticated('uid=alice,dc=example', 'Alice Admin', 'alice@example.internal'));

        $okUseCase = new AuthenticateDirectoryUser($domains, $okAuthenticator, $allowLimiter);
        $outcome = $okUseCase('alice', 'secret', '192.0.2.5', 4);
        $this->assertTrue($outcome->isAuthenticated());
        $this->assertSame(DirectoryLoginOutcome::STATUS_AUTHENTICATED, $outcome->status);
        $this->assertSame('uid=alice,dc=example', $outcome->resolvedDn);
        $this->assertSame('Alice Admin', $outcome->fullName);
    }

    public function testRevokeAllUserSessionsRotatesGenerationAndAudits(): void
    {
        $current = CredentialGeneration::generate();
        $recorded = [];
        $audit = new class ($recorded) implements AuditTrail {
            /** @param list<AuditEvent> $events */
            public function __construct(public array &$events) {}

            #[\Override]
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $clock = new class implements Clock {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-26T15:00:00Z');
            }
        };

        $revoker = $this->createMock(SessionCredentialRevoker::class);
        $revoker->expects($this->once())
            ->method('revokeAll')
            ->with(7, $current, $this->callback(static fn(CredentialGeneration $next): bool => !$current->matches($next)))
            ->willReturn(true);

        $useCase = new RevokeAllUserSessions($revoker, $audit, $clock);
        $result = $useCase(7, $current, 'logout_everywhere');

        $this->assertTrue($result->revoked);
        $this->assertNotNull($result->newGeneration);
        $this->assertFalse($current->matches($result->newGeneration));
        $this->assertNotNull($result->event);
        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEvent::SUCCEEDED, $recorded[0]->outcome);
    }

    public function testChangePasswordValidatesComplexityPreventsReuseAndRotatesGeneration(): void
    {
        $gen = CredentialGeneration::generate();
        $user = new User(id: 9, username: 'bob', accountRealm: 0, credentialGeneration: $gen, passwordChangeAllowed: true);
        $account = new LocalPasswordAccount($user, 'old-hash', false, $gen);

        $store = $this->createMock(PasswordCredentialStore::class);
        $store->method('findAccountById')->with(9)->willReturn($account);
        $store->method('complexityPolicy')->willReturn(new PasswordComplexityPolicy(true, 8, true, true, true));
        $store->expects($this->once())->method('changePasswordAndRotateGeneration')->willReturn(true);

        $verifier = $this->createStub(PasswordVerifier::class);
        $verifier->method('verify')->willReturnCallback(static fn(string $hash, string $plain): bool => $hash === 'old-hash' && $plain === 'OldPass1!');
        $verifier->method('hash')->willReturn('new-argon2-hash');

        $audit = $this->createMock(AuditTrail::class);
        $audit->expects($this->once())->method('record');

        $clock = new class implements Clock {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-26T15:00:00Z');
            }
        };

        $useCase = new ChangePassword($store, $verifier, new PasswordComplexity(), $audit, $clock);

        $badCurrent = $useCase(9, 'wrong', 'NewPass2!');
        $this->assertSame(PasswordChangeResult::STATUS_CURRENT_PASSWORD_INVALID, $badCurrent->status);

        $weak = $useCase(9, 'OldPass1!', 'weak');
        $this->assertSame(PasswordChangeResult::STATUS_COMPLEXITY_VIOLATION, $weak->status);

        $reused = $useCase(9, 'OldPass1!', 'OldPass1!');
        $this->assertSame(PasswordChangeResult::STATUS_PASSWORD_REUSED, $reused->status);

        $ok = $useCase(9, 'OldPass1!', 'StrongPass2!');
        $this->assertTrue($ok->isChanged());
        $this->assertNotNull($ok->newGeneration);
        $this->assertNotNull($ok->event);
    }

    public function testAuthenticateLocalUserHandlesPaddingRehashAndForcedChange(): void
    {
        $gen = CredentialGeneration::generate();
        $user = new User(id: 11, username: 'carol', accountRealm: 0, credentialGeneration: $gen, passwordChangeAllowed: true);
        $account = new LocalPasswordAccount($user, 'legacy-stored-hash', false, $gen);

        $store = $this->createMock(PasswordCredentialStore::class);
        $store->method('findLocalAccountByUsername')->willReturnMap([
            ['missing', null],
            ['carol', $account],
        ]);
        $store->method('lockoutPolicy')->willReturn(new LockoutPolicy(5, 30));
        $store->method('complexityPolicy')->willReturn(new PasswordComplexityPolicy(true, 8, true, true, true));
        $store->expects($this->once())->method('updatePasswordPreservingSessions')->with(11, 'legacy-stored-hash', 'upgraded-hash')->willReturn(true);
        $store->expects($this->once())->method('requirePasswordChange')->with(11);
        $store->expects($this->once())->method('recordLastLogin')->with(11);

        $verifier = $this->createMock(PasswordVerifier::class);
        $verifier->expects($this->once())->method('padUnknownUserTiming');
        $verifier->method('verify')->willReturnCallback(static fn(string $hash, string $plain): bool => $hash === 'legacy-stored-hash' && $plain === 'weak');
        $verifier->method('needsRehash')->willReturn(true);
        $verifier->method('hash')->willReturn('upgraded-hash');

        $limiter = $this->createMock(LoginRateLimiter::class);
        $limiter->method('consume')->willReturn(LoginRateLimitDecision::allow());
        $limiter->expects($this->once())->method('resetAccount')->with('carol', 0);

        $clock = new class implements Clock {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-26T15:00:00Z');
            }
        };

        $useCase = new AuthenticateLocalUser($store, $verifier, new PasswordComplexity(), $limiter, $clock);

        $missing = $useCase('missing', 'anything', '192.0.2.9');
        $this->assertSame(LocalAuthenticationResult::STATUS_INVALID_CREDENTIALS, $missing->status);

        $result = $useCase('carol', 'weak', '192.0.2.9');
        $this->assertSame(LocalAuthenticationResult::STATUS_PASSWORD_CHANGE_REQUIRED, $result->status);
        $this->assertTrue($result->allowsSessionEstablishment());
        $this->assertNotNull($result->event);
    }
}
