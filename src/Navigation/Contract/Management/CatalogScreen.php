<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract\Management;

enum CatalogScreen: string
{
    case LocalGraphItems = 'graph-items';
    case GraphSources = 'graph-data-sources';
    case AggregateMembers = 'aggregate-members';
    case SourceDefinitions = 'data-source-definitions';
    case SourceGraphs = 'data-source-graphs';
    case ReportItems = 'report-items';
    case MyReportItems = 'my-report-items';
    case TreeItems = 'tree-items';
    case CollectorDevices = 'collector-devices';
    case NetworkDevices = 'network-devices';

    case Graphs = 'graphs';
    case DataSources = 'data-sources';
    case Aggregates = 'aggregate-graphs';
    case AdminReports = 'reports';
    case MyReports = 'my-reports';
    case Plugins = 'plugins';
    case GraphItems = 'graph-template-items';
    case GraphInputs = 'graph-template-inputs';
    case DeviceGraphs = 'device-template-graphs';
    case QueryGraphs = 'data-query-graphs';

    case InputItems = 'graph-input-items';
    case QuerySources = 'query-data-sources';
    case QueryGraphSuggestions = 'query-graph-suggestions';
    case QueryDataSuggestions = 'query-data-suggestions';
    case QueryDeviceTemplates = 'query-device-templates';
    case GraphDeviceTemplates = 'graph-device-templates';
    case Mibs = 'snmp-mibs';
    case MibObjects = 'mib-objects';
    case MibConventions = 'mib-conventions';
    case MibAttributes = 'mib-notification-attributes';

    public function title(): string
    {
        return match ($this) {
            self::LocalGraphItems => 'Graph Items', self::GraphSources => 'Graph Data Sources',
            self::AggregateMembers => 'Aggregate Member Graphs', self::SourceDefinitions => 'Data Source Definitions',
            self::SourceGraphs => 'Data Source Graphs', self::ReportItems => 'Report Contents',
            self::MyReportItems => 'My Report Contents', self::TreeItems => 'Tree Items',
            self::CollectorDevices => 'Collector Devices', self::NetworkDevices => 'Network Discovery Results',
            self::InputItems => 'Graph Input Associations',
            self::QuerySources => 'Query Data Source Mappings',
            self::QueryGraphSuggestions => 'Query Graph Suggested Values',
            self::QueryDataSuggestions => 'Query Data Suggested Values',
            self::QueryDeviceTemplates => 'Query Device Templates',
            self::GraphDeviceTemplates => 'Graph Device Templates',
            self::Mibs => 'Registered SNMP MIBs',
            self::MibObjects => 'MIB Objects',
            self::MibConventions => 'MIB Textual Conventions',
            self::MibAttributes => 'MIB Notification Attributes',

            self::Graphs => 'Graphs', self::DataSources => 'Data Sources', self::Aggregates => 'Aggregate Graphs',
            self::AdminReports => 'Administrator Reports', self::MyReports => 'My Reports', self::Plugins => 'Recorded Plugins',
            self::GraphItems => 'Graph Template Items', self::GraphInputs => 'Graph Template Inputs',
            self::DeviceGraphs => 'Device Template Graphs', self::QueryGraphs => 'Data Query Graph Mappings',
        };
    }

    public function realm(): int
    {
        return match ($this) {
            self::LocalGraphItems, self::GraphSources, self::AggregateMembers => 5,
            self::SourceDefinitions, self::SourceGraphs, self::CollectorDevices => 3,
            self::ReportItems => 21, self::MyReportItems => 22, self::TreeItems => 4, self::NetworkDevices => 23,
            self::InputItems => 10,
            self::QuerySources => 13,
            self::QueryGraphSuggestions => 13,
            self::QueryDataSuggestions => 13,
            self::QueryDeviceTemplates => 13,
            self::GraphDeviceTemplates => 10,
            self::Mibs => 15,
            self::MibObjects => 15,
            self::MibConventions => 15,
            self::MibAttributes => 15,

            self::Graphs, self::Aggregates => 5, self::DataSources => 3,
            self::AdminReports => 21, self::MyReports => 22, self::Plugins => 101,
            self::GraphItems, self::GraphInputs => 10, self::DeviceGraphs => 12, self::QueryGraphs => 13,
        };
    }

    /** @return list<int> Grants required to display related resource names. */
    public function relatedRealms(): array
    {
        if ($this === self::GraphSources) {
            return [3];
        }
        if ($this === self::SourceGraphs) {
            return [5];
        }
        return in_array($this, [self::QueryDeviceTemplates, self::GraphDeviceTemplates], true) ? [12] : [];
    }

    public function scoped(): bool
    {
        if ($this->instanceView()) {
            return true;
        }
        return in_array($this, [self::InputItems, self::QuerySources, self::QueryGraphSuggestions, self::QueryDataSuggestions, self::QueryDeviceTemplates, self::GraphDeviceTemplates, self::MibObjects, self::MibConventions, self::MibAttributes, self::GraphItems, self::GraphInputs, self::DeviceGraphs, self::QueryGraphs], true);
    }

    public function defaultSort(): string
    {
        return match ($this) {
            self::GraphItems, self::LocalGraphItems, self::AggregateMembers, self::ReportItems, self::MyReportItems => 'sequence',
            self::TreeItems => 'position',
            default => 'name',
        };
    }

    public function route(): string
    {
        return 'management_' . str_replace('-', '_', $this->value);
    }

    public function path(): string
    {
        return 'app.php/management/' . $this->value;
    }

    public function legacyScript(): string
    {
        return match ($this) {
            self::LocalGraphItems, self::GraphSources => 'graphs.php',
            self::AggregateMembers => 'aggregate_graphs.php',
            self::SourceDefinitions, self::SourceGraphs => 'data_sources.php',
            self::ReportItems => 'reports_admin.php', self::MyReportItems => 'reports_user.php',
            self::TreeItems => 'tree.php', self::CollectorDevices => 'pollers.php', self::NetworkDevices => 'automation_devices.php',
            self::InputItems => 'graph_templates_inputs.php',
            self::QuerySources, self::QueryGraphSuggestions, self::QueryDataSuggestions, self::QueryDeviceTemplates => 'data_queries.php',
            self::GraphDeviceTemplates => 'graph_templates.php',
            self::Mibs, self::MibObjects, self::MibConventions, self::MibAttributes => 'utilities.php',
            self::Graphs => 'graphs.php', self::DataSources => 'data_sources.php', self::Aggregates => 'aggregate_graphs.php',
            self::AdminReports => 'reports_admin.php', self::MyReports => 'reports_user.php', self::Plugins => 'plugins.php',
            self::GraphItems, self::GraphInputs => 'graph_templates.php', self::DeviceGraphs => 'host_templates.php', self::QueryGraphs => 'data_queries.php',
        };
    }

    /** @return array<string, string> Complete sort allowlist. */
    public function columns(): array
    {
        if ($this === self::MibConventions) {
            return ['name' => 'Name', 'type' => 'Type', 'description' => 'Description'];
        }
        if ($this === self::MibAttributes) {
            return ['name' => 'Notification', 'attribute' => 'Attribute', 'sequence' => 'Sequence'];
        }
        return ['name' => 'Name', 'id' => 'ID'] + match ($this) {
            self::LocalGraphItems => ['sequence' => 'Sequence', 'type' => 'Graph Type ID', 'source_id' => 'Data Source ID', 'value' => 'Value'],
            self::GraphSources => ['device' => 'Device', 'active' => 'Status', 'rrd_step' => 'RRD Step'],
            self::AggregateMembers => ['sequence' => 'Sequence', 'device' => 'Device'],
            self::SourceDefinitions => ['source_type' => 'Data Source Type', 'heartbeat' => 'Heartbeat', 'minimum' => 'Minimum', 'maximum' => 'Maximum'],
            self::SourceGraphs => ['device' => 'Device', 'template' => 'Graph Template'],
            self::ReportItems, self::MyReportItems => ['sequence' => 'Sequence', 'type' => 'Item Type', 'graph_id' => 'Local Graph ID', 'device_id' => 'Device ID', 'tree_id' => 'Tree ID', 'timespan' => 'Timespan', 'alignment' => 'Alignment'],
            self::TreeItems => ['parent' => 'Parent Item ID', 'position' => 'Position', 'graph_id' => 'Local Graph ID', 'device_id' => 'Device ID', 'site_id' => 'Site ID'],
            self::CollectorDevices => ['hostname' => 'Hostname', 'disabled' => 'Disabled', 'status' => 'Recorded Status'],
            self::NetworkDevices => ['hostname' => 'Hostname', 'ip' => 'IP Address', 'os' => 'Operating System', 'known' => 'Known Device', 'up' => 'Device Up'],
            self::InputItems => ['sequence' => 'Sequence', 'type' => 'Graph Type ID', 'consolidation' => 'Consolidation ID', 'resolved' => 'Template Item Found'],
            self::QuerySources => ['data_template_id' => 'Data Template ID', 'template' => 'Data Template', 'data_source' => 'Data Source', 'resolved' => 'Template Source Found'],
            self::QueryGraphSuggestions => ['sequence' => 'Sequence', 'value' => 'Suggested Value'],
            self::QueryDataSuggestions => ['sequence' => 'Sequence', 'data_template_id' => 'Data Template ID', 'template' => 'Data Template', 'value' => 'Suggested Value'],
            self::QueryDeviceTemplates, self::GraphDeviceTemplates, self::Mibs => [],
            self::MibObjects => ['type' => 'Type', 'otype' => 'Object Type', 'kind' => 'Kind', 'access' => 'Access', 'description' => 'Description'],
            self::Graphs => ['device' => 'Device', 'template' => 'Graph Template', 'size' => 'Size', 'query_id' => 'Data Query ID', 'snmp_index' => 'Index'],
            self::DataSources => ['device' => 'Device', 'template' => 'Data Template', 'active' => 'Status', 'rrd_step' => 'RRD Step', 'profile' => 'Data Source Profile'],
            self::Aggregates => ['template' => 'Aggregate Template', 'graph_id' => 'Local Graph ID', 'size' => 'Size', 'members' => 'Member Graphs'],
            self::AdminReports, self::MyReports => ['owner' => 'Owner', 'enabled' => 'Status', 'interval' => 'Interval', 'count' => 'Interval Count', 'offset' => 'Offset', 'lastsent' => 'Last Sent (UTC)', 'mailtime' => 'Next Run (UTC)', 'attachment_type' => 'Attachment Type'],
            self::Plugins => ['directory' => 'Directory', 'version' => 'Version', 'status' => 'Recorded Status', 'author' => 'Author'],
            self::GraphItems => ['sequence' => 'Sequence', 'type' => 'Graph Type ID', 'data_source' => 'Data Source', 'cdef' => 'CDEF', 'vdef' => 'VDEF', 'value' => 'Value'],
            self::GraphInputs => ['description' => 'Description', 'field' => 'Field', 'items' => 'Associated Items'],
            self::DeviceGraphs => ['size' => 'Size', 'image_format' => 'Image Format', 'vertical_label' => 'Vertical Label'],
            self::QueryGraphs => ['template' => 'Graph Template', 'data_sources' => 'Data Source Mappings'],
        };
    }

    /** Fixed legacy destination and encoded data; never operator-supplied URLs. */
    public function legacyQuery(int $parentId = 0, ?int $recordId = null): array
    {
        if ($this->instanceView()) {
            return match ($this) {
                self::LocalGraphItems, self::GraphSources => ['action' => 'graph_edit', 'id' => $parentId],
                self::SourceDefinitions, self::SourceGraphs => ['action' => 'ds_edit', 'id' => $parentId],
                self::ReportItems, self::MyReportItems => ['action' => 'edit', 'tab' => 'items', 'id' => $parentId],
                self::NetworkDevices => ['network' => $parentId],
                default => ['action' => 'edit', 'id' => $parentId],
            };
        }
        if ($this->metadataView()) {
            return match ($this) {
                self::InputItems => ['action' => 'input_edit', 'id' => $parentId],
                self::QuerySources, self::QueryGraphSuggestions, self::QueryDataSuggestions => ['action' => 'item_edit', 'id' => $parentId],
                self::QueryDeviceTemplates => ['action' => 'edit', 'id' => $parentId],
                self::GraphDeviceTemplates => ['action' => 'template_edit', 'id' => $parentId],
                default => ['action' => 'view_snmpagent_cache'],
            };
        }
        if ($recordId === null) {
            return $this->scoped() ? ['action' => in_array($this, [self::GraphItems, self::GraphInputs], true) ? 'template_edit' : 'edit', 'id' => $parentId] : [];
        }
        return match ($this) {
            self::Graphs => ['action' => 'graph_edit', 'id' => $recordId],
            self::DataSources => ['action' => 'ds_edit', 'id' => $recordId],
            self::Aggregates => ['action' => 'edit', 'id' => $recordId],
            self::AdminReports, self::MyReports => ['action' => 'edit', 'tab' => 'details', 'id' => $recordId],
            self::GraphItems => ['action' => 'item_edit', 'id' => $recordId, 'graph_template_id' => $parentId],
            self::GraphInputs => ['action' => 'input_edit', 'id' => $recordId, 'graph_template_id' => $parentId],
            self::DeviceGraphs => ['action' => 'template_edit', 'id' => $recordId],
            self::QueryGraphs => ['action' => 'item_edit', 'id' => $recordId, 'snmp_query_id' => $parentId],
            self::Plugins => [],
            default => throw new \LogicException('This view has no editable record.'),
        };
    }

    public function metadataView(): bool
    {
        return in_array($this, [self::InputItems, self::QuerySources, self::QueryGraphSuggestions, self::QueryDataSuggestions, self::QueryDeviceTemplates, self::GraphDeviceTemplates, self::Mibs, self::MibObjects, self::MibConventions, self::MibAttributes], true);
    }

    public function editableRecord(): bool
    {
        return !$this->metadataView() && !$this->instanceView() && $this !== self::Plugins;
    }

    public function instanceView(): bool
    {
        return in_array($this, [self::LocalGraphItems, self::GraphSources, self::AggregateMembers, self::SourceDefinitions,
            self::SourceGraphs, self::ReportItems, self::MyReportItems, self::TreeItems, self::CollectorDevices, self::NetworkDevices], true);
    }

    public function personalReport(): bool
    {
        return in_array($this, [self::MyReports, self::MyReportItems], true);
    }

    public function maximumParentId(): int
    {
        if ($this->instanceView()) {
            return match ($this) {
                self::TreeItems, self::CollectorDevices => 65535,
                self::ReportItems, self::MyReportItems => 16777215,
                default => 4294967295,
            };
        }
        return in_array($this, [self::MibObjects, self::MibConventions, self::MibAttributes], true) ? 4294967295 : 16777215;
    }

    public function recordScript(): string
    {
        return match ($this) {
            self::DeviceGraphs => 'graph_templates.php', self::GraphItems => 'graph_templates_items.php',
            self::GraphInputs => 'graph_templates_inputs.php', default => $this->legacyScript(),
        };
    }
}
