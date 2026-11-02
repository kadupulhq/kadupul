<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Prime only absent settings while preserving pre-upgrade LDAP policy and existing values. */
final readonly class InstallerDefaultSettings
{
    /** Keep all reads and INSERT IGNORE writes on the native caller's selected session. */
    public function __construct(private NativeInstallerFunctions $native) {}

    /**
     * @param array<array-key, mixed> $definitions Native grouped setting metadata.
     */
    public function prime(array $definitions, string $schemaVersion): void
    {
        foreach ($definitions as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $name => $attributes) {
                if (!is_string($name) || !is_array($attributes)) {
                    continue;
                }
                if (isset($attributes['default'])) {
                    $default = $attributes['default'];
                    $current = $this->native->call('db_fetch_cell_prepared', 'SELECT value FROM settings WHERE name = ?', [$name]);
                    if (in_array($current, [null, false, '', 0, 0.0, []], true)) {
                        if ($name === 'ldap_tls_certificate') {
                            $default = $this->ldapDefault($default, $schemaVersion);
                        }
                        $this->insert($name, $default);
                    }
                } elseif (isset($attributes['items']) && is_array($attributes['items'])) {
                    foreach ($attributes['items'] as $item => $definition) {
                        if (!is_string($item) || !is_array($definition) || !isset($definition['default'])) {
                            continue;
                        }
                        $current = $this->native->call('db_fetch_cell_prepared', 'SELECT value FROM settings WHERE name = ?', [$item]);
                        if (in_array($current, [null, false, '', 0, 0.0, []], true)) {
                            $this->insert($item, $definition['default']);
                        }
                    }
                }
            }
        }
    }

    /** Existing encrypted LDAP installations keep their previously implicit certificate requirement. */
    public function ldapDefault(mixed $default, string $schemaVersion): mixed
    {
        if ($schemaVersion === '' || $schemaVersion === 'new_install') {
            return $default;
        }
        $global = $this->native->call('db_fetch_cell_prepared', 'SELECT value FROM settings WHERE name = ?', ['ldap_encryption']);
        $domains = $this->native->call('db_fetch_cell', 'SELECT COUNT(*) FROM user_domains_ldap WHERE encryption > 0');
        return (int) $this->native->text($global) > 0 || (int) $this->native->text($domains) > 0 ? LDAP_OPT_X_TLS_NEVER : $default;
    }

    /** A failed write must remain retryable instead of setting a successful priming marker. */
    private function insert(string $name, mixed $value): void
    {
        if ($this->native->call('db_execute_prepared', 'INSERT IGNORE INTO settings (name, value) VALUES (?, ?)', [$name, $value]) === false) {
            throw new \RuntimeException('The installation defaults could not be stored.');
        }
    }
}
