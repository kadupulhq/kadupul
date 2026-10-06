<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Authorize a concrete graph before reading its item associations or changing them. */
function graph_item_editor_require_scope(string $item_key, bool $require_existing): array
{
    $graph_id = auth_resource_id(get_nfilter_request_var('local_graph_id'));
    $item_id = !$require_existing && $item_key === 'id' && !isset_request_var($item_key)
        ? 0 : auth_resource_id(get_nfilter_request_var($item_key));
    if ($graph_id === null || $graph_id === 0 || $item_id === null || ($require_existing && $item_id === 0) || !is_graph_allowed($graph_id)) {
        graph_item_editor_access_denied();
    }

    $graph = db_fetch_row_prepared('SELECT id, host_id, graph_template_id FROM graph_local WHERE id = ?', array($graph_id));
    $host_id = auth_resource_id($graph['host_id'] ?? null);
    if (empty($graph) || $host_id === null || ($host_id > 0 && !is_device_allowed($host_id))) {
        graph_item_editor_access_denied();
    }

    $item = array('graph_template_id' => 0, 'local_graph_template_item_id' => 0);
    if ($item_id > 0) {
        $item = db_fetch_row_prepared('SELECT id, graph_template_id, local_graph_template_item_id FROM graph_templates_item WHERE id = ? AND local_graph_id = ?', array($item_id, $graph_id));
        if (empty($item)) {
            graph_item_editor_access_denied();
        }
    }

    set_request_var('local_graph_id', $graph_id);
    set_request_var($item_key, $item_id);
    return $item;
}

function graph_item_editor_access_denied(): never
{
    cacti_log('Unauthorized graph item request.', false, 'AUTH');
    header('Location: graphs.php?header=false');
    exit;
}

/** The picker may select any authorized device; -1 is its display-only Any sentinel. */
function graph_item_editor_require_host_filter(): void
{
    $value = get_nfilter_request_var('host_id');
    if ($value === '' || $value === '-1' || $value === -1) {
        return;
    }
    $host_id = auth_resource_id($value);
    if ($host_id === null || ($host_id > 0 && !is_device_allowed($host_id))) {
        graph_item_editor_access_denied();
    }
    set_request_var('host_id', $host_id);
}

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
