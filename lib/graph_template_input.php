<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

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
