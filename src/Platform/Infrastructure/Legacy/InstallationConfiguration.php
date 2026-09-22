<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\HttpFoundation\RequestStack;

final class InstallationConfiguration implements LegacyConfiguration
{
    private ?array $configuration = null;

    public function __construct(private readonly string $projectDir, private readonly ?RequestStack $requests = null) {}

    public function values(): array
    {
        if ($this->configuration !== null) {
            if (($this->configuration['collector_id'] ?? 1) !== 1) {
                $this->requireSiteRoute();
            }
            return $this->configuration;
        }
        // Trusted installation configuration only; never load global.php or a page.
        $config = [];
        $path = $this->projectDir . '/include/config.php';
        if (!is_file($path)) {
            throw new \RuntimeException('Installation configuration is required.');
        }
        require $path;
        if (($database_type ?? 'mysql') !== 'mysql') {
            throw new \RuntimeException('The Symfony application requires the primary MySQL installation.');
        }

        $values = [
            'collector_id' => (int) ($poller_id ?? 1),
            'root' => $this->projectDir,
            'forced_locale' => $i18n_force_language ?? null,
            'host' => $database_hostname ?? 'localhost', 'port' => $database_port ?? 3306,
            'database' => $database_default ?? '', 'username' => $database_username ?? '', 'password' => $database_password ?? '',
            'ssl' => $database_ssl ?? false, 'ssl_key' => $database_ssl_key ?? '',
            'ssl_cert' => $database_ssl_cert ?? '', 'ssl_ca' => $database_ssl_ca ?? '',
            'session_name' => $cacti_session_name ?? 'Cacti', 'database_sessions' => $cacti_db_session ?? false,
            'cookie_domain' => $cacti_cookie_domain ?? '', 'url_path' => $url_path ?? '/',
        ];
        if ($values['collector_id'] < 1) {
            throw new \RuntimeException('Invalid collector identity.');
        }
        if ($values['collector_id'] !== 1) {
            $this->requireSiteRoute();
            if (($rdatabase_type ?? 'mysql') !== 'mysql' || empty($rdatabase_hostname) || empty($rdatabase_default) || !isset($rdatabase_username, $rdatabase_password) || ($conn_mode ?? '') === 'offline') {
                throw new \RuntimeException('Online primary configuration is required for collector Sites administration.');
            }
            $primary = array_replace($values, [
                'host' => $rdatabase_hostname, 'database' => $rdatabase_default,
                'username' => $rdatabase_username, 'password' => $rdatabase_password,
                'port' => $rdatabase_port ?? 3306, 'ssl' => $rdatabase_ssl ?? false,
                'ssl_key' => $rdatabase_ssl_key ?? '', 'ssl_cert' => $rdatabase_ssl_cert ?? '', 'ssl_ca' => $rdatabase_ssl_ca ?? '',
            ]);
            CollectorSiteDatabase::verify($values, $primary);
            $values = $primary;
        }
        return $this->configuration = $values;
    }

    private function requireSiteRoute(): void
    {
        $route = $this->requests?->getCurrentRequest()?->attributes->get('_route');
        if (!in_array($route, ['inventory_sites', 'inventory_sites_json', 'inventory_sites_legacy', 'inventory_site_edit', 'inventory_site_create', 'inventory_site_action'], true)) {
            throw new \RuntimeException('The Symfony application requires the primary MySQL installation outside online collector Sites routes.');
        }
    }
}
