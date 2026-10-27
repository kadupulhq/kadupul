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
    private ?array $settings = null;

    public function __construct(private readonly string $projectDir, private readonly ?RequestStack $requests = null) {}

    #[\Override]
    public function values(): array
    {
        if ($this->configuration !== null) {
            if (isset($this->configuration['connection_status']) && !in_array(
                $this->requests?->getCurrentRequest()?->attributes->get('_route'),
                ['platform_console', 'platform_help'],
                true,
            )) {
                throw new \RuntimeException('Console collector routing cannot be reused by another workflow.');
            }
            if (($this->configuration['collector_id'] ?? 1) !== 1 && !isset($this->configuration['connection_status'])) {
                $this->requireOnlinePrimaryRoute();
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
            'proxy_headers' => $settings['proxy_headers'] ?? [],
        ];
        if ($values['collector_id'] < 1) {
            throw new \RuntimeException('Invalid collector identity.');
        }
        if (in_array($this->requests?->getCurrentRequest()?->attributes->get('_route'), ['platform_console', 'platform_help'], true)) {
            return $this->configuration = ConsoleInstallation::select($values, $this->databaseTargets());
        }
        if ($values['collector_id'] !== 1) {
            $this->requireOnlinePrimaryRoute();
            if (($settings['rdatabase_type'] ?? 'mysql') !== 'mysql' || empty($settings['rdatabase_hostname']) || empty($settings['rdatabase_default']) || !isset($settings['rdatabase_username'], $settings['rdatabase_password']) || ($settings['conn_mode'] ?? '') === 'offline') {
                throw new \RuntimeException('Online primary configuration is required for collector administration.');
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

    /** Public URL metadata only; never opens a database for a login redirect. */
    public function urlPath(): string
    {
        $path = $this->load()['url_path'] ?? '/';
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x20?#]/', $path)) {
            throw new \RuntimeException('Invalid installation URL path.');
        }

        return rtrim($path, '/');
    }

    /**
     * config.php is read once per instance, so values() and databaseTargets()
     * derive from the same load. A refused load is not kept and is refused
     * again on the next call.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        return $this->settings ??= $this->read();
    }

    /**
     * Variables that include/config.php defines. Trusted installation
     * configuration only; never load global.php or a page.
     *
     * @return array<string, mixed>
     */
    private function read(): array
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

    private function requireOnlinePrimaryRoute(): void
    {
        $route = $this->requests?->getCurrentRequest()?->attributes->get('_route');
        if (!in_array($route, [
            'inventory_sites', 'inventory_sites_json', 'inventory_sites_legacy', 'inventory_site_edit', 'inventory_site_create', 'inventory_site_action',
            'navigation_links', 'navigation_links_legacy', 'navigation_link_create', 'navigation_link_edit', 'navigation_link_action',
            'platform_help', 'platform_about', 'platform_about_legacy',
            'platform_diagnostics_menu', 'platform_diagnostics_summary', 'platform_diagnostics_database', 'platform_diagnostics_database_settings', 'platform_diagnostics_database_status', 'platform_diagnostics_php', 'platform_diagnostics_changelog', 'platform_diagnostics_boost', 'platform_diagnostics_poller_cache', 'platform_diagnostics_data_query_cache',
            'management_graph_input_items', 'management_query_data_sources', 'management_query_graph_suggestions', 'management_query_data_suggestions', 'management_query_device_templates', 'management_graph_device_templates', 'management_snmp_mibs', 'management_mib_objects', 'management_mib_conventions', 'management_mib_notification_attributes', 'management_graphs', 'management_data_sources', 'management_aggregate_graphs', 'management_reports', 'management_my_reports', 'management_plugins', 'management_graph_template_items', 'management_graph_template_inputs', 'management_device_template_graphs', 'management_data_query_graphs',
            'relationships_device_template_queries', 'relationships_data_template_definitions', 'relationships_aggregate_template_items', 'relationships_snmp_option_entries', 'relationships_graph_rule_device_criteria', 'relationships_graph_rule_query_criteria', 'relationships_tree_rule_device_criteria', 'relationships_tree_rule_steps', 'relationships_receiver_subscriptions', 'relationships_receiver_history',
            'admin_users', 'admin_user_groups', 'admin_user_domains', 'admin_discovered_devices', 'admin_collectors', 'admin_trees', 'admin_rrd_candidates', 'admin_snmp_objects', 'admin_snmp_notifications', 'admin_login_history', 'admin_device_templates', 'admin_graph_templates', 'admin_data_templates', 'admin_aggregate_templates',
            'admin_data_queries', 'admin_snmp_options', 'admin_graph_rules', 'admin_tree_rules', 'admin_networks', 'admin_notification_receivers',
            'graph_vdefs', 'graph_vdef_create', 'graph_vdef_edit', 'graph_vdef_item_edit', 'graph_vdef_item_delete', 'graph_vdef_item_reorder', 'graph_vdef_action', 'graph_vdef_legacy',
        ], true)) {
            throw new \RuntimeException('The Symfony application requires the primary MySQL installation outside supported online collector administration routes.');
        }
    }

    /**
     * The values global.php derives from this installation's include/
     * directory and config.php. The include path is the directory global.php
     * runs from, so it is resolved the way __FILE__ is.
     */
    public function platform(): \Kadupul\Platform\Contract\PlatformConfiguration
    {
        $settings = $this->load();
        $includeDirectory = realpath($this->projectDir . '/include');
        if ($includeDirectory === false) {
            throw new \RuntimeException('Installation include directory is required.');
        }

        return \Kadupul\Platform\Contract\PlatformConfiguration::fromBootstrap(
            $includeDirectory,
            $settings['poller_id'] ?? null,
            \array_key_exists('url_path', $settings) ? $settings['url_path'] : '/cacti/',
            PHP_OS,
        );
    }
}
