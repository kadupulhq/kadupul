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

    public function title(): string
    {
        return match ($this) {
            self::Graphs => 'Graphs', self::DataSources => 'Data Sources', self::Aggregates => 'Aggregate Graphs',
            self::AdminReports => 'Administrator Reports', self::MyReports => 'My Reports', self::Plugins => 'Recorded Plugins',
            self::GraphItems => 'Graph Template Items', self::GraphInputs => 'Graph Template Inputs',
            self::DeviceGraphs => 'Device Template Graphs', self::QueryGraphs => 'Data Query Graph Mappings',
        };
    }

    public function realm(): int
    {
        return match ($this) {
            self::Graphs, self::Aggregates => 5, self::DataSources => 3,
            self::AdminReports => 21, self::MyReports => 22, self::Plugins => 101,
            self::GraphItems, self::GraphInputs => 10, self::DeviceGraphs => 12, self::QueryGraphs => 13,
        };
    }

    public function scoped(): bool
    {
        return in_array($this, [self::GraphItems, self::GraphInputs, self::DeviceGraphs, self::QueryGraphs], true);
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
            self::Graphs => 'graphs.php', self::DataSources => 'data_sources.php', self::Aggregates => 'aggregate_graphs.php',
            self::AdminReports => 'reports_admin.php', self::MyReports => 'reports_user.php', self::Plugins => 'plugins.php',
            self::GraphItems, self::GraphInputs => 'graph_templates.php', self::DeviceGraphs => 'host_templates.php', self::QueryGraphs => 'data_queries.php',
        };
    }

    /** @return array<string, string> Complete sort allowlist. */
    public function columns(): array
    {
        return ['name' => 'Name', 'id' => 'ID'] + match ($this) {
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
        };
    }

    public function recordScript(): string
    {
        return match ($this) {
            self::DeviceGraphs => 'graph_templates.php', self::GraphItems => 'graph_templates_items.php',
            self::GraphInputs => 'graph_templates_inputs.php', default => $this->legacyScript(),
        };
    }
}
