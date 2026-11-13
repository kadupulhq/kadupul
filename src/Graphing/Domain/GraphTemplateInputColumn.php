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
 * lib/graph_template_input.php's graph_template_input_column().
 *
 * allows() is the one stored-value rule: the legacy
 * graph_template_input_value_is_allowed() delegates to it, so this file must
 * stay loadable without an autoloader or any other Kadupul class.
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

    /**
     * Whether a value fits this graph item field before it is copied between
     * graphs. Numeric fields must fit their storage columns, and text limits
     * count UTF-8 characters. Existing text whitespace remains supported.
     */
    public function allows(mixed $value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }
        $value = (string) $value;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return false;
        }
        // Digit strings compare numerically, as the legacy checks relied on.
        return match ($this) {
            self::GraphType, self::ConsolidationFunction => preg_match('/^\d{1,10}$/D', $value) === 1 && $value <= '255',
            self::DataSource => preg_match('/^\d{1,10}$/D', $value) === 1 && $value <= '4294967295',
            self::Cdef, self::Vdef, self::Gprint => preg_match('/^\d{1,10}$/D', $value) === 1 && $value <= '16777215',
            self::Color => $value === '' || (preg_match('/^\d{1,8}$/D', $value) === 1 && (int) $value <= 16777215),
            self::Alpha => preg_match('/^[A-Fa-f0-9]{2}$/D', $value) === 1,
            self::Shift, self::HardReturn => in_array($value, ['', '0', '1', 'on'], true),
            self::LineWidth => $value === '' || (strlen($value) <= 5 && preg_match('/^\d+(?:[.,]\d{1,2})?$/D', $value) === 1 && (float) str_replace(',', '.', $value) <= 99.99),
            self::DashOffset => $value === '' || (preg_match('/^-?\d{1,7}$/D', $value) === 1 && (int) $value >= -8388608 && (int) $value <= 8388607),
            self::Sequence => $value === '' || (preg_match('/^\d{1,8}$/D', $value) === 1 && (int) $value <= 16777215),
            self::Dashes => strlen($value) <= 20 && ($value === '' || preg_match('/^[0-9.,[:space:]]+$/D', $value) === 1),
            self::TextAlign => strlen($value) <= 10 && ($value === '' || preg_match('/^[A-Za-z]+$/D', $value) === 1),
            self::Value, self::TextFormat => strlen($value) <= 1020 && self::characters($value) <= 255,
        };
    }

    private static function characters(string $value): int
    {
        $characters = preg_match_all('/./us', $value);
        // Invalid UTF-8 has no character count and is refused.
        return $characters === false ? PHP_INT_MAX : $characters;
    }
}
