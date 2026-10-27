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

    case Users = 'users';
    case UserGroups = 'user-groups';
    case UserDomains = 'user-domains';
    case DiscoveredDevices = 'discovered-devices';
    case Collectors = 'collectors';
    case Trees = 'trees';
    case RrdCandidates = 'rrd-candidates';
    case SnmpObjects = 'snmp-objects';
    case SnmpNotifications = 'snmp-notifications';
    case LoginHistory = 'login-history';

    public function title(): string
    {
        return match ($this) {
            self::Users => 'Users',
            self::UserGroups => 'User Groups',
            self::UserDomains => 'User Domains',
            self::DiscoveredDevices => 'Discovered Devices',
            self::Collectors => 'Data Collectors',
            self::Trees => 'Trees',
            self::RrdCandidates => 'Recorded RRD Cleanup Candidates',
            self::SnmpObjects => 'SNMP Agent Objects',
            self::SnmpNotifications => 'SNMP Agent Notification Log',
            self::LoginHistory => 'Login History',

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
            self::Users => 1,
            self::UserGroups => 1,
            self::UserDomains => 1,
            self::DiscoveredDevices => 23,
            self::Collectors => 3,
            self::Trees => 4,
            self::RrdCandidates => 15,
            self::SnmpObjects => 15,
            self::SnmpNotifications => 15,
            self::LoginHistory => 15,

            self::DeviceTemplates => 12, self::GraphTemplates => 10, self::DataTemplates => 11,
            self::AggregateTemplates => 5, self::DataQueries => 13, self::NotificationReceivers => 15,
            self::SnmpOptions, self::GraphRules, self::TreeRules, self::Networks => 23,
        };
    }

    public function legacyScript(): string
    {
        return match ($this) {
            self::Users => 'user_admin.php',
            self::UserGroups => 'user_group_admin.php',
            self::UserDomains => 'user_domains.php',
            self::DiscoveredDevices => 'automation_devices.php',
            self::Collectors => 'pollers.php',
            self::Trees => 'tree.php',
            self::RrdCandidates => 'rrdcleaner.php',
            self::SnmpObjects => 'utilities.php',
            self::SnmpNotifications => 'utilities.php',
            self::LoginHistory => 'utilities.php',

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
            self::Users => '/administration/users',
            self::UserGroups => '/administration/user-groups',
            self::UserDomains => '/administration/domains',
            self::DiscoveredDevices => '/automation/discovered-devices',
            self::Collectors => '/data-collection/collectors',
            self::Trees => '/graphing/trees',
            self::RrdCandidates => '/utilities/rrd-candidates',
            self::SnmpObjects => '/utilities/snmp-objects',
            self::SnmpNotifications => '/utilities/snmp-notifications',
            self::LoginHistory => '/utilities/login-history',

            self::DeviceTemplates => '/templates/devices', self::GraphTemplates => '/templates/graphs',
            self::DataTemplates => '/templates/data-sources', self::AggregateTemplates => '/templates/aggregates',
            self::DataQueries => '/data-collection/queries', self::SnmpOptions => '/automation/snmp-options',
            self::GraphRules => '/automation/graph-rules', self::TreeRules => '/automation/tree-rules',
            self::Networks => '/automation/networks', self::NotificationReceivers => '/utilities/notification-receivers',
        };
    }

    public function editAction(): string
    {
        return match ($this) {
            self::Users => 'user_edit',
            self::GraphTemplates, self::DataTemplates => 'template_edit',
            default => 'edit',
        };
    }

    public function defaultSortColumn(): string
    {
        return match ($this) {
            self::LoginHistory => 'login_time', self::SnmpNotifications => 'event_time',
            self::Trees => 'sequence', self::SnmpObjects => 'id', default => 'name',
        };
    }

    public function defaultSortDirection(): string
    {
        return in_array($this, [self::LoginHistory, self::SnmpNotifications], true) ? 'DESC' : 'ASC';
    }

    public function editable(): bool
    {
        return !in_array($this, [self::DiscoveredDevices, self::RrdCandidates, self::SnmpObjects, self::SnmpNotifications, self::LoginHistory], true);
    }

    public function creatable(): bool
    {
        return $this->editable() && $this !== self::Collectors;
    }

    /** @return array<string, int|string> */
    public function editQuery(int|string|null $id = null): array
    {
        $query = ['action' => $this->editAction()];
        if ($id !== null) {
            $query[$this === self::UserDomains ? 'domain_id' : 'id'] = $id;
        }
        return $query;
    }

    /** @return array<string, string> Fixed navigation context for shared PHP scripts. */
    public function legacyQuery(): array
    {
        return match ($this) {
            self::SnmpObjects => ['action' => 'view_snmpagent_cache'],
            self::SnmpNotifications => ['action' => 'view_snmpagent_events'],
            self::LoginHistory => ['action' => 'view_user_log'],
            default => [],
        };
    }

    /** @return array<string, string> Column aliases are also the complete sort allowlist. */
    public function columns(): array
    {
        return ['name' => ($this === self::LoginHistory ? 'User' : 'Name')]
            + ($this === self::LoginHistory ? [] : ['id' => ($this === self::SnmpObjects ? 'OID' : 'ID')]) + match ($this) {
                self::Users => ['full_name' => 'Full Name', 'enabled' => 'Enabled', 'auth_realm' => 'Realm', 'domain_name' => 'Domain', 'graph_policy' => 'Graph Policy', 'device_policy' => 'Device Policy', 'template_policy' => 'Template Policy', 'last_attempt' => 'Last Login Attempt'],
                self::UserGroups => ['description' => 'Description', 'members' => 'Members', 'graph_policy' => 'Graph Policy', 'device_policy' => 'Device Policy', 'template_policy' => 'Template Policy', 'enabled' => 'Enabled'],
                self::UserDomains => ['domain_type' => 'Domain Type', 'default_domain' => 'Default', 'effective_user' => 'Effective User', 'cn_full_name' => 'CN FullName', 'cn_email' => 'CN eMail', 'enabled' => 'Enabled'],
                self::DiscoveredDevices => ['ip' => 'IP Address', 'sys_name' => 'SNMP Name', 'sys_location' => 'Location', 'sys_contact' => 'Contact', 'sys_description' => 'Description', 'os' => 'OS', 'uptime_seconds' => 'Uptime (seconds)', 'snmp_available' => 'SNMP', 'device_up' => 'Status', 'last_check' => 'Last Check'],
                self::Collectors => ['hostname' => 'Hostname', 'collector_status' => 'Status', 'processes' => 'Processes', 'threads' => 'Threads', 'total_time' => 'Polling Time', 'avg_time' => 'Average Time', 'max_time' => 'Maximum Time', 'hosts' => 'Devices', 'snmp' => 'SNMP Requests', 'script' => 'Script Requests', 'server' => 'Script Server Requests', 'last_update' => 'Last Finished', 'last_status' => 'Last Update', 'last_sync' => 'Last Sync'],
                self::Trees => ['enabled' => 'Published', 'locked' => 'Locked', 'owner' => 'Owner', 'sequence' => 'Order', 'last_modified' => 'Last Edited', 'modified_by' => 'Edited By', 'sites' => 'Sites', 'branches' => 'Branches', 'hosts' => 'Devices', 'graphs' => 'Graphs'],
                self::RrdCandidates => ['name_cache' => 'DS Name', 'local_data_id' => 'DS ID', 'data_template_id' => 'Template ID', 'data_template_name' => 'Template', 'last_mod' => 'Last Modified', 'size_kib' => 'Size [KB]'],
                self::SnmpObjects => ['mib' => 'MIB', 'kind' => 'Type', 'max_access' => 'Max-Access', 'object_value' => 'Value', 'description' => 'Description'],
                self::SnmpNotifications => ['event_time' => 'Time', 'hostname' => 'Receiver', 'severity' => 'Severity', 'mib' => 'MIB', 'varbinds' => 'Varbinds'],
                self::LoginHistory => ['full_name' => 'Full Name', 'auth_realm' => 'Authentication Realm', 'login_time' => 'Date', 'result' => 'Result', 'ip' => 'IP Address'],

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
