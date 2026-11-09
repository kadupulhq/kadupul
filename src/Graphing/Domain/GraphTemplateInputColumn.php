<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/**
 * The graph item fields a graph item input can carry, in the order the legacy
 * Field Type drop-down listed them. The values are the same allowlist as
 * lib/graph_template_input.php's graph_template_input_column(), which the
 * editor still consults before a column name reaches SQL.
 */
enum GraphTemplateInputColumn: string
{
    case GraphType = 'graph_type_id';
    case DataSource = 'task_item_id';
    case Color = 'color_id';
    case Alpha = 'alpha';
    case ConsolidationFunction = 'consolidation_function_id';
    case Cdef = 'cdef_id';
    case Vdef = 'vdef_id';
    case Shift = 'shift';
    case Value = 'value';
    case Gprint = 'gprint_id';
    case TextAlign = 'textalign';
    case TextFormat = 'text_format';
    case HardReturn = 'hard_return';
    case LineWidth = 'line_width';
    case Dashes = 'dashes';
    case DashOffset = 'dash_offset';
    case Sequence = 'sequence';

    /** The legacy friendly name; a message key in the graph_template_input domain. */
    public function label(): string
    {
        return match ($this) {
            self::GraphType => 'Graph Item Type',
            self::DataSource => 'Data Source',
            self::Color => 'Color',
            self::Alpha => 'Opacity/Alpha Channel',
            self::ConsolidationFunction => 'Consolidation Function',
            self::Cdef => 'CDEF Function',
            self::Vdef => 'VDEF Function',
            self::Shift => 'Shift Data',
            self::Value => 'Value',
            self::Gprint => 'GPRINT Type',
            self::TextAlign => 'Text Alignment (TEXTALIGN)',
            self::TextFormat => 'Text Format',
            self::HardReturn => 'Insert Hard Return',
            self::LineWidth => 'Line Width',
            self::Dashes => 'Dashes (dashes[=on_s[,off_s[,on_s,off_s]...]])',
            self::DashOffset => 'Dash Offset (dash-offset=offset)',
            self::Sequence => 'Sequence',
        };
    }
}
