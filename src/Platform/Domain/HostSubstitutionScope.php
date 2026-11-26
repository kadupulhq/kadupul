<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain;

/**
 * Separates host variable tokens permitted in user-facing titles and legends
 * from credential tokens restricted to poller and script-query execution.
 */
enum HostSubstitutionScope: string
{
    case Display = 'display';
    case Execution = 'execution';

    /** @var list<string> */
    private const array CREDENTIAL_TOKENS = [
        'host_snmp_community',
        'host_snmp_username',
        'host_snmp_password',
        'host_snmp_priv_passphrase',
    ];

    public function allowsToken(string $token): bool
    {
        return match ($this) {
            self::Execution => true,
            self::Display => !in_array($token, self::CREDENTIAL_TOKENS, true),
        };
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    public function filterFields(array $fields): array
    {
        if ($this === self::Execution) {
            return $fields;
        }

        return array_filter(
            $fields,
            fn(string $token): bool => $this->allowsToken($token),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
