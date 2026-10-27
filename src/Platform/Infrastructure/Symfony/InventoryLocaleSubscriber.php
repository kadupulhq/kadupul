<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class InventoryLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private LocalePreference $preference, private DatabaseConnection $database, private LegacyConfiguration $configuration, private string $projectDir = __DIR__ . '/../../../..') {}

    public static function getSubscribedEvents(): array
    {
        // After routing (32), before Symfony's LocaleListener (16).
        return [KernelEvents::REQUEST => ['onRequest', 20]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !in_array($request->attributes->get('_route'), ['management_graph_input_items', 'management_query_data_sources', 'management_query_graph_suggestions', 'management_query_data_suggestions', 'management_query_device_templates', 'management_graph_device_templates', 'management_snmp_mibs', 'management_mib_objects', 'management_mib_conventions', 'management_mib_notification_attributes', 'relationships_device_template_queries', 'relationships_data_template_definitions', 'relationships_aggregate_template_items', 'relationships_snmp_option_entries', 'relationships_graph_rule_device_criteria', 'relationships_graph_rule_query_criteria', 'relationships_tree_rule_device_criteria', 'relationships_tree_rule_steps', 'relationships_receiver_subscriptions', 'relationships_receiver_history', 'management_graphs', 'management_data_sources', 'management_aggregate_graphs', 'management_reports', 'management_my_reports', 'management_plugins', 'management_graph_template_items', 'management_graph_template_inputs', 'management_device_template_graphs', 'management_data_query_graphs', 'platform_diagnostics_menu', 'platform_diagnostics_summary', 'platform_diagnostics_database', 'platform_diagnostics_database_settings', 'platform_diagnostics_database_status', 'platform_diagnostics_php', 'platform_diagnostics_changelog', 'platform_diagnostics_boost', 'platform_diagnostics_poller_cache', 'platform_diagnostics_data_query_cache', 'admin_users', 'admin_user_groups', 'admin_user_domains', 'admin_discovered_devices', 'admin_collectors', 'admin_trees', 'admin_rrd_candidates', 'admin_snmp_objects', 'admin_snmp_notifications', 'admin_login_history', 'admin_device_templates', 'admin_graph_templates', 'admin_data_templates', 'admin_aggregate_templates', 'admin_data_queries', 'admin_snmp_options', 'admin_graph_rules', 'admin_tree_rules', 'admin_networks', 'admin_notification_receivers', 'platform_help', 'platform_console', 'platform_permission_denied', 'data_input_bulk', 'data_inputs', 'data_input_edit', 'data_input_create', 'data_input_field', 'data_input_action', 'data_input_legacy', 'inventory_sites', 'inventory_site_edit', 'inventory_site_create', 'inventory_device_create', 'inventory_site_action', 'inventory_sites_legacy', 'inventory_devices', 'inventory_device_details', 'inventory_device_edit', 'inventory_device_template', 'inventory_device_collector', 'inventory_device_state', 'inventory_device_remove', 'navigation_links', 'navigation_links_legacy', 'navigation_link_action', 'navigation_link_create', 'navigation_link_edit', 'platform_about', 'platform_about_legacy', 'platform_rrd_checks', 'platform_rrd_check_purge', 'platform_rrd_check_legacy', 'palette_color_list', 'palette_color_edit', 'palette_color_create', 'palette_color_delete', 'palette_color_import', 'palette_color_export', 'palette_color_legacy', 'gprint_preset_list', 'gprint_preset_edit', 'gprint_preset_create', 'gprint_preset_delete', 'gprint_preset_legacy', 'color_template_list', 'color_template_create', 'color_template_edit', 'color_template_item_create', 'color_template_item_edit', 'color_template_item_delete', 'color_template_item_order', 'color_template_action', 'color_template_legacy', 'color_template_items_legacy', 'data_source_profile_list', 'data_source_profile_create', 'data_source_profile_edit', 'data_source_profile_rra_create', 'data_source_profile_rra_edit', 'data_source_profile_rra_delete', 'data_source_profile_action', 'data_source_profile_legacy', 'graph_vdefs', 'graph_vdef_create', 'graph_vdef_edit', 'graph_vdef_item_edit', 'graph_vdef_item_delete', 'graph_vdef_item_reorder', 'graph_vdef_action', 'graph_vdef_legacy', 'graph_cdefs', 'graph_cdef_create', 'graph_cdef_edit', 'graph_cdef_item_edit', 'graph_cdef_item_delete', 'graph_cdef_item_move', 'graph_cdef_action', 'graph_cdef_legacy', 'automation_templates', 'automation_template_create', 'automation_template_edit', 'automation_template_delete', 'automation_template_move', 'automation_templates_legacy', 'inventory_device_bulk_assignment', 'inventory_device_automation', 'inventory_devices_legacy', 'inventory_device_associations', 'inventory_device_maintenance', 'inventory_device_placement'], true)) {
            return;
        }
        // Anonymous requests do not need installation/session access for locale.
        $request->attributes->set('_locale', 'en');
        if ($request->cookies->count() === 0) {
            return;
        }
        $settings = $this->database->get()->query("SELECT name, value FROM settings WHERE name IN ('i18n_language_support', 'i18n_auto_detection', 'i18n_default_language')")->fetchAll(\PDO::FETCH_KEY_PAIR);
        if (($settings['i18n_language_support'] ?? '') === '0') {
            return;
        }
        $config = $this->configuration->values();
        $normalize = in_array($request->attributes->get('_route'), ['platform_console', 'platform_permission_denied'], true)
            ? fn(mixed $value): ?string => ConsoleTranslations::normalize($value, $this->projectDir)
            : self::normalize(...);
        $locale = $normalize($config['forced_locale'] ?? null) ?? $normalize($this->preference->preferredLocale());
        if ($locale === null && in_array($settings['i18n_auto_detection'] ?? '', ['', '1'], true)) {
            foreach ($request->getLanguages() as $language) {
                if (($locale = $normalize($language)) !== null) {
                    break;
                }
            }
        }
        $request->attributes->set('_locale', $locale ?? $normalize($settings['i18n_default_language'] ?? null) ?? 'en');
    }

    private static function normalize(mixed $locale): ?string
    {
        return is_string($locale) && preg_match('/\A(en|fr)(?:[-_][a-z]{2})?\z/iD', $locale, $match) ? strtolower($match[1]) : null;
    }
}
