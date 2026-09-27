<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Graphing\Infrastructure\Rrd;

foreach (array(
    'GRAPH_ITEM_TYPE_LINE1' => 1,
    'GRAPH_ITEM_TYPE_LINE2' => 2,
    'GRAPH_ITEM_TYPE_LINE3' => 3,
    'GRAPH_ITEM_TYPE_LINESTACK' => 4,
    'GRAPH_ITEM_TYPE_TIC' => 5,
    'GRAPH_ITEM_TYPE_AREA' => 6,
    'GRAPH_ITEM_TYPE_STACK' => 7,
    'GRAPH_ITEM_TYPE_GPRINT' => 8,
    'GRAPH_ITEM_TYPE_GPRINT_AVERAGE' => 9,
    'GRAPH_ITEM_TYPE_GPRINT_LAST' => 10,
    'GRAPH_ITEM_TYPE_GPRINT_MAX' => 11,
    'GRAPH_ITEM_TYPE_GPRINT_MIN' => 12,
    'GRAPH_ITEM_TYPE_COMMENT' => 13,
) as $name => $value) {
    if (!defined($name)) {
        define(__NAMESPACE__ . '\\' . $name, $value);
    }
}

function cacti_sizeof($value)
{
    return count($value);
}

function generate_graph_best_cf($local_data_id, $consolidation_function_id, $rra_seconds, $rrdtool_pipe = false)
{
    $GLOBALS['graph_cf_calls'][] = array($local_data_id, $consolidation_function_id, $rra_seconds);

    return $consolidation_function_id;
}

require_once dirname(__DIR__, 4) . '/src/Graphing/Infrastructure/Rrd/GraphItemConsolidationResolver.php';

\test('consolidation references preserve graph order and GPRINT associations', function () {
    $GLOBALS['graph_cf_calls'] = array();
    $items = array(
        array('graph_type_id' => GRAPH_ITEM_TYPE_AREA, 'local_data_id' => 11, 'consolidation_function_id' => 'AVERAGE', 'data_source_name' => 'traffic', 'local_data_template_rrd_id' => 101),
        array('graph_type_id' => GRAPH_ITEM_TYPE_GPRINT, 'local_data_id' => 11, 'consolidation_function_id' => 'SUM', 'data_source_name' => 'traffic', 'local_data_template_rrd_id' => 101),
        array('graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_MAX, 'local_data_id' => 11, 'consolidation_function_id' => 'MAX', 'data_source_name' => 'traffic', 'local_data_template_rrd_id' => 101),
        array('graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'local_data_id' => 11, 'consolidation_function_id' => 'AVERAGE', 'data_source_name' => 'traffic', 'local_data_template_rrd_id' => 101),
        array('graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_LAST, 'local_data_id' => 11, 'consolidation_function_id' => 'LAST', 'data_source_name' => 'traffic', 'local_data_template_rrd_id' => 101),
        array('graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_MIN, 'local_data_id' => 11, 'consolidation_function_id' => 'MIN', 'data_source_name' => 'traffic', 'local_data_template_rrd_id' => 101),
        array('graph_type_id' => GRAPH_ITEM_TYPE_GPRINT, 'local_data_id' => 12, 'consolidation_function_id' => 'LAST', 'data_source_name' => 'errors', 'local_data_template_rrd_id' => 202),
        array('graph_type_id' => GRAPH_ITEM_TYPE_TIC, 'local_data_id' => 13, 'consolidation_function_id' => 'MIN', 'data_source_name' => 'ticks', 'local_data_template_rrd_id' => 303),
        array('graph_type_id' => GRAPH_ITEM_TYPE_LINE1, 'local_data_id' => 14, 'consolidation_function_id' => 'AVERAGE', 'data_source_name' => 'line1', 'local_data_template_rrd_id' => 404),
        array('graph_type_id' => GRAPH_ITEM_TYPE_LINE2, 'local_data_id' => 15, 'consolidation_function_id' => 'AVERAGE', 'data_source_name' => 'line2', 'local_data_template_rrd_id' => 505),
        array('graph_type_id' => GRAPH_ITEM_TYPE_LINE3, 'local_data_id' => 16, 'consolidation_function_id' => 'AVERAGE', 'data_source_name' => 'line3', 'local_data_template_rrd_id' => 606),
        array('graph_type_id' => GRAPH_ITEM_TYPE_LINESTACK, 'local_data_id' => 17, 'consolidation_function_id' => 'AVERAGE', 'data_source_name' => 'linestack', 'local_data_template_rrd_id' => 707),
        array('graph_type_id' => GRAPH_ITEM_TYPE_STACK, 'local_data_id' => 18, 'consolidation_function_id' => 'AVERAGE', 'data_source_name' => 'stack', 'local_data_template_rrd_id' => 808),
        array('graph_type_id' => GRAPH_ITEM_TYPE_COMMENT, 'local_data_id' => 19, 'consolidation_function_id' => 'MAX', 'data_source_name' => 'comment', 'local_data_template_rrd_id' => 909),
    );

    $resolver = new GraphItemConsolidationResolver();
    $last = array();
    foreach ($items as &$item) {
        $resolver->assignReference($item, $last, 300);
    }
    unset($item);

    expect(array_column($items, 'cf_reference'))->toBe(array('AVERAGE', 'AVERAGE', 'MAX', 'AVERAGE', 'LAST', 'MIN', 'LAST', 'MIN', 'AVERAGE', 'AVERAGE', 'AVERAGE', 'AVERAGE', 'AVERAGE', 'MAX'))
        ->and($last)->toBe(array(
            'traffic' => array(101 => 'AVERAGE'), 'ticks' => array(303 => 'MIN'),
            'line1' => array(404 => 'AVERAGE'), 'line2' => array(505 => 'AVERAGE'),
            'line3' => array(606 => 'AVERAGE'), 'linestack' => array(707 => 'AVERAGE'),
            'stack' => array(808 => 'AVERAGE'),
        ))
        ->and($GLOBALS['graph_cf_calls'])->toBe(array(
            array(11, 'AVERAGE', 300),
            array(12, 'LAST', 300),
            array(13, 'MIN', 300),
            array(14, 'AVERAGE', 300),
            array(15, 'AVERAGE', 300),
            array(16, 'AVERAGE', 300),
            array(17, 'AVERAGE', 300),
            array(18, 'AVERAGE', 300),
            array(19, 'MAX', 300),
        ));

    unset($GLOBALS['graph_cf_calls']);
});
