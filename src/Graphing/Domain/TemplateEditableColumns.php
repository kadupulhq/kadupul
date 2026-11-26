<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/**
 * Canonical per-table editable column allowlists for graph creation and
 * data-source suggested-value overrides. Prevents arbitrary column
 * mass-assignment and unquoted SQL identifier interpolation.
 */
final readonly class TemplateEditableColumns
{
    /** @var array<string, string> */
    private const array GRAPH_TEMPLATE_GRAPH = [
        'title' => 'title',
        'image_format_id' => 'image_format_id',
        'height' => 'height',
        'width' => 'width',
        'base_value' => 'base_value',
        'slope_mode' => 'slope_mode',
        'auto_scale' => 'auto_scale',
        'auto_scale_opts' => 'auto_scale_opts',
        'auto_scale_log' => 'auto_scale_log',
        'scale_log_units' => 'scale_log_units',
        'auto_scale_rigid' => 'auto_scale_rigid',
        'auto_padding' => 'auto_padding',
        'export' => 'export',
        'upper_limit' => 'upper_limit',
        'lower_limit' => 'lower_limit',
        'vertical_label' => 'vertical_label',
        'unit_value' => 'unit_value',
        'unit_exponent_value' => 'unit_exponent_value',
        'unit_length' => 'unit_length',
        'no_gridfit' => 'no_gridfit',
        'alt_y_grid' => 'alt_y_grid',
        'right_axis' => 'right_axis',
        'right_axis_label' => 'right_axis_label',
        'right_axis_format' => 'right_axis_format',
        'right_axis_formatter' => 'right_axis_formatter',
        'left_axis_formatter' => 'left_axis_formatter',
        'tab_width' => 'tab_width',
        'legend_position' => 'legend_position',
        'legend_direction' => 'legend_direction',
        'force_rules_legend' => 'force_rules_legend',
        'dynamic_labels' => 'dynamic_labels',
    ];

    /** @var array<string, string> */
    private const array GRAPH_TEMPLATE_ITEM = [
        'color_id' => 'color_id',
        'alpha' => 'alpha',
        'graph_type_id' => 'graph_type_id',
        'cdef_id' => 'cdef_id',
        'vdef_id' => 'vdef_id',
        'consolidation_function_id' => 'consolidation_function_id',
        'textalign' => 'textalign',
        'text_format' => 'text_format',
        'value' => 'value',
        'hard_return' => 'hard_return',
        'line_width' => 'line_width',
        'dashes' => 'dashes',
        'dash_offset' => 'dash_offset',
        'gprint_id' => 'gprint_id',
        'shift' => 'shift',
    ];

    /** @var array<string, string> */
    private const array DATA_TEMPLATE_DATA = [
        'name' => 'name',
        'data_source_profile_id' => 'data_source_profile_id',
        'rrd_step' => 'rrd_step',
        'active' => 'active',
    ];

    /** @var array<string, string> */
    private const array DATA_TEMPLATE_RRD = [
        'rrd_maximum' => 'rrd_maximum',
        'rrd_minimum' => 'rrd_minimum',
        'rrd_heartbeat' => 'rrd_heartbeat',
        'data_source_type_id' => 'data_source_type_id',
        'data_source_name' => 'data_source_name',
    ];

    /** @var array<string, string> */
    private const array SUGGESTED_DATA_SOURCE_DATA = [
        'name' => 'name',
        'active' => 'active',
        'rrd_step' => 'rrd_step',
    ];

    /** @var array<string, string> */
    private const array SUGGESTED_DATA_SOURCE_RRD = [
        'rrd_maximum' => 'rrd_maximum',
        'rrd_minimum' => 'rrd_minimum',
        'rrd_heartbeat' => 'rrd_heartbeat',
    ];

    public static function graphTemplateColumn(string $column): ?string
    {
        return self::GRAPH_TEMPLATE_GRAPH[$column] ?? null;
    }

    public static function graphTemplateItemColumn(string $column): ?string
    {
        return self::GRAPH_TEMPLATE_ITEM[$column] ?? null;
    }

    public static function dataTemplateDataColumn(string $column): ?string
    {
        return self::DATA_TEMPLATE_DATA[$column] ?? null;
    }

    public static function dataTemplateRrdColumn(string $column): ?string
    {
        return self::DATA_TEMPLATE_RRD[$column] ?? null;
    }

    public static function suggestedDataSourceDataColumn(string $column): ?string
    {
        return self::SUGGESTED_DATA_SOURCE_DATA[$column] ?? null;
    }

    public static function suggestedDataSourceRrdColumn(string $column): ?string
    {
        return self::SUGGESTED_DATA_SOURCE_RRD[$column] ?? null;
    }

    public static function isSuggestedDataSourceFieldAllowed(string $column): bool
    {
        return isset(self::SUGGESTED_DATA_SOURCE_DATA[$column]) || isset(self::SUGGESTED_DATA_SOURCE_RRD[$column]);
    }
}
