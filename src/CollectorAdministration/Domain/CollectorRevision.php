<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Domain;

final class CollectorRevision
{
    /** @param array<string, mixed> $values */
    public static function fromValues(array $values, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $key): string
    {
        $public = [];
        foreach (['name', 'hostname', 'timezone', 'notes', 'processes', 'threads', 'sync_interval', 'dbdefault', 'dbhost', 'dbuser', 'dbport', 'dbretries', 'dbssl', 'dbsslkey', 'dbsslcert', 'dbsslca'] as $field) {
            $public[$field] = $values[$field] ?? null;
        }
        foreach (['processes', 'threads', 'sync_interval', 'dbport', 'dbretries'] as $field) {
            $public[$field] = (int) ($public[$field] ?? 0);
        }
        $public['dbssl'] = ($public['dbssl'] ?? '') === true || ($public['dbssl'] ?? '') === 'on';
        return hash_hmac('sha256', json_encode([$public, $password], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key);
    }
}
