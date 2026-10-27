<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract\Administration;

/** Public-data list views; editors and mutations retain their existing owners. */
enum ListScreen: string
{
    case DeviceTemplates = 'device-templates';
    case GraphTemplates = 'graph-templates';
    case DataTemplates = 'data-templates';
    case AggregateTemplates = 'aggregate-templates';
    case DataQueries = 'data-queries';
    case SnmpOptions = 'snmp-options';
    case GraphRules = 'graph-rules';
    case TreeRules = 'tree-rules';
    case Networks = 'networks';
    case NotificationReceivers = 'notification-receivers';

    public function title(): string
    {
        return match ($this) {
            self::DeviceTemplates => 'Device Templates', self::GraphTemplates => 'Graph Templates',
            self::DataTemplates => 'Data Templates', self::AggregateTemplates => 'Aggregate Templates',
            self::DataQueries => 'Data Queries', self::SnmpOptions => 'SNMP Option Sets',
            self::GraphRules => 'Graph Rules', self::TreeRules => 'Tree Rules',
            self::Networks => 'Networks', self::NotificationReceivers => 'SNMP Notification Receivers',
        };
    }

    public function realm(): int
    {
        return match ($this) {
            self::DeviceTemplates => 12, self::GraphTemplates => 10, self::DataTemplates => 11,
            self::AggregateTemplates => 5, self::DataQueries => 13, self::NotificationReceivers => 15,
            default => 23,
        };
    }

    public function legacyScript(): string
    {
        return match ($this) {
            self::DeviceTemplates => 'host_templates.php', self::GraphTemplates => 'graph_templates.php',
            self::DataTemplates => 'data_templates.php', self::AggregateTemplates => 'aggregate_templates.php',
            self::DataQueries => 'data_queries.php', self::SnmpOptions => 'automation_snmp.php',
            self::GraphRules => 'automation_graph_rules.php', self::TreeRules => 'automation_tree_rules.php',
            self::Networks => 'automation_networks.php', self::NotificationReceivers => 'managers.php',
        };
    }

    public function path(): string
    {
        return 'app.php' . match ($this) {
            self::DeviceTemplates => '/templates/devices', self::GraphTemplates => '/templates/graphs',
            self::DataTemplates => '/templates/data-sources', self::AggregateTemplates => '/templates/aggregates',
            self::DataQueries => '/data-collection/queries', self::SnmpOptions => '/automation/snmp-options',
            self::GraphRules => '/automation/graph-rules', self::TreeRules => '/automation/tree-rules',
            self::Networks => '/automation/networks', self::NotificationReceivers => '/utilities/notification-receivers',
        };
    }

    public function editAction(): string
    {
        return in_array($this, [self::GraphTemplates, self::DataTemplates], true) ? 'template_edit' : 'edit';
    }

    /** @return array<string, string> Column aliases are also the complete sort allowlist. */
    public function columns(): array
    {
        return ['name' => 'Name', 'id' => 'ID'] + match ($this) {
            self::DeviceTemplates => ['device_class' => 'Device Class', 'hosts' => 'Devices Using'],
            self::GraphTemplates => ['graphs' => 'Graphs Using', 'size' => 'Size', 'image_format' => 'Image Format', 'vertical_label' => 'Vertical Label'],
            self::DataTemplates => ['data_sources' => 'Data Sources Using', 'data_input_method' => 'Data Input Method', 'profile_name' => 'Data Source Profile', 'active' => 'Status'],
            self::AggregateTemplates => ['graphs' => 'Graphs Using', 'graph_template_name' => 'Graph Template'],
            self::DataQueries => ['graphs' => 'Graphs Using', 'templates' => 'Templates Using', 'data_input_method' => 'Data Input Method'],
            self::SnmpOptions => ['networks' => 'Networks Using', 'totals' => 'SNMP Entries', 'v1entries' => 'V1 Entries', 'v2entries' => 'V2 Entries', 'v3entries' => 'V3 Entries'],
            self::GraphRules => ['snmp_query_name' => 'Data Query', 'graph_type_name' => 'Graph Type', 'enabled' => 'Status'],
            self::TreeRules => ['tree_name' => 'Hook into Tree', 'subtree_name' => 'At Subtree', 'leaf_type' => 'This Type', 'host_grouping_type' => 'Using Grouping', 'enabled' => 'Status'],
            self::Networks => ['data_collector' => 'Data Collector', 'sched_type' => 'Schedule', 'total_ips' => 'Total IPs', 'network_status' => 'Status', 'pending' => 'Pending', 'running' => 'Running', 'done' => 'Done', 'up_hosts' => 'Up Hosts', 'snmp_hosts' => 'SNMP Hosts', 'threads' => 'Threads', 'last_runtime' => 'Last Runtime', 'next_start' => 'Next Start', 'last_started' => 'Last Started'],
            self::NotificationReceivers => ['disabled' => 'Status', 'hostname' => 'Hostname', 'count_notify' => 'Notifications', 'count_log' => 'Logs'],
        };
    }
}
