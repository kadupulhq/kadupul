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

    public function parentScreen(): ListScreen
    {
        return match ($this) {
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
        $query = ['action' => $this === self::DataDefinitions ? 'template_edit' : 'edit', 'id' => $parentId];
        if ($this === self::ReceiverSubscriptions || $this === self::ReceiverHistory) {
            $query['tab'] = $this === self::ReceiverSubscriptions ? 'notifications' : 'logs';
        }
        return $query;
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
