<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\IdentityAccess;

use Kadupul\IdentityAccess\Domain\AuthDomain;
use Kadupul\IdentityAccess\Domain\AuthMethod;
use Kadupul\IdentityAccess\Domain\AuthRealm;
use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\DirectoryType;
use Kadupul\IdentityAccess\Domain\Event\CredentialsRevoked;
use Kadupul\IdentityAccess\Domain\Event\RealmGrantChanged;
use Kadupul\IdentityAccess\Domain\Event\UserAuthenticated;
use Kadupul\IdentityAccess\Domain\GraphAuthMode;
use Kadupul\IdentityAccess\Domain\LdapDirectoryConfig;
use Kadupul\IdentityAccess\Domain\LdapEncryption;
use Kadupul\IdentityAccess\Domain\LdapSearchMode;
use Kadupul\IdentityAccess\Domain\LdapTlsCertRequirement;
use Kadupul\IdentityAccess\Domain\Policy\LockoutPolicy;
use Kadupul\IdentityAccess\Domain\RememberedToken;
use Kadupul\IdentityAccess\Domain\User;
use Kadupul\IdentityAccess\Domain\UserGroup;
use Kadupul\IdentityAccess\Domain\VisibilityDefault;
use PHPUnit\Framework\TestCase;

final class DomainModelsTest extends TestCase
{
    public function testAuthRealmSecurityAttributesAndClassification(): void
    {
        $this->assertSame('KADUPUL_REALM_15', AuthRealm::SettingsUtilities->securityAttribute());
        $this->assertSame(AuthRealm::SettingsUtilities, AuthRealm::fromSecurityAttribute('KADUPUL_REALM_15'));
        $this->assertSame(AuthRealm::SpikeHandling, AuthRealm::fromSecurityAttribute('KADUPUL_REALM_1043'));
        $this->assertNull(AuthRealm::fromSecurityAttribute('KADUPUL_REALM_99999'));
        $this->assertNull(AuthRealm::fromSecurityAttribute('ROLE_USER'));
        $this->assertTrue(AuthRealm::UsersGroups->requiresConsoleAccess());
        $this->assertFalse(AuthRealm::ViewGraphs->requiresConsoleAccess());
        $this->assertSame('Console Access', AuthRealm::ConsoleAccess->label());
    }

    public function testAuthMethodAndDirectoryEnums(): void
    {
        $this->assertFalse(AuthMethod::Builtin->supportsDirectoryAuthentication());
        $this->assertTrue(AuthMethod::Builtin->supportsLocalPassword());
        $this->assertFalse(AuthMethod::WebBasic->supportsLocalPassword());
        $this->assertTrue(AuthMethod::Ldap->supportsDirectoryAuthentication());
        $this->assertTrue(AuthMethod::MultipleDomains->supportsDirectoryAuthentication());

        $this->assertSame('Active Directory', DirectoryType::ActiveDirectory->label());
        $this->assertSame('ldaps', LdapEncryption::Ldaps->scheme());
        $this->assertTrue(LdapEncryption::StartTls->usesTls());
        $this->assertFalse(LdapEncryption::None->usesTls());
        $this->assertFalse(LdapSearchMode::NoSearching->requiresSearch());
        $this->assertTrue(LdapSearchMode::SpecificSearching->requiresSearch());
        $this->assertSame(LdapTlsCertRequirement::Demand, LdapTlsCertRequirement::fromStored(null));
        $this->assertSame(LdapTlsCertRequirement::Demand, LdapTlsCertRequirement::fromStored('invalid'));
        $this->assertSame(LdapTlsCertRequirement::Never, LdapTlsCertRequirement::fromStored('0'));
        $this->assertSame('demand', LdapTlsCertRequirement::Demand->openLdapOptionName());
        $this->assertSame(VisibilityDefault::Allow, VisibilityDefault::fromStored('1'));
        $this->assertSame(GraphAuthMode::Permissive, GraphAuthMode::fromStored(false));
        $this->assertSame(GraphAuthMode::Restrictive, GraphAuthMode::fromStored('2'));
    }

    public function testCredentialGenerationLifecycleAndMapping(): void
    {
        $gen = CredentialGeneration::generate();
        $this->assertSame(64, strlen($gen->toHex()));
        $this->assertTrue($gen->matches($gen->toHex()));
        $this->assertFalse($gen->matches('not-hex'));

        $mapping = $gen->mappingFor('stored-hash-1');
        $resolved = CredentialGeneration::resolveFromMapping('stored-hash-1', $mapping);
        $this->assertTrue($gen->matches($resolved));

        // Mismatched password fingerprint falls back to the password's own SHA-256.
        $fallback = CredentialGeneration::resolveFromMapping('different-hash', $mapping);
        $this->assertSame(CredentialGeneration::passwordFingerprint('different-hash'), $fallback->toHex());

        $this->expectException(\InvalidArgumentException::class);
        CredentialGeneration::fromHex('UPPERCASE_NOT_ALLOWED');
    }

    public function testRememberedTokenV1HmacBindingAndCookieParsing(): void
    {
        $gen1 = CredentialGeneration::generate();
        $gen2 = CredentialGeneration::generate();

        $token = RememberedToken::issue($gen1);
        $this->assertTrue($token->isVersioned());
        $this->assertTrue($token->isValidForGeneration($gen1));
        $this->assertFalse($token->isValidForGeneration($gen2));
        $this->assertSame(hash('sha512', $token->raw()), $token->storageHash());

        $cookie = $token->formatCookie(42, 0);
        $parsed = RememberedToken::parseCookie($cookie);
        $this->assertNotNull($parsed);
        $this->assertSame('42', $parsed['identity']);
        $this->assertSame(0, $parsed['realm']);
        $this->assertTrue($parsed['token']->isValidForGeneration($gen1));

        // Two-part legacy cookie defaults to realm 0 and unversioned tokens pass only when allowed.
        $legacyParsed = RememberedToken::parseCookie('admin,' . str_repeat('f', 64));
        $this->assertNotNull($legacyParsed);
        $this->assertSame(0, $legacyParsed['realm']);
        $this->assertFalse($legacyParsed['token']->isVersioned());
        $this->assertTrue($legacyParsed['token']->isValidForGeneration($gen1, false));
        $this->assertFalse($legacyParsed['token']->isValidForGeneration($gen1, true));

        // Malformed v1 token is always rejected.
        $malformedV1 = RememberedToken::fromRaw('v1.short.bad');
        $this->assertFalse($malformedV1->isValidForGeneration($gen1));
        $this->assertNull(RememberedToken::parseCookie('broken'));
    }

    public function testLdapDirectoryConfigInvariantsAndUri(): void
    {
        $config = new LdapDirectoryConfig(
            server: 'ldap.example.internal',
            port: 389,
            sslPort: 636,
            encryption: LdapEncryption::Ldaps,
            searchMode: LdapSearchMode::SpecificSearching,
            bindDn: 'cn=bind,dc=example,dc=internal',
            bindPassword: 'secret-bind-password',
        );

        $this->assertSame(636, $config->effectivePort());
        $this->assertSame('ldaps://ldap.example.internal:636', $config->connectionUri());
        $this->assertTrue($config->usesSearchThenBind());
        $this->assertFalse($config->usesStartTls());
        $this->assertSame('secret-bind-password', $config->bindPassword());
        $this->assertSame(1007, LdapDirectoryConfig::accountRealmForDomain(7));

        $this->expectException(\InvalidArgumentException::class);
        new LdapDirectoryConfig(server: 'ldap.example.internal', groupRequire: true, groupDn: '');
    }

    public function testUserAggregateLifecycleAndEvents(): void
    {
        $gen1 = CredentialGeneration::generate();
        $gen2 = CredentialGeneration::generate();
        $now = new \DateTimeImmutable('2026-11-26T12:00:00Z');

        $user = new User(
            id: 7,
            username: 'alice',
            accountRealm: 0,
            credentialGeneration: $gen1,
            mustChangePassword: true,
            passwordChangeAllowed: true,
            directRealms: [8],
        );

        $this->assertTrue($user->isEligibleForLogin());
        $this->assertFalse($user->isEligibleForLogin(true));
        $this->assertTrue($user->requiresForcedPasswordChange());
        $this->assertTrue($user->hasDirectRealm(AuthRealm::ConsoleAccess));

        $user->grantRealm(AuthRealm::SitesDevicesData, $now);
        $user->revokeRealm(AuthRealm::ConsoleAccess, $now);
        $user->recordAuthentication(AuthMethod::Builtin, '192.0.2.10', $now);
        $user->completePasswordChange($gen2, $now);

        $this->assertFalse($user->mustChangePassword);
        $this->assertTrue($user->credentialGeneration->matches($gen2));
        $this->assertSame([AuthRealm::SitesDevicesData->value], $user->directRealms);

        $events = $user->releaseEvents();
        $this->assertCount(4, $events);
        $this->assertInstanceOf(RealmGrantChanged::class, $events[0]);
        $this->assertTrue($events[0]->granted);
        $this->assertInstanceOf(RealmGrantChanged::class, $events[1]);
        $this->assertFalse($events[1]->granted);
        $this->assertInstanceOf(UserAuthenticated::class, $events[2]);
        $this->assertInstanceOf(CredentialsRevoked::class, $events[3]);
        $this->assertSame([], $user->releaseEvents());

        $policy = new LockoutPolicy(2, 10);
        $this->assertFalse($user->recordFailedLogin($policy, 1000));
        $this->assertTrue($user->recordFailedLogin($policy, 1010));
        $this->assertTrue($user->locked);
        $this->assertFalse($user->recoverLockout($policy, 1610)); // boundary: elapsed == window (600)
        $this->assertTrue($user->recoverLockout($policy, 1611));
        $this->assertFalse($user->locked);
        $this->assertSame(0, $user->failedAttempts);
    }

    public function testUserGroupAndAuthDomainAggregates(): void
    {
        $now = new \DateTimeImmutable('2026-11-26T12:00:00Z');
        $group = new UserGroup(id: 3, name: 'Operators', realms: [8]);
        $group->grantRealm(AuthRealm::Graphs, $now);
        $group->addMember(42);

        $this->assertTrue($group->hasEffectiveRealm(AuthRealm::Graphs));
        $this->assertTrue($group->containsMember(42));
        $group->disable();
        $this->assertFalse($group->hasEffectiveRealm(AuthRealm::Graphs));
        $this->assertCount(1, $group->releaseEvents());

        $domain = new AuthDomain(
            id: 5,
            name: 'CorpAD',
            type: DirectoryType::ActiveDirectory,
            ldapConfig: new LdapDirectoryConfig(server: 'ad.example.internal'),
        );
        $this->assertSame(1005, $domain->accountRealm());
        $domain->markAsDefault();
        $this->assertTrue($domain->isDefault);
        $domain->disable();
        $this->assertFalse($domain->enabled);
    }
}
