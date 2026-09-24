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

    #[\Override]
    public function values(): array
    {
        if ($this->configuration !== null) {
            if (($this->configuration['collector_id'] ?? 1) !== 1) {
                $this->requireSiteRoute();
            }
            return $this->configuration;
        }
        $settings = $this->load();
        $values = [
            'collector_id' => (int) ($settings['poller_id'] ?? 1),
            'root' => $this->projectDir,
            'forced_locale' => $settings['i18n_force_language'] ?? null,
            'host' => $settings['database_hostname'] ?? 'localhost', 'port' => $settings['database_port'] ?? 3306,
            'database' => $settings['database_default'] ?? '', 'username' => $settings['database_username'] ?? '', 'password' => $settings['database_password'] ?? '',
            'read_username' => $settings['database_read_username'] ?? '', 'read_password' => $settings['database_read_password'] ?? '',
            'ssl' => $settings['database_ssl'] ?? false, 'ssl_key' => $settings['database_ssl_key'] ?? '',
            'ssl_cert' => $settings['database_ssl_cert'] ?? '', 'ssl_ca' => $settings['database_ssl_ca'] ?? '',
            'session_name' => $settings['cacti_session_name'] ?? 'Cacti', 'database_sessions' => $settings['cacti_db_session'] ?? false,
            'cookie_domain' => $settings['cacti_cookie_domain'] ?? '', 'url_path' => $settings['url_path'] ?? '/',
        ];
        if ($values['collector_id'] < 1) {
            throw new \RuntimeException('Invalid collector identity.');
        }
        if ($values['collector_id'] !== 1) {
            $this->requireSiteRoute();
            if (($settings['rdatabase_type'] ?? 'mysql') !== 'mysql' || empty($settings['rdatabase_hostname']) || empty($settings['rdatabase_default']) || !isset($settings['rdatabase_username'], $settings['rdatabase_password']) || ($settings['conn_mode'] ?? '') === 'offline') {
                throw new \RuntimeException('Online primary configuration is required for collector Sites administration.');
            }
            $primary = array_replace($values, [
                'host' => $settings['rdatabase_hostname'], 'database' => $settings['rdatabase_default'],
                'username' => $settings['rdatabase_username'], 'password' => $settings['rdatabase_password'],
                'port' => $settings['rdatabase_port'] ?? 3306, 'ssl' => $settings['rdatabase_ssl'] ?? false,
                'ssl_key' => $settings['rdatabase_ssl_key'] ?? '', 'ssl_cert' => $settings['rdatabase_ssl_cert'] ?? '', 'ssl_ca' => $settings['rdatabase_ssl_ca'] ?? '',
            ]);
            CollectorSiteDatabase::verify($values, $primary);
            $values = $primary;
        }
        return $this->configuration = $values;
    }

    /**
     * Credential sets for command-line tools. Unlike values(), this never
     * swaps in the primary for the local set: a collector-side tool must be
     * able to address either database explicitly.
     *
     * @return array{collector_id: int, local: array<string, mixed>, main: ?array<string, mixed>}
     */
    public function databaseTargets(): array
    {
        $settings = $this->load();
        $local = [
            'host' => $settings['database_hostname'] ?? 'localhost', 'port' => $settings['database_port'] ?? 3306,
            'database' => $settings['database_default'] ?? '', 'username' => $settings['database_username'] ?? '', 'password' => $settings['database_password'] ?? '',
            'ssl' => $settings['database_ssl'] ?? false, 'ssl_key' => $settings['database_ssl_key'] ?? '', 'ssl_cert' => $settings['database_ssl_cert'] ?? '', 'ssl_ca' => $settings['database_ssl_ca'] ?? '',
        ];
        $collector = (int) ($settings['poller_id'] ?? 1);
        if ($collector < 1) {
            throw new \RuntimeException('Invalid collector identity.');
        }
        if ($collector === 1) {
            return ['collector_id' => 1, 'local' => $local, 'main' => $local];
        }
        $main = null;
        // An offline collector must not reach the main database, even when
        // its remote credentials are still present in config.php.
        if (($settings['conn_mode'] ?? '') !== 'offline' && ($settings['rdatabase_type'] ?? 'mysql') === 'mysql' && !empty($settings['rdatabase_hostname']) && !empty($settings['rdatabase_default']) && isset($settings['rdatabase_username'], $settings['rdatabase_password'])) {
            $main = [
                'host' => $settings['rdatabase_hostname'], 'port' => $settings['rdatabase_port'] ?? 3306, 'database' => $settings['rdatabase_default'],
                'username' => $settings['rdatabase_username'], 'password' => $settings['rdatabase_password'],
                'ssl' => $settings['rdatabase_ssl'] ?? false, 'ssl_key' => $settings['rdatabase_ssl_key'] ?? '', 'ssl_cert' => $settings['rdatabase_ssl_cert'] ?? '', 'ssl_ca' => $settings['rdatabase_ssl_ca'] ?? '',
            ];
        }

        return ['collector_id' => $collector, 'local' => $local, 'main' => $main];
    }

    /**
     * Variables that include/config.php defines. Trusted installation
     * configuration only; never load global.php or a page.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        // config.php may write into $config, as it does under global.php.
        $config = [];
        $path = $this->projectDir . '/include/config.php';
        if (!is_file($path)) {
            throw new \RuntimeException('Installation configuration is required.');
        }
        require $path;
        if (($database_type ?? 'mysql') !== 'mysql') {
            throw new \RuntimeException('The Symfony application requires the primary MySQL installation.');
        }
        unset($path);

        return get_defined_vars();
    }

    private function requireSiteRoute(): void
    {
        $route = $this->requests?->getCurrentRequest()?->attributes->get('_route');
        if (!in_array($route, ['inventory_sites', 'inventory_sites_json', 'inventory_sites_legacy', 'inventory_site_edit', 'inventory_site_create', 'inventory_site_action'], true)) {
            throw new \RuntimeException('The Symfony application requires the primary MySQL installation outside online collector Sites routes.');
        }
    }
}
