<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Ldap;

use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticationResult;
use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticator;
use Kadupul\IdentityAccess\Domain\LdapDirectoryConfig;
use Kadupul\IdentityAccess\Domain\LdapEncryption;
use Kadupul\IdentityAccess\Domain\LdapSearchMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Ldap\Adapter\QueryInterface;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\Exception\LdapException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Symfony LDAP adapter implementing ADR 0010 Decision 7: direct-bind and
 * search-then-bind modes, TLS certificate enforcement, library filter/DN
 * escaping, group membership verification, and non-disclosing error outcomes.
 */
final readonly class SymfonyLdapDirectoryAuthenticator implements DirectoryAuthenticator
{
    /** @var ?\Closure(LdapDirectoryConfig): LdapInterface */
    private ?\Closure $clientFactory;

    /**
     * @param ?\Closure(LdapDirectoryConfig): LdapInterface $clientFactory
     */
    public function __construct(
        private ?LoggerInterface $logger = null,
        ?\Closure $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory;
    }

    #[\Override]
    public function authenticate(
        LdapDirectoryConfig $config,
        string $username,
        #[\SensitiveParameter]
        string $password,
    ): DirectoryAuthenticationResult {
        if ($username === '' || $password === '') {
            return DirectoryAuthenticationResult::invalidCredentials();
        }

        try {
            $client = $this->createClient($config);
            $resolvedEntry = null;

            if ($config->usesSearchThenBind()) {
                $resolvedEntry = $this->searchUserEntry($client, $config, $username);
                if ($resolvedEntry === null) {
                    return DirectoryAuthenticationResult::invalidCredentials();
                }
                $userDn = $resolvedEntry->getDn();
            } else {
                if ($config->dnTemplate === '') {
                    return DirectoryAuthenticationResult::configurationError();
                }
                $escapedDn = $client->escape($username, '', LdapInterface::ESCAPE_DN);
                $userDn = str_replace('<username>', $escapedDn, $config->dnTemplate);
            }

            $client->bind($userDn, $password);

            if ($config->groupRequire && !$this->verifyGroupMembership($client, $config, $userDn, $username)) {
                return DirectoryAuthenticationResult::groupMembershipDenied();
            }

            $fullName = $this->extractAttribute($resolvedEntry, $config->fullNameAttribute);
            $email = $this->extractAttribute($resolvedEntry, $config->emailAttribute);

            return DirectoryAuthenticationResult::authenticated($userDn, $fullName, $email);
        } catch (InvalidCredentialsException) {
            return DirectoryAuthenticationResult::invalidCredentials();
        } catch (ConnectionException|LdapException $error) {
            $this->logger?->warning('Directory authentication transport failure.', [
                'exception' => $error::class,
                'server' => $config->server,
            ]);

            return DirectoryAuthenticationResult::unavailable();
        }
    }

    private function createClient(LdapDirectoryConfig $config): LdapInterface
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($config);
        }

        $encryption = match ($config->encryption) {
            LdapEncryption::None => 'none',
            LdapEncryption::Ldaps => 'ssl',
            LdapEncryption::StartTls => 'tls',
        };

        return Ldap::create('ext_ldap', [
            'connection_string' => $config->connectionUri(),
            'encryption' => $encryption,
            'version' => $config->protocolVersion,
            'referrals' => $config->referrals,
            'options' => [
                'x_tls_require_cert' => $config->tlsCertRequirement->value,
                'network_timeout' => $config->networkTimeoutSeconds,
            ],
        ]);
    }

    private function searchUserEntry(LdapInterface $client, LdapDirectoryConfig $config, string $username): ?Entry
    {
        if ($config->searchBase === '' || $config->searchFilter === '') {
            return null;
        }

        if ($config->searchMode === LdapSearchMode::SpecificSearching) {
            if ($config->bindDn === '' || $config->bindPassword() === '') {
                return null;
            }
            $client->bind($config->bindDn, $config->bindPassword());
        } else {
            $client->bind();
        }

        $escapedUsername = $client->escape($username, '', LdapInterface::ESCAPE_FILTER);
        $filter = str_replace('<username>', $escapedUsername, $config->searchFilter);

        $attributes = ['dn'];
        if ($config->fullNameAttribute !== '') {
            $attributes[] = $config->fullNameAttribute;
        }
        if ($config->emailAttribute !== '') {
            $attributes[] = $config->emailAttribute;
        }

        $entries = $client->query($config->searchBase, $filter, [
            'filter' => $attributes,
            'scope' => QueryInterface::SCOPE_SUB,
        ])->execute()->toArray();

        // Ambiguous or missing directory matches must fail closed.
        if (count($entries) !== 1) {
            return null;
        }

        return $entries[0];
    }

    private function verifyGroupMembership(
        LdapInterface $client,
        LdapDirectoryConfig $config,
        string $userDn,
        string $username,
    ): bool {
        $memberValue = $config->groupMemberType === LdapDirectoryConfig::GROUP_MEMBER_DN
            ? $userDn
            : $username;

        $escapedMember = $client->escape($memberValue, '', LdapInterface::ESCAPE_FILTER);
        $filter = sprintf('(%s=%s)', $config->groupAttribute, $escapedMember);

        $matches = $client->query($config->groupDn, $filter, [
            'filter' => ['dn'],
            'scope' => QueryInterface::SCOPE_BASE,
        ])->execute()->toArray();

        return $matches !== [];
    }

    private function extractAttribute(?Entry $entry, string $attributeName): ?string
    {
        if ($entry === null || $attributeName === '') {
            return null;
        }
        $values = $entry->getAttribute($attributeName, false);
        if ($values === null || !isset($values[0]) || !is_string($values[0])) {
            return null;
        }

        return $values[0];
    }
}
