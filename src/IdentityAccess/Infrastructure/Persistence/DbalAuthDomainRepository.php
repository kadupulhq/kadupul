<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Application\Port\AuthDomainRepository;
use Kadupul\IdentityAccess\Domain\AuthDomain;
use Kadupul\IdentityAccess\Domain\DirectoryType;
use Kadupul\IdentityAccess\Domain\LdapDirectoryConfig;
use Kadupul\IdentityAccess\Domain\LdapEncryption;
use Kadupul\IdentityAccess\Domain\LdapSearchMode;
use Kadupul\IdentityAccess\Domain\LdapTlsCertRequirement;

/**
 * Doctrine DBAL read repository loading configured LDAP / Active Directory
 * login domains (user_domains + user_domains_ldap) and global LDAP settings.
 */
final readonly class DbalAuthDomainRepository implements AuthDomainRepository
{
    public function __construct(
        private Connection $database,
    ) {}

    #[\Override]
    public function findEnabledById(int $domainId): ?AuthDomain
    {
        if ($domainId < 1) {
            return null;
        }

        $row = $this->database->fetchAssociative(
            "SELECT d.domain_id, d.domain_name, d.type, d.enabled, d.defdomain, d.user_id,
                    l.server, l.port, l.port_ssl, l.proto_version, l.encryption, l.referrals,
                    l.mode, l.dn, l.group_require, l.group_dn, l.group_attrib, l.group_member_type,
                    l.search_base, l.search_filter, l.specific_dn, l.specific_password,
                    l.cn_full_name, l.cn_email
             FROM user_domains AS d
             INNER JOIN user_domains_ldap AS l ON l.domain_id = d.domain_id
             WHERE d.domain_id = ? AND d.enabled = 'on'",
            [$domainId],
        );

        return $row === false ? null : $this->hydrateDomain($row);
    }

    #[\Override]
    public function findDefaultEnabled(): ?AuthDomain
    {
        $row = $this->database->fetchAssociative(
            "SELECT d.domain_id, d.domain_name, d.type, d.enabled, d.defdomain, d.user_id,
                    l.server, l.port, l.port_ssl, l.proto_version, l.encryption, l.referrals,
                    l.mode, l.dn, l.group_require, l.group_dn, l.group_attrib, l.group_member_type,
                    l.search_base, l.search_filter, l.specific_dn, l.specific_password,
                    l.cn_full_name, l.cn_email
             FROM user_domains AS d
             INNER JOIN user_domains_ldap AS l ON l.domain_id = d.domain_id
             WHERE d.defdomain = 1 AND d.enabled = 'on'
             LIMIT 1",
        );

        return $row === false ? null : $this->hydrateDomain($row);
    }

    #[\Override]
    public function findGlobalLdapConfig(): ?LdapDirectoryConfig
    {
        $rows = $this->database->fetchAllKeyValue(
            "SELECT name, value FROM settings WHERE name IN (
                'ldap_server', 'ldap_port', 'ldap_port_ssl', 'ldap_version', 'ldap_encryption',
                'ldap_tls_certificate', 'ldap_referrals', 'ldap_mode', 'ldap_dn', 'ldap_group_require',
                'ldap_group_dn', 'ldap_group_attrib', 'ldap_group_member_type', 'ldap_search_base',
                'ldap_search_filter', 'ldap_specific_dn', 'ldap_specific_password',
                'ldap_cn_full_name', 'ldap_cn_email', 'ldap_network_timeout'
            )",
        );

        $server = self::asString($rows['ldap_server'] ?? '');
        if (trim($server) === '') {
            return null;
        }

        $groupRequire = ($rows['ldap_group_require'] ?? '') === 'on';

        $groupAttribute = self::asString($rows['ldap_group_attrib'] ?? '');

        return new LdapDirectoryConfig(
            server: $server,
            port: self::positiveOrDefault($rows['ldap_port'] ?? null, 389),
            sslPort: self::positiveOrDefault($rows['ldap_port_ssl'] ?? null, 636),
            protocolVersion: self::asInt($rows['ldap_version'] ?? 3) === 2 ? 2 : 3,
            encryption: LdapEncryption::tryFrom(self::asInt($rows['ldap_encryption'] ?? 0)) ?? LdapEncryption::None,
            tlsCertRequirement: LdapTlsCertRequirement::fromStored($rows['ldap_tls_certificate'] ?? null),
            referrals: self::asInt($rows['ldap_referrals'] ?? 0) === 1,
            searchMode: LdapSearchMode::tryFrom(self::asInt($rows['ldap_mode'] ?? 0)) ?? LdapSearchMode::NoSearching,
            dnTemplate: self::asString($rows['ldap_dn'] ?? ''),
            searchBase: self::asString($rows['ldap_search_base'] ?? ''),
            searchFilter: self::asString($rows['ldap_search_filter'] ?? ''),
            bindDn: self::asString($rows['ldap_specific_dn'] ?? ''),
            bindPassword: self::asString($rows['ldap_specific_password'] ?? ''),
            groupRequire: $groupRequire,
            groupDn: self::asString($rows['ldap_group_dn'] ?? ''),
            groupAttribute: $groupAttribute !== '' ? $groupAttribute : 'member',
            groupMemberType: self::asInt($rows['ldap_group_member_type'] ?? 1) === LdapDirectoryConfig::GROUP_MEMBER_USERNAME
                ? LdapDirectoryConfig::GROUP_MEMBER_USERNAME
                : LdapDirectoryConfig::GROUP_MEMBER_DN,
            fullNameAttribute: self::asString($rows['ldap_cn_full_name'] ?? ''),
            emailAttribute: self::asString($rows['ldap_cn_email'] ?? ''),
            networkTimeoutSeconds: self::positiveOrDefault($rows['ldap_network_timeout'] ?? null, 5),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateDomain(array $row): AuthDomain
    {
        $tlsRequirement = LdapTlsCertRequirement::fromStored(
            $this->database->fetchOne("SELECT value FROM settings WHERE name = 'ldap_tls_certificate'"),
        );

        $groupAttribute = self::asString($row['group_attrib'] ?? '');

        $config = new LdapDirectoryConfig(
            server: self::asString($row['server'] ?? ''),
            port: self::positiveOrDefault($row['port'] ?? null, 389),
            sslPort: self::positiveOrDefault($row['port_ssl'] ?? null, 636),
            protocolVersion: self::asInt($row['proto_version'] ?? 3) === 2 ? 2 : 3,
            encryption: LdapEncryption::tryFrom(self::asInt($row['encryption'] ?? 0)) ?? LdapEncryption::None,
            tlsCertRequirement: $tlsRequirement,
            referrals: self::asInt($row['referrals'] ?? 0) === 1,
            searchMode: LdapSearchMode::tryFrom(self::asInt($row['mode'] ?? 0)) ?? LdapSearchMode::NoSearching,
            dnTemplate: self::asString($row['dn'] ?? ''),
            searchBase: self::asString($row['search_base'] ?? ''),
            searchFilter: self::asString($row['search_filter'] ?? ''),
            bindDn: self::asString($row['specific_dn'] ?? ''),
            bindPassword: self::asString($row['specific_password'] ?? ''),
            groupRequire: ($row['group_require'] ?? '') === 'on',
            groupDn: self::asString($row['group_dn'] ?? ''),
            groupAttribute: $groupAttribute !== '' ? $groupAttribute : 'member',
            groupMemberType: self::asInt($row['group_member_type'] ?? 1) === LdapDirectoryConfig::GROUP_MEMBER_USERNAME
                ? LdapDirectoryConfig::GROUP_MEMBER_USERNAME
                : LdapDirectoryConfig::GROUP_MEMBER_DN,
            fullNameAttribute: self::asString($row['cn_full_name'] ?? ''),
            emailAttribute: self::asString($row['cn_email'] ?? ''),
        );

        return new AuthDomain(
            id: self::asInt($row['domain_id'] ?? 0),
            name: self::asString($row['domain_name'] ?? ''),
            type: DirectoryType::tryFrom(self::asInt($row['type'] ?? 1)) ?? DirectoryType::Ldap,
            ldapConfig: $config,
            enabled: ($row['enabled'] ?? '') === 'on',
            isDefault: self::asInt($row['defdomain'] ?? 0) === 1,
            templateUserId: max(0, self::asInt($row['user_id'] ?? 0)),
        );
    }

    private static function asInt(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }

    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function positiveOrDefault(mixed $value, int $default): int
    {
        $parsed = self::asInt($value);

        return $parsed > 0 ? $parsed : $default;
    }
}
