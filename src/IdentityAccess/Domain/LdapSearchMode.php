<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * LDAP DN resolution modes stored in user_domains_ldap.mode and settings.ldap_mode.
 * Matches $ldap_modes in include/global_arrays.php.
 */
enum LdapSearchMode: int
{
    case NoSearching = 0;
    case AnonymousSearching = 1;
    case SpecificSearching = 2;

    public function requiresSearch(): bool
    {
        return $this !== self::NoSearching;
    }
}
