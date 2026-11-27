<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * OpenLDAP TLS peer certificate verification levels (LDAP_OPT_X_TLS_*).
 * Unset or unknown stored values fail closed to Demand, matching cacti_ldap_tls_require_cert().
 */
enum LdapTlsCertRequirement: int
{
    case Never = 0;
    case Hard = 1;
    case Demand = 2;
    case Allow = 3;
    case Try = 4;

    public static function fromStored(mixed $value): self
    {
        if (is_int($value)) {
            return self::tryFrom($value) ?? self::Demand;
        }
        if (is_string($value) && ctype_digit($value)) {
            return self::tryFrom((int) $value) ?? self::Demand;
        }

        return self::Demand;
    }

    public function openLdapOptionName(): string
    {
        return match ($this) {
            self::Never => 'never',
            self::Hard => 'hard',
            self::Demand => 'demand',
            self::Allow => 'allow',
            self::Try => 'try',
        };
    }
}
