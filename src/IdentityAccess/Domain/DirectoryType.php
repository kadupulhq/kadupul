<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * Login domain directory types stored in user_domains.type.
 * Matches $domain_types in include/global_arrays.php.
 */
enum DirectoryType: int
{
    case Ldap = 1;
    case ActiveDirectory = 2;

    public function label(): string
    {
        return match ($this) {
            self::Ldap => 'LDAP',
            self::ActiveDirectory => 'Active Directory',
        };
    }
}
