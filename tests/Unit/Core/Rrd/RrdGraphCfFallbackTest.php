<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

/*
 * The RRD holds AVERAGE and MAX only. The AREA asks for MAX and gets a MAX
 * DEF. GPRINT_LAST keeps LAST as its cf_reference and gets a LAST DEF of its
 * own. In the item loop, generate_graph_best_cf() answers AVERAGE for LAST,
 * no AVERAGE DEF exists, and the fallback chain picks MAX. The line after the
 * chain replaces that with cf_reference, so the GPRINT reads the LAST DEF.
 */
test('dead code: the CF fallback chain in __rrdtool_function_graph is always overwritten by cf_reference', function () {
    $in = rrd_characterization_ds('traffic_in');
    $items = array(
        rrd_characterization_item(1, 'AREA', $in + array('hex' => '00CF00', 'text_format' => 'Inbound', 'consolidation_function_id' => '3')),
        rrd_characterization_item(2, 'GPRINT_LAST', $in + array('text_format' => 'Now:', 'gprint_text' => '%8.2lf %s', 'consolidation_function_id' => '4')),
    );
    $graph = rrd_characterization_graph();
    $scenario = array(
        'files' => array('router_traffic_11.rrd', 'router_errors_12.rrd'),
        'rrd_cfs' => array('AVERAGE', 'MAX'),
        'options' => rrd_characterization_options(),
        'db' => rrd_characterization_graph_db($graph, $items),
        'calls' => array(array('fn' => 'rrdtool_function_graph', 'args' => array(7, 0, array('graph_start' => 1700000000, 'graph_end' => 1700003600), false, array(), 0))),
    );
    $observed = rrd_characterization_observed(rrd_characterization_run($this, $scenario)['results'][0]);
    $graph_command = end($observed['commands'])['stdin'];

    expect($graph_command)->toContain("DEF:a='rra/router_traffic_11.rrd':'traffic_in':MAX")
        ->and($graph_command)->toContain("DEF:b='rra/router_traffic_11.rrd':'traffic_in':LAST")
        ->and(implode('  ', $graph_command))->toContain('GPRINT:b:LAST:')
        ->and(implode('  ', $graph_command))->not->toContain('GPRINT:a:LAST:');
    rrd_characterization_golden('graph-cf-fallback', $observed);
});
