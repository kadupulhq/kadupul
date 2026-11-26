<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\AuthDomainRepository;
use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticationStatus;
use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticator;
use Kadupul\IdentityAccess\Application\Port\LoginRateLimiter;
use Kadupul\IdentityAccess\Domain\LdapDirectoryConfig;

/**
 * Authenticates a user against a configured LDAP / Active Directory domain or
 * global LDAP directory, enforcing additive rate limiting ahead of network I/O.
 */
final readonly class AuthenticateDirectoryUser
{
    public const int GLOBAL_LDAP_REALM = 3;

    public function __construct(
        private AuthDomainRepository $domains,
        private DirectoryAuthenticator $authenticator,
        private LoginRateLimiter $rateLimiter,
    ) {}

    public function __invoke(
        string $username,
        #[\SensitiveParameter]
        string $password,
        string $clientAddress,
        ?int $domainId = null,
    ): DirectoryLoginOutcome {
        $accountRealm = $domainId !== null && $domainId > 0
            ? LdapDirectoryConfig::accountRealmForDomain($domainId)
            : self::GLOBAL_LDAP_REALM;

        $limit = $this->rateLimiter->consume($clientAddress, $username, $accountRealm);
        if (!$limit->allowed) {
            return DirectoryLoginOutcome::rateLimited($accountRealm, $limit->retryAfterSeconds);
        }

        if ($username === '' || $password === '') {
            return DirectoryLoginOutcome::fromFailure(
                $accountRealm,
                DirectoryAuthenticationStatus::InvalidCredentials,
            );
        }

        if ($domainId !== null && $domainId > 0) {
            $domain = $this->domains->findEnabledById($domainId);
            if ($domain === null || !$domain->enabled) {
                return DirectoryLoginOutcome::domainUnavailable($accountRealm);
            }
            $config = $domain->ldapConfig;
        } else {
            $config = $this->domains->findGlobalLdapConfig();
            if ($config === null) {
                return DirectoryLoginOutcome::domainUnavailable($accountRealm);
            }
        }

        $result = $this->authenticator->authenticate($config, $username, $password);
        if (!$result->isAuthenticated()) {
            return DirectoryLoginOutcome::fromFailure($accountRealm, $result->status);
        }

        $this->rateLimiter->resetAccount($username, $accountRealm);

        return DirectoryLoginOutcome::authenticated($accountRealm, $result);
    }
}
