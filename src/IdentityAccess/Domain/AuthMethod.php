<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * Installation authentication methods stored in settings (auth_method).
 * Matches $auth_methods in include/global_arrays.php.
 */
enum AuthMethod: int
{
    case Builtin = 1;
    case WebBasic = 2;
    case Ldap = 3;
    case MultipleDomains = 4;

    public function supportsDirectoryAuthentication(): bool
    {
        return $this === self::Ldap || $this === self::MultipleDomains;
    }

    public function supportsLocalPassword(): bool
    {
        return $this !== self::WebBasic;
    }

    public function label(): string
    {
        return match ($this) {
            self::Builtin => 'Builtin Authentication',
            self::WebBasic => 'Web Basic Authentication',
            self::Ldap => 'LDAP Authentication',
            self::MultipleDomains => 'Multiple LDAP/AD Domains',
        };
    }
}
