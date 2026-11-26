<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * LDAP transport encryption modes stored in user_domains_ldap.encryption and settings.ldap_encryption.
 * Matches $ldap_encryption in include/global_arrays.php.
 */
enum LdapEncryption: int
{
    case None = 0;
    case Ldaps = 1;
    case StartTls = 2;

    public function scheme(): string
    {
        return $this === self::Ldaps ? 'ldaps' : 'ldap';
    }

    public function usesTls(): bool
    {
        return $this !== self::None;
    }
}
