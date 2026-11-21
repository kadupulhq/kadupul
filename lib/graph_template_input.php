<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// The graph template input value rule is shared with the Symfony editor.
require_once __DIR__ . '/../src/Graphing/Domain/GraphTemplateInputColumn.php';

function graph_template_input_column($column)
{
    // SQL identifiers cannot be parameter-bound. Return only a canonical literal.
    static $columns = array(
        'graph_type_id' => 'graph_type_id',
        'task_item_id' => 'task_item_id',
        'color_id' => 'color_id',
        'alpha' => 'alpha',
        'consolidation_function_id' => 'consolidation_function_id',
        'cdef_id' => 'cdef_id',
        'vdef_id' => 'vdef_id',
        'shift' => 'shift',
        'value' => 'value',
        'gprint_id' => 'gprint_id',
        'textalign' => 'textalign',
        'text_format' => 'text_format',
        'hard_return' => 'hard_return',
        'line_width' => 'line_width',
        'dashes' => 'dashes',
        'dash_offset' => 'dash_offset',
        'sequence' => 'sequence'
    );

    return is_string($column) && isset($columns[$column]) ? $columns[$column] : null;
}

/**
 * Validate a value for a graph template input field before it is written to
 * graph items. The rule lives in GraphTemplateInputColumn::allows(), which the
 * Symfony graph item input editor uses too.
 */
function graph_template_input_value_is_allowed($column, $value)
{
    $field = is_string($column) ? \Kadupul\Graphing\Domain\GraphTemplateInputColumn::tryFrom($column) : null;

    return $field !== null && $field->allows($value);
}

/** Convert validated form values to storage form while preserving existing empty numeric semantics. */
function graph_template_input_storage_value(mixed $column, mixed $value): mixed
{
    if ($value !== null && is_scalar($value) && (string) $value === ''
        && in_array($column, array('color_id', 'line_width', 'dash_offset', 'sequence'), true)) {
        return '0';
    }

    return $column === 'line_width' && is_string($value) ? str_replace(',', '.', $value) : $value;
}

/**
 * Verify that a graph input and every selected template item belong to one
 * graph template. An input id of 0 is a new input.
 *
 * @param array<int|string> $graph_template_item_ids
 */
function graph_template_input_relationships_are_valid($input_id, $graph_template_id, $graph_template_item_ids)
{
    $input_id          = (int) $input_id;
    $graph_template_id = (int) $graph_template_id;

    if ($graph_template_id <= 0 || $input_id < 0 || !is_array($graph_template_item_ids)) {
        return false;
    }

    if ($input_id > 0 && (int) db_fetch_cell_prepared('SELECT COUNT(*)
		FROM graph_template_input
		WHERE id = ?
		AND graph_template_id = ?', array($input_id, $graph_template_id)) !== 1) {
        return false;
    }

    $graph_template_item_ids = array_values(array_unique(array_map('intval', $graph_template_item_ids)));

    foreach ($graph_template_item_ids as $item_id) {
        if ($item_id <= 0) {
            return false;
        }
    }

    if (!cacti_sizeof($graph_template_item_ids)) {
        return true;
    }

    $placeholders = implode(',', array_fill(0, cacti_sizeof($graph_template_item_ids), '?'));

    return (int) db_fetch_cell_prepared(
        'SELECT COUNT(*)
		FROM graph_templates_item
		WHERE graph_template_id = ?
		AND local_graph_id = 0
		AND id IN (' . $placeholders . ')',
        array_merge(array($graph_template_id), $graph_template_item_ids)
    ) === cacti_sizeof($graph_template_item_ids);
}

/**
 * Check that every graph input in one decoded XML graph template names only
 * items of that same template. The hash cache used during import spans the
 * whole package, so an unchecked reference could link another template's item.
 */
function graph_template_input_xml_items_are_valid($xml_array)
{
    $available_items = array();

    foreach (is_array($xml_array['items'] ?? null) ? array_keys($xml_array['items']) : array() as $item_hash) {
        $parsed_hash = parse_xml_hash($item_hash);

        if ($parsed_hash === false) {
            return false;
        }

        $available_items[$parsed_hash['hash']] = true;
    }

    foreach (is_array($xml_array['inputs'] ?? null) ? $xml_array['inputs'] : array() as $input) {
        if (!is_array($input) || (isset($input['items']) && !is_string($input['items']))) {
            return false;
        }

        foreach (array_filter(explode('|', $input['items'] ?? ''), 'strlen') as $item_hash) {
            $parsed_hash = parse_xml_hash($item_hash);

            if ($parsed_hash === false || !isset($available_items[$parsed_hash['hash']])) {
                return false;
            }
        }
    }

    return true;
}
