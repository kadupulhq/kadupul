<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Keep the graph and template editors' legend expansion in sync. */
function graph_item_editor_legend_items(string $type, bool $translate): array
{
    if ($type !== 'LEGEND' && $type !== 'LEGEND_CAMM') {
        return array(array());
    }
    $labels = array('4' => 'Cur:', '1' => 'Avg:');
    if ($type === 'LEGEND_CAMM') {
        $labels['2'] = 'Min:';
    }
    $labels['3'] = 'Max:';
    $items = array();
    foreach ($labels as $function => $label) {
        $items[] = array(
            'color_id' => '0',
            'graph_type_id' => '9',
            'consolidation_function_id' => (string) $function,
            'text_format' => $translate ? __($label) : $label,
            'hard_return' => $label === 'Max:' ? 'on' : ''
        );
    }
    return $items;
}

/** The width input is meaningful only for the configurable LINE:STACK type. */
function graph_item_editor_line_width_field(): array
{
    return array(
        'friendly_name' => __('Line Width'),
        'method' => 'textbox',
        'max_length' => '5',
        'default' => '1.00',
        'size' => '5',
        'description' => __('LINE1, LINE2 and LINE3 use fixed widths. For LINE:STACK, enter a positive width in pixels; integers or decimal values are supported.'),
    );
}
