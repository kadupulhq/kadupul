<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract\Relationships;

use Kadupul\Navigation\Contract\Administration\ListScreen;

enum RelationshipScreen: string
{
    case DeviceQueries = 'device-template-queries';
    case DataDefinitions = 'data-template-definitions';
    case AggregateItems = 'aggregate-template-items';
    case SnmpEntries = 'snmp-option-entries';
    case GraphDeviceCriteria = 'graph-rule-device-criteria';
    case GraphQueryCriteria = 'graph-rule-query-criteria';
    case TreeDeviceCriteria = 'tree-rule-device-criteria';
    case TreeSteps = 'tree-rule-steps';
    case ReceiverSubscriptions = 'receiver-subscriptions';
    case ReceiverHistory = 'receiver-history';

    case UserGroups = 'user-group-memberships';
    case GroupMembers = 'group-members';
    case UserGraphs = 'user-graphs-permissions';
    case UserDevices = 'user-devices-permissions';
    case UserTemplates = 'user-templates-permissions';
    case UserTrees = 'user-trees-permissions';
    case GroupGraphs = 'group-graphs-permissions';
    case GroupDevices = 'group-devices-permissions';
    case GroupTemplates = 'group-templates-permissions';
    case GroupTrees = 'group-trees-permissions';

    case UserRealms = 'user-realm-grants';
    case UserDisplay = 'user-display-preferences';
    case UserGraphPreferences = 'user-graph-preferences';
    case UserTime = 'user-time-preferences';
    case UserLogin = 'user-login-options';
    case GroupRealms = 'group-realm-grants';
    case GroupDisplay = 'group-display-preferences';
    case GroupGraphPreferences = 'group-graph-preferences';
    case GroupTime = 'group-time-preferences';
    case GroupLogin = 'group-login-options';

    public function parentScreen(): ListScreen
    {
        return match ($this) {
            self::UserRealms => ListScreen::Users,
            self::UserDisplay => ListScreen::Users,
            self::UserGraphPreferences => ListScreen::Users,
            self::UserTime => ListScreen::Users,
            self::UserLogin => ListScreen::Users,
            self::GroupRealms => ListScreen::UserGroups,
            self::GroupDisplay => ListScreen::UserGroups,
            self::GroupGraphPreferences => ListScreen::UserGroups,
            self::GroupTime => ListScreen::UserGroups,
            self::GroupLogin => ListScreen::UserGroups,

            self::UserGroups => ListScreen::Users,
            self::GroupMembers => ListScreen::UserGroups,
            self::UserGraphs => ListScreen::Users,
            self::UserDevices => ListScreen::Users,
            self::UserTemplates => ListScreen::Users,
            self::UserTrees => ListScreen::Users,
            self::GroupGraphs => ListScreen::UserGroups,
            self::GroupDevices => ListScreen::UserGroups,
            self::GroupTemplates => ListScreen::UserGroups,
            self::GroupTrees => ListScreen::UserGroups,

            self::DeviceQueries => ListScreen::DeviceTemplates, self::DataDefinitions => ListScreen::DataTemplates,
            self::AggregateItems => ListScreen::AggregateTemplates, self::SnmpEntries => ListScreen::SnmpOptions,
            self::GraphDeviceCriteria, self::GraphQueryCriteria => ListScreen::GraphRules,
            self::TreeDeviceCriteria, self::TreeSteps => ListScreen::TreeRules,
            self::ReceiverSubscriptions, self::ReceiverHistory => ListScreen::NotificationReceivers,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::UserRealms => 'User Realm Grants',
            self::UserDisplay => 'User Display Preferences',
            self::UserGraphPreferences => 'User Graph Preferences',
            self::UserTime => 'User Time Preferences',
            self::UserLogin => 'User Login Options',
            self::GroupRealms => 'Group Realm Grants',
            self::GroupDisplay => 'Group Display Preferences',
            self::GroupGraphPreferences => 'Group Graph Preferences',
            self::GroupTime => 'Group Time Preferences',
            self::GroupLogin => 'Group Login Options',

            self::UserGroups => 'User Group Memberships',
            self::GroupMembers => 'Group Members',
            self::UserGraphs => 'User Graph Permission Exceptions',
            self::UserDevices => 'User Device Permission Exceptions',
            self::UserTemplates => 'User Template Permission Exceptions',
            self::UserTrees => 'User Tree Permission Exceptions',
            self::GroupGraphs => 'Group Graph Permission Exceptions',
            self::GroupDevices => 'Group Device Permission Exceptions',
            self::GroupTemplates => 'Group Template Permission Exceptions',
            self::GroupTrees => 'Group Tree Permission Exceptions',

            self::DeviceQueries => 'Device Template Data Queries', self::DataDefinitions => 'Data Template Definitions',
            self::AggregateItems => 'Aggregate Template Items', self::SnmpEntries => 'SNMP Option Entries',
            self::GraphDeviceCriteria => 'Graph Rule Device Criteria', self::GraphQueryCriteria => 'Graph Rule Query Criteria',
            self::TreeDeviceCriteria => 'Tree Rule Device Criteria', self::TreeSteps => 'Tree Rule Steps',
            self::ReceiverSubscriptions => 'Receiver Subscriptions', self::ReceiverHistory => 'Receiver History',
        };
    }

    public function route(): string
    {
        return 'relationships_' . str_replace('-', '_', $this->value);
    }

    public function path(): string
    {
        return 'app.php/relationships/' . $this->value;
    }

    public function defaultSort(): string
    {
        return $this === self::ReceiverHistory ? 'id' : (in_array($this, [self::AggregateItems, self::GraphDeviceCriteria, self::GraphQueryCriteria, self::TreeDeviceCriteria, self::TreeSteps], true) ? 'sequence' : 'name');
    }

    public function columns(): array
    {
        return match ($this) {
            self::UserRealms, self::GroupRealms => ['name' => 'Realm ID', 'grant' => 'Assignment'],
            self::UserDisplay, self::GroupDisplay, self::UserGraphPreferences, self::GroupGraphPreferences,
            self::UserTime, self::GroupTime, self::UserLogin, self::GroupLogin => ['name' => 'Setting', 'value' => 'Stored Value'],
            self::UserGroups => ['name' => 'Group', 'id' => 'ID', 'enabled' => 'Enabled', 'description' => 'Description'],
            self::GroupMembers => ['name' => 'Username', 'id' => 'ID', 'realm' => 'Authentication Realm', 'enabled' => 'Enabled'],
            self::UserGraphs, self::UserDevices, self::UserTemplates, self::UserTrees,
            self::GroupGraphs, self::GroupDevices, self::GroupTemplates, self::GroupTrees => ['name' => 'Resource', 'id' => 'Resource ID', 'resolved' => 'Resource Found', 'policy' => 'Default Policy'],
            self::DeviceQueries => ['name' => 'Data Query', 'id' => 'ID', 'input_method' => 'Input Method', 'graphs' => 'Graph Mappings'],
            self::DataDefinitions => ['name' => 'Data Source Name', 'id' => 'ID', 'source_type' => 'Data Source Type', 'heartbeat' => 'Heartbeat', 'minimum' => 'Minimum', 'maximum' => 'Maximum'],
            self::AggregateItems => ['name' => 'Graph Item', 'id' => 'Item ID', 'sequence' => 'Sequence', 'resolved' => 'Template Item Found', 'skip' => 'Skip', 'total' => 'Total', 'graph_type' => 'Graph Type', 'cdef' => 'CDEF', 'color_template' => 'Color Template'],
            self::SnmpEntries => ['name' => 'Sequence', 'id' => 'Entry ID', 'version' => 'SNMP Version', 'port' => 'Port', 'timeout' => 'Timeout (ms)', 'retries' => 'Retries', 'max_oids' => 'Maximum OIDs', 'bulk_walk' => 'Bulk Walk Size'],
            self::GraphDeviceCriteria, self::GraphQueryCriteria, self::TreeDeviceCriteria => ['name' => 'Field', 'id' => 'ID', 'sequence' => 'Sequence', 'operation' => 'Operation', 'operator' => 'Operator', 'pattern' => 'Pattern'],
            self::TreeSteps => ['name' => 'Field', 'id' => 'ID', 'sequence' => 'Sequence', 'sort_type' => 'Sort Order', 'propagate' => 'Propagate Changes', 'search_pattern' => 'Search Pattern', 'replace_pattern' => 'Replacement Pattern'],
            self::ReceiverSubscriptions => ['name' => 'Notification', 'mib' => 'MIB'],
            self::ReceiverHistory => ['name' => 'Notification', 'id' => 'ID', 'event_time' => 'Time (UTC)', 'severity' => 'Severity', 'mib' => 'MIB', 'varbinds' => 'Varbinds'],
        };
    }

    public function legacyQuery(int $parentId): array
    {
        if ($this->identityView()) {
            return ['action' => $this->parentScreen() === ListScreen::Users ? 'user_edit' : 'edit', 'id' => $parentId, 'tab' => match ($this) {
                self::UserRealms => 'realms',
                self::UserDisplay => 'settings',
                self::UserGraphPreferences => 'settings',
                self::UserTime => 'settings',
                self::UserLogin => 'general',
                self::GroupRealms => 'realms',
                self::GroupDisplay => 'settings',
                self::GroupGraphPreferences => 'settings',
                self::GroupTime => 'settings',
                self::GroupLogin => 'general',
                self::UserGroups => 'permsgr',
                self::GroupMembers => 'members',
                self::UserGraphs => 'permsg',
                self::UserDevices => 'permsd',
                self::UserTemplates => 'permste',
                self::UserTrees => 'permstr',
                self::GroupGraphs => 'permsg',
                self::GroupDevices => 'permsd',
                self::GroupTemplates => 'permste',
                self::GroupTrees => 'permstr',
                default => throw new \LogicException('Missing identity tab.'),
            }];
        }
        $query = ['action' => $this === self::DataDefinitions ? 'template_edit' : 'edit', 'id' => $parentId];
        if ($this === self::ReceiverSubscriptions || $this === self::ReceiverHistory) {
            $query['tab'] = $this === self::ReceiverSubscriptions ? 'notifications' : 'logs';
        }
        return $query;
    }

    public function identityView(): bool
    {
        return in_array($this->parentScreen(), [ListScreen::Users, ListScreen::UserGroups], true);
    }

    public function identitySettingsView(): bool
    {
        return in_array($this, [self::UserRealms, self::GroupRealms, self::UserDisplay, self::GroupDisplay,
            self::UserGraphPreferences, self::GroupGraphPreferences, self::UserTime, self::GroupTime,
            self::UserLogin, self::GroupLogin], true);
    }

    /** Explicit public setting keys only. Plugin and credential settings are excluded. @return array<string, string> */
    public function settingLabels(): array
    {
        return match ($this) {
            self::UserDisplay, self::GroupDisplay => [
                'selected_theme' => 'Theme', 'default_view_mode' => 'Default View Mode', 'user_language' => 'User Language',
                'enable_hscroll' => 'Enable Horizontal Scrolling', 'page_refresh' => 'Page Refresh',
            ],
            self::UserGraphPreferences, self::GroupGraphPreferences => [
                'show_graph_title' => 'Show Graph Title', 'hide_disabled' => 'Hide Disabled', 'show_aggregates' => 'Show Device Aggregates',
                'preview_graphs_per_page' => 'Preview Graphs Per Page', 'num_columns' => 'Preview Thumbnail Columns',
                'num_columns_tree' => 'Tree View Thumbnail Columns', 'default_height' => 'Thumbnail Height', 'default_width' => 'Thumbnail Width',
                'thumbnail_section_preview' => 'Preview Thumbnails', 'thumbnail_section_tree_2' => 'Tree Thumbnails',
                'treeview_graphs_per_page' => 'Tree Graphs Per Page', 'default_tree_id' => 'Default Tree', 'expand_hosts' => 'Expand Devices', 'tree_history' => 'Tree History',
            ],
            self::UserTime, self::GroupTime => [
                'client_timezone_support' => 'Timezone Support', 'default_date_format' => 'Date Display Format', 'default_datechar' => 'Date Separator',
                'default_rra_id' => 'Default Time Range', 'default_timespan' => 'Default Timespan', 'default_timeshift' => 'Default Timeshift',
                'allow_graph_dates_in_future' => 'Allow Graph Dates in Future', 'first_weekdayid' => 'First Day of the Week',
                'day_shift_start' => 'Start of Daily Shift', 'day_shift_end' => 'End of Daily Shift',
            ],
            self::UserLogin => ['enabled' => 'Enabled', 'locked' => 'Locked', 'must_change_password' => 'Must Change Password',
                'password_change' => 'Can Change Password', 'login_opts' => 'Login Option', 'graph_settings' => 'Can Change Graph Settings'],
            self::GroupLogin => ['enabled' => 'Enabled', 'login_opts' => 'Login Option', 'graph_settings' => 'Can Change Graph Settings'],
            default => [],
        };
    }

    public function editableEntry(): bool
    {
        return in_array($this, [self::DeviceQueries, self::DataDefinitions, self::SnmpEntries, self::GraphDeviceCriteria, self::GraphQueryCriteria, self::TreeDeviceCriteria, self::TreeSteps], true);
    }

    public function entryScript(): string
    {
        return $this === self::DeviceQueries ? 'data_queries.php' : $this->parentScreen()->legacyScript();
    }

    public function entryQuery(int $parentId, int $recordId): array
    {
        return match ($this) {
            self::DeviceQueries => ['action' => 'edit', 'id' => $recordId],
            self::DataDefinitions => ['action' => 'template_edit', 'id' => $parentId, 'view_rrd' => $recordId],
            self::SnmpEntries => ['action' => 'item_edit', 'id' => $parentId, 'item_id' => $recordId],
            self::GraphDeviceCriteria, self::GraphQueryCriteria => ['action' => 'item_edit', 'id' => $parentId, 'item_id' => $recordId, 'rule_type' => $this === self::GraphDeviceCriteria ? 1 : 2],
            self::TreeDeviceCriteria, self::TreeSteps => ['action' => 'item_edit', 'id' => $parentId, 'item_id' => $recordId, 'rule_type' => $this === self::TreeDeviceCriteria ? 3 : 4],
            default => $this->legacyQuery($parentId),
        };
    }
}
