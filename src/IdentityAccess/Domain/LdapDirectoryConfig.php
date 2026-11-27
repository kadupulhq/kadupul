<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * Immutable directory configuration for LDAP / Active Directory authentication
 * (user_domains_ldap row or global LDAP settings).
 */
final readonly class LdapDirectoryConfig
{
    public const int GROUP_MEMBER_DN = 1;
    public const int GROUP_MEMBER_USERNAME = 2;
    public const int DOMAIN_REALM_OFFSET = 1000;

    public string $server;

    public function __construct(
        string $server,
        public int $port = 389,
        public int $sslPort = 636,
        public int $protocolVersion = 3,
        public LdapEncryption $encryption = LdapEncryption::None,
        public LdapTlsCertRequirement $tlsCertRequirement = LdapTlsCertRequirement::Demand,
        public bool $referrals = false,
        public LdapSearchMode $searchMode = LdapSearchMode::NoSearching,
        public string $dnTemplate = '',
        public string $searchBase = '',
        public string $searchFilter = '',
        public string $bindDn = '',
        #[\SensitiveParameter]
        private string $bindPassword = '',
        public bool $groupRequire = false,
        public string $groupDn = '',
        public string $groupAttribute = 'member',
        public int $groupMemberType = self::GROUP_MEMBER_DN,
        public string $fullNameAttribute = '',
        public string $emailAttribute = '',
        public int $networkTimeoutSeconds = 5,
    ) {
        $trimmedServer = trim($server);
        if ($trimmedServer === '') {
            throw new \InvalidArgumentException('LDAP server must not be empty.');
        }
        if ($port < 1 || $port > 65535 || $sslPort < 1 || $sslPort > 65535) {
            throw new \InvalidArgumentException('LDAP ports must be between 1 and 65535.');
        }
        if (!in_array($protocolVersion, [2, 3], true)) {
            throw new \InvalidArgumentException('LDAP protocol version must be 2 or 3.');
        }
        if (!in_array($groupMemberType, [self::GROUP_MEMBER_DN, self::GROUP_MEMBER_USERNAME], true)) {
            throw new \InvalidArgumentException('LDAP group member type must be 1 (DN) or 2 (username).');
        }
        if ($networkTimeoutSeconds < 1 || $networkTimeoutSeconds > 300) {
            throw new \InvalidArgumentException('LDAP network timeout must be between 1 and 300 seconds.');
        }
        if ($groupRequire && ($groupDn === '' || $groupAttribute === '')) {
            throw new \InvalidArgumentException('LDAP group verification requires group DN and group attribute.');
        }

        $this->server = $trimmedServer;
    }

    public function effectivePort(): int
    {
        return $this->encryption === LdapEncryption::Ldaps ? $this->sslPort : $this->port;
    }

    public function usesStartTls(): bool
    {
        return $this->encryption === LdapEncryption::StartTls;
    }

    public function usesSearchThenBind(): bool
    {
        return $this->searchMode->requiresSearch();
    }

    public function bindPassword(): string
    {
        return $this->bindPassword;
    }

    /**
     * Builds the connection URI for the primary LDAP server, respecting existing
     * scheme prefixes if the operator configured an explicit ldap:// or ldaps:// URI.
     */
    public function connectionUri(): string
    {
        $firstHost = preg_split('/\s+/', $this->server)[0] ?? $this->server;
        if (str_starts_with(strtolower($firstHost), 'ldap://') || str_starts_with(strtolower($firstHost), 'ldaps://')) {
            return $firstHost;
        }

        return sprintf('%s://%s:%d', $this->encryption->scheme(), $firstHost, $this->effectivePort());
    }

    public static function accountRealmForDomain(int $domainId): int
    {
        if ($domainId < 1) {
            throw new \InvalidArgumentException('Domain id must be positive.');
        }

        return self::DOMAIN_REALM_OFFSET + $domainId;
    }
}
