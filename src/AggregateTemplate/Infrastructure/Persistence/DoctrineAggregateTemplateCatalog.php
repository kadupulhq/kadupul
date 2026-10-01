<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplateCatalog;
use Kadupul\AggregateTemplate\Domain\AggregateTemplateCriteria;
use Kadupul\AggregateTemplate\Domain\AggregateTemplateRevision;

final readonly class DoctrineAggregateTemplateCatalog implements AggregateTemplateCatalog
{
    private const CHECKBOX_FIELDS = ['auto_padding', 'auto_scale', 'auto_scale_log', 'auto_scale_rigid', 'dynamic_labels', 'force_rules_legend', 'no_gridfit', 'scale_log_units', 'slope_mode', 'alt_y_grid'];

    private const GRAPH_FIELDS = [
        'alt_y_grid', 'auto_padding', 'auto_scale', 'auto_scale_log', 'auto_scale_opts', 'auto_scale_rigid',
        'base_value', 'dynamic_labels', 'force_rules_legend', 'grouping', 'height', 'image_format_id',
        'left_axis_formatter', 'legend_direction', 'legend_position', 'lower_limit', 'no_gridfit',
        'right_axis', 'right_axis_format', 'right_axis_formatter', 'right_axis_label', 'scale_log_units',
        'slope_mode', 'tab_width', 'unit_exponent_value', 'unit_length', 'unit_value', 'upper_limit',
        'vertical_label', 'width',
    ];

    public function __construct(private Connection $database) {}

    public function list(AggregateTemplateCriteria $criteria): array
    {
        $where = [];
        $parameters = [];
        if ($criteria->search !== '') {
            $where[] = '(t.name LIKE ? ESCAPE \'!\' OR gt.name LIKE ? ESCAPE \'!\')';
            $search = '%' . strtr($criteria->search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $parameters[] = $search;
            $parameters[] = $search;
        }
        if ($criteria->hasGraphs) {
            $where[] = 'graph_usage.graphs > 0';
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $orderBy = [
            'name' => 't.name',
            'graphs' => 'graph_usage.graphs',
            'source' => 'graph_template_name',
        ][$criteria->sort];
        $direction = strtoupper($criteria->direction);
        $from = ' FROM aggregate_graph_templates t
            LEFT JOIN graph_templates gt ON gt.id = t.graph_template_id
            LEFT JOIN (SELECT aggregate_template_id, COUNT(*) AS graphs FROM aggregate_graphs GROUP BY aggregate_template_id) graph_usage
                ON graph_usage.aggregate_template_id = t.id';
        $total = (int) $this->database->fetchOne('SELECT COUNT(*)' . $from . $whereSql, $parameters);
        $rows = $this->database->fetchAllAssociative('SELECT t.id, t.name, t.graph_template_id, gt.name AS graph_template_name, graph_usage.graphs'
            . $from . $whereSql . " ORDER BY $orderBy $direction, t.id $direction LIMIT {$criteria->pageSize} OFFSET {$criteria->offset()}", $parameters);
        return ['rows' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'name' => (string) $row['name'],
            'sourceTemplateId' => (int) $row['graph_template_id'],
            'sourceTemplateName' => (string) ($row['graph_template_name'] ?? ''),
            'graphs' => (int) ($row['graphs'] ?? 0),
            'deletable' => (int) ($row['graphs'] ?? 0) === 0,
        ], $rows), 'total' => $total];
    }

    public function editData(?int $id, int $sourceTemplateId = 0): ?array
    {
        $template = $id === null
            ? ['id' => 0, 'name' => '', 'graph_template_id' => $sourceTemplateId, 'gprint_prefix' => '', 'gprint_format' => '', 'graph_type' => 8,
                'total' => 1, 'total_type' => 1, 'total_prefix' => '', 'order_type' => 1, 'user_id' => 0]
            : $this->database->fetchAssociative('SELECT id, name, graph_template_id, gprint_prefix, gprint_format, graph_type, total, total_type, total_prefix, order_type, user_id FROM aggregate_graph_templates WHERE id = ?', [$id]);
        if ($template === false) {
            return null;
        }
        $template = array_map(static fn(mixed $value): mixed => is_numeric($value) && !is_string($value) ? (int) $value : $value, $template);
        $template['id'] = (int) $template['id'];
        $template['graph_template_id'] = (int) $template['graph_template_id'];
        $template['name'] = (string) $template['name'];
        $sourceId = (int) $template['graph_template_id'];
        $source = $sourceId > 0
            ? $this->database->fetchAssociative('SELECT id, name FROM graph_templates WHERE id = ?', [$sourceId])
            : false;
        if ($sourceId > 0 && $source === false) {
            return null;
        }

        $sourceGraph = $sourceId > 0
            ? $this->database->fetchAssociative('SELECT * FROM graph_templates_graph WHERE graph_template_id = ? ORDER BY id LIMIT 1', [$sourceId])
            : false;
        $aggregateGraph = $template['id'] > 0
            ? $this->database->fetchAssociative('SELECT * FROM aggregate_graph_templates_graph WHERE aggregate_template_id = ?', [$template['id']])
            : false;
        $graphSettings = [];
        $graphFieldMetadata = [];
        $graphChoices = [
            'image_format_id' => ['Use configured default' => 0, 'PNG' => 1, 'SVG' => 3],
            'auto_scale_opts' => [
                'RRDtool default' => 0,
                'Use --alt-autoscale (ignore limits)' => 1,
                'Use --alt-autoscale-max (accept lower limit)' => 2,
                'Use --alt-autoscale-min (accept upper limit)' => 3,
                'Use --alt-autoscale (accept both limits)' => 4,
            ],
            'left_axis_formatter' => ['None' => '', 'Numeric' => 'numeric', 'Timestamp' => 'timestamp', 'Duration' => 'duration'],
            'right_axis_formatter' => ['None' => '', 'Numeric' => 'numeric', 'Timestamp' => 'timestamp', 'Duration' => 'duration'],
            'legend_position' => ['None' => '', 'North' => 'north', 'South' => 'south', 'West' => 'west', 'East' => 'east'],
            'legend_direction' => ['None' => '', 'Top to bottom' => 'topdown', 'Bottom to top' => 'bottomup'],
        ];
        $graphChoices['right_axis_format'] = ['None' => ''] + self::namedChoices($this->database->fetchAllAssociative("SELECT name, id FROM graph_templates_gprint WHERE gprint_text NOT LIKE '%\\\\%s%' ORDER BY name, id"));
        $textLengths = ['vertical_label' => 200, 'right_axis' => 20, 'right_axis_label' => 200, 'unit_value' => 20,
            'unit_exponent_value' => 5, 'unit_length' => 10, 'tab_width' => 20, 'upper_limit' => 20, 'lower_limit' => 20];
        foreach (self::GRAPH_FIELDS as $field) {
            $baseValue = $sourceGraph[$field] ?? '';
            $override = $aggregateGraph !== false && ($aggregateGraph['t_' . $field] ?? '') === 'on';
            $value = $override ? ($aggregateGraph[$field] ?? $baseValue) : $baseValue;
            if (in_array($field, self::CHECKBOX_FIELDS, true)) {
                $value = $value === 'on';
                $kind = 'checkbox';
            } elseif (isset($graphChoices[$field])) {
                $kind = 'choice';
            } else {
                $kind = 'text';
            }
            $graphSettings[$field] = [
                'value' => $value,
                'override' => $override,
                'default' => $baseValue,
            ];
            $graphFieldMetadata[$field] = ['kind' => $kind, 'choices' => $graphChoices[$field] ?? [], 'maxLength' => $textLengths[$field] ?? 255];
        }

        $items = [];
        if ($sourceId > 0) {
            $baseItems = $this->database->fetchAllAssociative('SELECT i.id, i.sequence, i.graph_type_id, i.consolidation_function_id, i.text_format, i.value, i.hard_return, i.color_id, c.hex, cd.name AS cdef_name FROM graph_templates_item i LEFT JOIN colors c ON c.id = i.color_id LEFT JOIN cdef cd ON cd.id = i.cdef_id WHERE i.local_graph_id = 0 AND i.graph_template_id = ? ORDER BY i.sequence, i.id', [$sourceId]);
            $currentItems = $template['id'] > 0
                ? $this->database->fetchAllAssociative('SELECT * FROM aggregate_graph_templates_item WHERE aggregate_template_id = ?', [$template['id']])
                : [];
            $currentByItem = [];
            foreach ($currentItems as $current) {
                $currentByItem[(int) $current['graph_templates_item_id']] = $current;
            }
            foreach ($baseItems as $item) {
                $itemId = (int) $item['id'];
                $current = $currentByItem[$itemId] ?? [];
                $graphTypeName = self::graphTypeName((int) $item['graph_type_id']);
                $forceSkip = \Kadupul\AggregateTemplate\Domain\AggregateTemplateItemPolicy::forceSkip((int) $item['graph_type_id'], (string) $item['value'], (string) $item['text_format']);
                $items[] = [
                    'id' => $itemId, 'sequence' => (int) $item['sequence'],
                    'title' => self::itemTitle($graphTypeName, (string) $item['value'], (string) $item['text_format']),
                    'graphType' => $graphTypeName, 'consolidation' => (string) $item['consolidation_function_id'],
                    'color' => (string) ($item['hex'] ?? ''), 'cdef' => (string) ($item['cdef_name'] ?? ''),
                    'colorTemplate' => (int) ($current['color_template'] ?? 0),
                    'skip' => $forceSkip || (($current['item_skip'] ?? '') === 'on'),
                    'total' => ($current['item_total'] ?? '') === 'on', 'forceSkip' => $forceSkip,
                ];
            }
        }
        $revisionTemplate = array_intersect_key($template, array_flip(['id', 'name', 'graph_template_id', 'gprint_prefix', 'gprint_format', 'graph_type', 'total', 'total_type', 'total_prefix', 'order_type']));
        $revisionGraph = $aggregateGraph === false ? [] : $aggregateGraph;
        $revisionItems = array_map(static fn(array $item): array => array_intersect_key($item, array_flip(['aggregate_template_id', 'graph_templates_item_id', 'sequence', 'color_template', 't_graph_type_id', 'graph_type_id', 't_cdef_id', 'cdef_id', 'item_skip', 'item_total'])), $currentItems ?? []);
        $template['revision'] = $template['id'] > 0 ? AggregateTemplateRevision::fromState($revisionTemplate, $revisionGraph, $revisionItems) : '';
        $template['graphSettings'] = $graphSettings;
        $template['items'] = $items;
        $template['gprint_format'] = ($template['gprint_format'] ?? '') === 'on';

        return [
            'template' => $template,
            'source' => $source === false ? null : ['id' => $sourceId, 'name' => (string) $source['name']],
            'graphTemplates' => self::namedChoices($this->database->fetchAllAssociative('SELECT name, id FROM graph_templates ORDER BY name, id')),
            'graphSettings' => $graphSettings,
            'graphFieldMetadata' => $graphFieldMetadata,
            'items' => $items,
            'colorTemplates' => ['None' => 0] + self::namedChoices($this->database->fetchAllAssociative('SELECT name, color_template_id AS id FROM color_templates ORDER BY name, color_template_id')),
            'graphTypes' => ['Keep Graph Types' => 0, 'Keep Type and STACK' => 50, 'Convert to AREA/STACK' => 8, 'Convert to LINE1' => 4, 'Convert to LINE2' => 5, 'Convert to LINE3' => 6, 'Convert to LINE1/STACK' => 51, 'Convert to LINE2/STACK' => 52, 'Convert to LINE3/STACK' => 53],
            'totals' => ['No Totals' => 1, 'Print All Legend Items' => 2, 'Print Totaling Legend Items Only' => 3],
            'totalTypes' => ['Total Similar Data Sources' => 1, 'Total All Data Sources' => 2],
            'orderTypes' => ['No Reordering' => 1, 'Data Source, Graph' => 2, 'Graph, Data Source' => 3, 'Base Graph Order' => 4],
        ];
    }

    /** @param list<array{name:string,id:int|string}> $rows @return array<string,int> */
    private static function namedChoices(array $rows): array
    {
        $choices = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $choices[(string) $row['name'] . ' (#' . $id . ')'] = $id;
        }
        return $choices;
    }

    private static function graphTypeName(int $id): string
    {
        return [1 => 'COMMENT', 2 => 'HRULE', 3 => 'VRULE', 4 => 'LINE1', 5 => 'LINE2', 6 => 'LINE3', 7 => 'AREA', 8 => 'AREA:STACK', 9 => 'GPRINT', 10 => 'LEGEND', 11 => 'GPRINT:LAST', 12 => 'GPRINT:MAX', 13 => 'GPRINT:MIN', 14 => 'GPRINT:AVERAGE', 15 => 'LEGEND_CAMM', 20 => 'LINE:STACK', 30 => 'TICK', 40 => 'TEXTALIGN'][$id] ?? 'ITEM';
    }


    private static function itemTitle(string $type, string $value, string $text): string
    {
        return match ($type) {
            'HRULE' => 'HRULE: ' . $value,
            'VRULE' => 'VRULE: ' . $value,
            'COMMENT' => 'COMMENT: ' . $text,
            'TEXTALIGN' => 'TEXTALIGN: ' . ucfirst($text),
            default => $text,
        };
    }
}
