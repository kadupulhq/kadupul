<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * Login authentication domain aggregate root combining user_domains metadata
 * with its immutable LdapDirectoryConfig.
 */
final class AuthDomain
{
    public private(set) string $name;

    public function __construct(
        public readonly int $id,
        string $name,
        public readonly DirectoryType $type,
        public private(set) LdapDirectoryConfig $ldapConfig,
        public private(set) bool $enabled = true,
        public private(set) bool $isDefault = false,
        public private(set) int $templateUserId = 0,
    ) {
        if ($id < 1) {
            throw new \InvalidArgumentException('Auth domain id must be positive.');
        }
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Auth domain name must not be empty.');
        }
        if ($templateUserId < 0) {
            throw new \InvalidArgumentException('Template user id must be non-negative.');
        }

        $this->name = $trimmed;
    }

    /** Account realm stored on user_auth.realm for accounts provisioned in this domain. */
    public function accountRealm(): int
    {
        return LdapDirectoryConfig::accountRealmForDomain($this->id);
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function markAsDefault(): void
    {
        $this->isDefault = true;
    }

    public function clearDefault(): void
    {
        $this->isDefault = false;
    }

    public function updateLdapConfig(LdapDirectoryConfig $config): void
    {
        $this->ldapConfig = $config;
    }
}
