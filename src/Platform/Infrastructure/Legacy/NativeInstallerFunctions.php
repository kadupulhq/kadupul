<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Late calls keep native bootstrap and its side effects out of container boot. */
final class NativeInstallerFunctions
{
    private const FUNCTIONS = ['read_config_option', 'auth_session_check_eligibility', 'is_realm_allowed',
        'cacti_version_compare', 'install_file_paths', 'install_setup_get_templates',
        'get_installed_locales', 'install_setup_get_tables', 'db_fetch_cell_prepared',
        'exec_background', 'csrf_get_tokens', 'csrf_check'];

    public function call(string $name, mixed ...$arguments): mixed
    {
        if (!in_array($name, self::FUNCTIONS, true) || !function_exists($name)) {
            throw new \RuntimeException('The authenticated native installer runtime is required.');
        }
        return $name(...$arguments);
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        return $this->map($GLOBALS['config'] ?? null);
    }

    public function hasRemoteDatabase(): bool
    {
        return (bool) ($GLOBALS['rdatabase_default'] ?? null);
    }

    /** @return array<string, mixed> */
    public function snmpFields(): array
    {
        return $this->map($GLOBALS['fields_snmp_item_with_retry'] ?? null);
    }

    public function text(mixed $value, string $default = ''): string
    {
        if ($value === null || $value === false) {
            return $default;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new \RuntimeException('Invalid native installer text value.');
        }
        return (string) $value;
    }

    /** @return array<string, mixed> */
    public function map(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('Invalid native installer map.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('Invalid native installer map key.');
            }
            $result[$key] = $item;
        }
        return $result;
    }
}
