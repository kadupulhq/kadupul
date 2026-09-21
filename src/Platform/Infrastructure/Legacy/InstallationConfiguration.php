<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\LegacyConfiguration;

final class InstallationConfiguration implements LegacyConfiguration
{
    private ?array $configuration = null;

    public function __construct(private readonly string $projectDir) {}

    public function values(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }
        // Trusted installation configuration only; never load global.php or a page.
        $config = [];
        $path = $this->projectDir . '/include/config.php';
        if (!is_file($path)) {
            throw new \RuntimeException('Installation configuration is required.');
        }
        require $path;
        if (($database_type ?? 'mysql') !== 'mysql' || (int) ($poller_id ?? 1) !== 1) {
            throw new \RuntimeException('The Symfony application requires the primary MySQL installation.');
        }

        return $this->configuration = [
            'root' => $this->projectDir,
            'forced_locale' => $i18n_force_language ?? null,
            'host' => $database_hostname ?? 'localhost', 'port' => $database_port ?? 3306,
            'database' => $database_default ?? '', 'username' => $database_username ?? '', 'password' => $database_password ?? '',
            'ssl' => $database_ssl ?? false, 'ssl_key' => $database_ssl_key ?? '',
            'ssl_cert' => $database_ssl_cert ?? '', 'ssl_ca' => $database_ssl_ca ?? '',
            'session_name' => $cacti_session_name ?? 'Cacti', 'database_sessions' => $cacti_db_session ?? false,
            'cookie_domain' => $cacti_cookie_domain ?? '', 'url_path' => $url_path ?? '/',
        ];
    }
}
