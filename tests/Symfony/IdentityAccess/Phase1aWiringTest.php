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
use Kadupul\IdentityAccess\Application\Command\RevokeAllUserSessions;
use Kadupul\IdentityAccess\Application\Port\AuthDomainRepository;
use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticator;
use Kadupul\IdentityAccess\Application\Port\LoginRateLimiter;
use Kadupul\IdentityAccess\Application\Port\PasswordCredentialStore;
use Kadupul\IdentityAccess\Application\Port\PasswordVerifier;
use Kadupul\IdentityAccess\Application\Port\RealmAccessChecker;
use Kadupul\IdentityAccess\Application\Port\SessionCredentialRevoker;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\RealmVoter;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\ResourceAccessVoter;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Validation\SymfonyIdentityInputValidator;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Verifies all Phase 1a IdentityAccess ports, use cases, LDAP/rate-limiter/validator
 * adapters, and Security voters resolve from the compiled Symfony container without
 * opening a database connection.
 */
final class Phase1aWiringTest extends TestCase
{
    private Kernel $kernel;
    private ContainerInterface $services;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->services = $this->kernel->getContainer()->get('test.service_container');
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAllPhase1aServicesResolveFromContainer(): void
    {
        $this->assertInstanceOf(DirectoryAuthenticator::class, $this->services->get('test.phase1a.directory_authenticator'));
        $this->assertInstanceOf(LoginRateLimiter::class, $this->services->get('test.phase1a.login_rate_limiter'));
        $this->assertInstanceOf(SessionCredentialRevoker::class, $this->services->get('test.phase1a.session_credential_revoker'));
        $this->assertInstanceOf(PasswordVerifier::class, $this->services->get('test.phase1a.password_verifier'));
        $this->assertInstanceOf(PasswordCredentialStore::class, $this->services->get('test.phase1a.password_credential_store'));
        $this->assertInstanceOf(AuthDomainRepository::class, $this->services->get('test.phase1a.auth_domain_repository'));
        $this->assertInstanceOf(RealmAccessChecker::class, $this->services->get('test.phase1a.realm_access_checker'));
        $this->assertInstanceOf(AuthenticateDirectoryUser::class, $this->services->get('test.phase1a.authenticate_directory_user'));
        $this->assertInstanceOf(AuthenticateLocalUser::class, $this->services->get('test.phase1a.authenticate_local_user'));
        $this->assertInstanceOf(ChangePassword::class, $this->services->get('test.phase1a.change_password'));
        $this->assertInstanceOf(RevokeAllUserSessions::class, $this->services->get('test.phase1a.revoke_all_user_sessions'));
        $this->assertInstanceOf(SymfonyIdentityInputValidator::class, $this->services->get('test.phase1a.input_validator'));
        $this->assertInstanceOf(RealmVoter::class, $this->services->get('test.phase1a.realm_voter'));
        $this->assertInstanceOf(ResourceAccessVoter::class, $this->services->get('test.phase1a.resource_access_voter'));
    }
}
