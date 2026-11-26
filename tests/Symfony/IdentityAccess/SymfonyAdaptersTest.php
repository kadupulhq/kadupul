<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\IdentityAccess;

use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticationStatus;
use Kadupul\IdentityAccess\Application\Port\LoginRateLimitDecision;
use Kadupul\IdentityAccess\Application\Port\RealmAccessChecker;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ResourceAccess;
use Kadupul\IdentityAccess\Domain\AuthRealm;
use Kadupul\IdentityAccess\Domain\LdapDirectoryConfig;
use Kadupul\IdentityAccess\Domain\LdapSearchMode;
use Kadupul\IdentityAccess\Infrastructure\Ldap\SymfonyLdapDirectoryAuthenticator;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyPasswordHasher;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\RealmVoter;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\ResourceAccessVoter;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\SessionIdentity;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\SymfonyLoginRateLimiter;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\SymfonyPasswordVerifier;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Validation\LoginCredentialsInput;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Validation\PasswordChangeInput;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Validation\SymfonyIdentityInputValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Ldap\Adapter\CollectionInterface;
use Symfony\Component\Ldap\Adapter\QueryInterface;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\LdapInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Validator\Validation;

final class SymfonyAdaptersTest extends TestCase
{
    public function testLdapSearchThenBindEscapesFilterAndVerifiesGroup(): void
    {
        $config = new LdapDirectoryConfig(
            server: 'ldap.example.internal',
            searchMode: LdapSearchMode::SpecificSearching,
            searchBase: 'ou=users,dc=example,dc=internal',
            searchFilter: '(uid=<username>)',
            bindDn: 'cn=svc,dc=example,dc=internal',
            bindPassword: 'svc-password',
            groupRequire: true,
            groupDn: 'cn=admins,ou=groups,dc=example,dc=internal',
            groupAttribute: 'member',
            fullNameAttribute: 'cn',
            emailAttribute: 'mail',
        );

        $userEntry = new Entry('uid=alice,ou=users,dc=example,dc=internal', [
            'cn' => ['Alice Admin'],
            'mail' => ['alice@example.internal'],
        ]);
        $groupEntry = new Entry('cn=admins,ou=groups,dc=example,dc=internal');

        $userCollection = $this->createStub(CollectionInterface::class);
        $userCollection->method('toArray')->willReturn([$userEntry]);
        $userQuery = $this->createStub(QueryInterface::class);
        $userQuery->method('execute')->willReturn($userCollection);

        $groupCollection = $this->createStub(CollectionInterface::class);
        $groupCollection->method('toArray')->willReturn([$groupEntry]);
        $groupQuery = $this->createStub(QueryInterface::class);
        $groupQuery->method('execute')->willReturn($groupCollection);

        $client = $this->createMock(LdapInterface::class);
        $client->method('escape')->willReturnCallback(
            static fn(string $subject): string => str_replace(['*', '(', ')'], ['\\2a', '\\28', '\\29'], $subject),
        );
        $client->expects($this->exactly(2))->method('bind')
            ->willReturnCallback(function (?string $dn, ?string $password): void {
                static $step = 0;
                $step++;
                if ($step === 1) {
                    $this->assertSame('cn=svc,dc=example,dc=internal', $dn);
                    $this->assertSame('svc-password', $password);
                } else {
                    $this->assertSame('uid=alice,ou=users,dc=example,dc=internal', $dn);
                    $this->assertSame('user-pass', $password);
                }
            });
        $client->expects($this->exactly(2))->method('query')
            ->willReturnCallback(function (string $dn, string $filter) use ($userQuery, $groupQuery): QueryInterface {
                if ($dn === 'ou=users,dc=example,dc=internal') {
                    $this->assertSame('(uid=alice\\28\\2a\\29)', $filter);

                    return $userQuery;
                }
                $this->assertSame('cn=admins,ou=groups,dc=example,dc=internal', $dn);
                $this->assertSame('(member=uid=alice,ou=users,dc=example,dc=internal)', $filter);

                return $groupQuery;
            });

        $adapter = new SymfonyLdapDirectoryAuthenticator(null, static fn(): LdapInterface => $client);
        $result = $adapter->authenticate($config, 'alice(*)', 'user-pass');

        $this->assertTrue($result->isAuthenticated());
        $this->assertSame('uid=alice,ou=users,dc=example,dc=internal', $result->resolvedDn);
        $this->assertSame('Alice Admin', $result->fullName);
        $this->assertSame('alice@example.internal', $result->emailAddress);
    }

    public function testLdapMapsInvalidCredentialsAndTransportFailureWithoutDisclosure(): void
    {
        $config = new LdapDirectoryConfig(server: 'ldap.example.internal', dnTemplate: 'uid=<username>,dc=example');

        $invalidClient = $this->createStub(LdapInterface::class);
        $invalidClient->method('escape')->willReturnArgument(0);
        $invalidClient->method('bind')->willThrowException(new InvalidCredentialsException('Internal bind failure detail'));

        $adapter = new SymfonyLdapDirectoryAuthenticator(null, static fn(): LdapInterface => $invalidClient);
        $this->assertSame(
            DirectoryAuthenticationStatus::InvalidCredentials,
            $adapter->authenticate($config, 'alice', 'wrong')->status,
        );

        $brokenClient = $this->createStub(LdapInterface::class);
        $brokenClient->method('escape')->willReturnArgument(0);
        $brokenClient->method('bind')->willThrowException(new ConnectionException('Can\'t contact LDAP server'));

        $brokenAdapter = new SymfonyLdapDirectoryAuthenticator(null, static fn(): LdapInterface => $brokenClient);
        $this->assertSame(
            DirectoryAuthenticationStatus::Unavailable,
            $brokenAdapter->authenticate($config, 'alice', 'pass')->status,
        );
    }

    public function testLoginRateLimiterCanonicalizesIpv6ChecksAddressFirstAndFailsClosed(): void
    {
        $this->assertSame('192.0.2.10', SymfonyLoginRateLimiter::canonicalizeAddress('::ffff:192.0.2.10'));
        $this->assertSame('2001:db8::1', SymfonyLoginRateLimiter::canonicalizeAddress('2001:0db8:0000:0000:0000:0000:0000:0001'));

        $addressFactory = new RateLimiterFactory([
            'id' => 'test_addr',
            'policy' => 'sliding_window',
            'limit' => 2,
            'interval' => '1 minute',
        ], new InMemoryStorage());
        $accountFactory = new RateLimiterFactory([
            'id' => 'test_acct',
            'policy' => 'sliding_window',
            'limit' => 5,
            'interval' => '1 minute',
        ], new InMemoryStorage());

        $limiter = new SymfonyLoginRateLimiter($addressFactory, $accountFactory);

        // Two requests from IPv4 and its IPv4-mapped IPv6 form consume the same address bucket.
        $this->assertTrue($limiter->consume('192.0.2.10', 'alice', 0)->allowed);
        $this->assertTrue($limiter->consume('::ffff:192.0.2.10', 'bob', 0)->allowed);

        // Third request hits address limit without consuming carol's account bucket.
        $denied = $limiter->consume('192.0.2.10', 'carol', 0);
        $this->assertFalse($denied->allowed);
        $this->assertSame(LoginRateLimitDecision::BUCKET_ADDRESS, $denied->exhaustedBucket);
        $this->assertGreaterThanOrEqual(1, $denied->retryAfterSeconds);

        // Storage outage must fail closed.
        $failingFactory = $this->createStub(RateLimiterFactoryInterface::class);
        $failingFactory->method('create')->willThrowException(new \RuntimeException('cache down'));
        $failClosed = new SymfonyLoginRateLimiter($failingFactory, $accountFactory);
        $storageFailure = $failClosed->consume('192.0.2.20', 'alice', 0);
        $this->assertFalse($storageFailure->allowed);
        $this->assertSame(LoginRateLimitDecision::BUCKET_STORAGE_UNAVAILABLE, $storageFailure->exhaustedBucket);
    }

    public function testSymfonyPasswordVerifierAndInputValidator(): void
    {
        $verifier = new SymfonyPasswordVerifier(new LegacyPasswordHasher());
        $hash = $verifier->hash('SecretPass1!');
        $this->assertTrue($verifier->verify($hash, 'SecretPass1!'));
        $this->assertFalse($verifier->verify($hash, 'wrong'));
        $this->assertTrue($verifier->verify(md5('legacy'), 'legacy'));
        $this->assertTrue($verifier->needsRehash(md5('legacy')));
        $verifier->padUnknownUserTiming();

        $validator = new SymfonyIdentityInputValidator(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
        $loginErrors = $validator->validate(new LoginCredentialsInput('', ''));
        $this->assertArrayHasKey('username', $loginErrors);
        $this->assertArrayHasKey('password', $loginErrors);
        $this->assertSame([], $validator->validate(new LoginCredentialsInput('alice', 'SecretPass1!')));

        $passwordErrors = $validator->validate(new PasswordChangeInput('old', 'NewPass1!', 'DifferentPass1!'));
        $this->assertArrayHasKey('confirmPassword', $passwordErrors);
        $this->assertSame([], $validator->validate(new PasswordChangeInput('old', 'NewPass1!', 'NewPass1!')));
    }

    public function testRealmAndResourceAccessVoters(): void
    {
        $checker = $this->createMock(RealmAccessChecker::class);
        $checker->expects($this->exactly(2))
            ->method('hasRealm')
            ->willReturnMap([
                [7, AuthRealm::UsersGroups->value, false, false],
                [7, AuthRealm::Graphs->value, true, true],
            ]);

        $token = new UsernamePasswordToken(new SessionIdentity(new Actor(7, 'alice')), 'native_session', []);
        $realmVoter = new RealmVoter($checker);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $realmVoter->vote($token, null, [AuthRealm::UsersGroups->securityAttribute()]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $realmVoter->vote($token, null, [AuthRealm::Graphs->securityAttribute()]));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $realmVoter->vote($token, null, ['UNRELATED_ATTRIBUTE']));

        $resourceAccess = $this->createStub(ResourceAccess::class);
        $resourceAccess->method('canManageTree')->with(7, 7)->willReturn(true);
        $resourceAccess->method('canManageReport')->with(7, 99)->willReturn(false);

        $resourceVoter = new ResourceAccessVoter($resourceAccess);
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $resourceVoter->vote($token, 7, [ResourceAccessVoter::MANAGE_TREE]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $resourceVoter->vote($token, 99, [ResourceAccessVoter::MANAGE_REPORT]));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $resourceVoter->vote($token, 'not-an-int', [ResourceAccessVoter::MANAGE_TREE]));
    }
}
