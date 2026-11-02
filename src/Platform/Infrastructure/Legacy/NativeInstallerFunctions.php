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
    /** Fixed native capabilities; request data cannot select arbitrary functions. */
    private const array FUNCTIONS = ['read_config_option', 'auth_session_check_eligibility', 'is_realm_allowed',
        'cacti_version_compare', 'install_file_paths', 'install_setup_get_templates',
        'get_installed_locales', 'install_setup_get_tables', 'db_fetch_cell_prepared',
        'exec_background', 'csrf_get_tokens', 'csrf_check', '__', 'clean_up_lines',
        'db_connect_real', 'db_close', 'db_execute', 'db_fetch_assoc', 'replicate_out', 'sql_save',
        'log_install_always', 'log_install_medium', 'csrf_get_secret', 'cacti_csrf_external_path_is_safe',
        'db_install_reset_error', 'db_install_add_cache', 'db_error', 'db_fetch_assoc_prepared', 'db_table_exists', 'db_column_exists',
        'db_add_column', 'db_index_matches', 'db_index_exists', 'db_remove_column', 'cacti_log', 'db_fetch_cell', 'db_execute_prepared', 'set_config_option', 'get_cacti_base_tables', 'db_fetch_row_prepared', 'config_value_exists', 'log_install_debug', 'log_install_high', 'get_installed_rrdtool_version'];

    /** @param ?\Closure(): (string|false) $hostname Late native host identity provider. */
    public function __construct(private readonly ?\Closure $hostname = null) {}

    /** Host resolution belongs to the native runtime, not installation business rules. */
    public function hostname(): string|false
    {
        return $this->hostname === null ? gethostname() : ($this->hostname)();
    }

    /**
     * Forward only a fixed capability and redact native credentials from exception arguments.
     * @phpstan-impure
     */
    public function call(string $name, #[\SensitiveParameter] mixed ...$arguments): mixed
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

    /** Observe the configured collector role without opening another database session. */
    public function hasRemoteDatabase(): bool
    {
        return (bool) ($GLOBALS['rdatabase_default'] ?? null);
    }

    /** @return array<string, mixed> */
    public function snmpFields(): array
    {
        return $this->map($GLOBALS['fields_snmp_item_with_retry'] ?? null);
    }

    /** Normalize native scalar text while refusing compound values and floating-point coercion. */
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
