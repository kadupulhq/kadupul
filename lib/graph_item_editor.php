<?php

declare(strict_types=1);

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

/** Apply the same RRDtool field validation in the graph and template editors. */
function graph_item_editor_rrd_fields(mixed $alpha): array
{
    return array(
        'alpha' => form_input_validate($alpha, 'alpha', '^[a-fA-F0-9]{2}\\z', true, 3),
        'dashes' => form_input_validate(isset_request_var('dashes') ? get_nfilter_request_var('dashes') : '', 'dashes', '^[0-9]+(?:\\.[0-9]+)?(?:,[0-9]+(?:\\.[0-9]+)?)*\\z', true, 3),
        'dash_offset' => form_input_validate(isset_request_var('dash_offset') ? get_nfilter_request_var('dash_offset') : '', 'dash_offset', '^[0-9]+(?:\\.[0-9]+)?\\z', true, 3),
    );
}

/** Validate the common persisted fields without changing either editor's associations. */
function graph_item_editor_save_fields(array $item, mixed $graph_type_id): array
{
    $fields = array();
    if (isset_request_var('line_width') || isset($item['line_width'])) {
        $fields['line_width'] = form_input_validate((isset($item['line_width']) ? $item['line_width'] : get_nfilter_request_var('line_width')), 'line_width', '(^[0-9]+[\.,0-9]+$|^[0-9]+$)', true, 3);
    } else { # make sure to transfer old LINEx style into line_width on save
        switch ($graph_type_id) {
            case GRAPH_ITEM_TYPE_LINE1:
                $fields['line_width'] = 1;
                break;
            case GRAPH_ITEM_TYPE_LINE2:
                $fields['line_width'] = 2;
                break;
            case GRAPH_ITEM_TYPE_LINE3:
                $fields['line_width'] = 3;
                break;
            default:
                $fields['line_width'] = 0;
        }
    }

    $fields['cdef_id']        = form_input_validate(get_nfilter_request_var('cdef_id'), 'cdef_id', '^[0-9]+$', true, 3);
    $fields['vdef_id']        = form_input_validate(get_nfilter_request_var('vdef_id'), 'vdef_id', '^[0-9]+$', true, 3);
    $fields['shift']          = form_input_validate((isset_request_var('shift') ? get_nfilter_request_var('shift') : ''), 'shift', '^((on)|)$', true, 3);
    $fields['consolidation_function_id'] = form_input_validate((isset($item['consolidation_function_id']) ? $item['consolidation_function_id'] : get_nfilter_request_var('consolidation_function_id')), 'consolidation_function_id', '^[0-9]+$', true, 3);
    $fields['textalign']      = form_input_validate((isset_request_var('textalign') ? get_nfilter_request_var('textalign') : ''), 'textalign', '^[a-z]+$', true, 3);
    $fields['text_format']    = form_input_validate((isset($item['text_format']) ? $item['text_format'] : get_nfilter_request_var('text_format')), 'text_format', '', true, 3);
    $value_pattern = '';
    if ((int) $graph_type_id === GRAPH_ITEM_TYPE_TIC || ($fields['shift'] === CHECKED && in_array((int) $graph_type_id, array(
        GRAPH_ITEM_TYPE_LINE1,
        GRAPH_ITEM_TYPE_LINE2,
        GRAPH_ITEM_TYPE_LINE3,
        GRAPH_ITEM_TYPE_LINESTACK,
        GRAPH_ITEM_TYPE_AREA,
        GRAPH_ITEM_TYPE_STACK,
    ), true))) {
        $value_pattern = '^[+-]?(?:[0-9]+(?:\\.[0-9]*)?|[0-9]*\\.[0-9]+)(?:[eE][+-]?[0-9]+)?\\z';
    }
    $fields['value']          = form_input_validate(get_nfilter_request_var('value'), 'value', $value_pattern, true, 3);
    $fields['hard_return']    = form_input_validate(((isset($item['hard_return']) ? $item['hard_return'] : (isset_request_var('hard_return') ? get_nfilter_request_var('hard_return') : ''))), 'hard_return', '', true, 3);
    $fields['gprint_id']      = form_input_validate(get_nfilter_request_var('gprint_id'), 'gprint_id', '^[0-9]+$', true, 3);

    return $fields;
}
