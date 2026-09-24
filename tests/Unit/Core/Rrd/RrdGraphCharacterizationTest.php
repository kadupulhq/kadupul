<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

function rrd_characterization_items(): array
{
    $in = rrd_characterization_ds('traffic_in');
    $out = rrd_characterization_ds('traffic_out');
    $errors = rrd_characterization_ds('errors');

    return array(
        rrd_characterization_item(1, 'AREA', $in + array('hex' => '00CF00', 'text_format' => 'Inbound "peak" 100%: caf' . "\u{e9}")),
        rrd_characterization_item(2, 'GPRINT_LAST', $in + array('text_format' => 'Current:', 'gprint_text' => '%8.2lf %s')),
        rrd_characterization_item(3, 'GPRINT_AVERAGE', $in + array('text_format' => 'Average 5%:', 'gprint_text' => '%8.2lf %s')),
        rrd_characterization_item(4, 'GPRINT_MAX', $in + array('text_format' => 'Maximum:', 'gprint_text' => '%8.2lf %s', 'consolidation_function_id' => '3')),
        rrd_characterization_item(5, 'GPRINT_MIN', $in + array('text_format' => 'Minimum:', 'gprint_text' => '%8.2lf %s', 'consolidation_function_id' => '2', 'hard_return' => 'on')),
        rrd_characterization_item(6, 'LINE1', $out + array('hex' => '002A97', 'alpha' => '80', 'text_format' => 'Outbound <bits>', 'cdef_id' => '1', 'dashes' => '5,3', 'dash_offset' => '2')),
        rrd_characterization_item(7, 'GPRINT', $out + array('text_format' => '95th: 50%', 'gprint_text' => '%8.2lf %s', 'cdef_id' => '1', 'vdef_id' => '1', 'hard_return' => 'on')),
        rrd_characterization_item(8, 'GPRINT', $out + array('text_format' => 'Last:', 'gprint_text' => '%8.2lf %s', 'consolidation_function_id' => '4')),
        rrd_characterization_item(9, 'LINE2', $errors + array('hex' => 'FF0000', 'text_format' => 'Errors |query_ifAlias|', 'shift' => 'on', 'value' => '3600')),
        rrd_characterization_item(10, 'LINE3', $errors + array('hex' => '0000FF', 'text_format' => 'Errors max', 'consolidation_function_id' => '3', 'cdef_id' => '2')),
        rrd_characterization_item(11, 'STACK', $in + array('hex' => '00FF00', 'alpha' => '4D', 'text_format' => 'Stacked', 'shift' => 'on', 'value' => '60')),
        rrd_characterization_item(12, 'LINESTACK', $out + array('hex' => '123456', 'line_width' => '1.50', 'text_format' => 'Line stack', 'dashes' => '4')),
        rrd_characterization_item(13, 'TIC', $errors + array('hex' => 'FF00FF', 'value' => '0.5', 'text_format' => 'Tick')),
        rrd_characterization_item(14, 'HRULE', array('hex' => 'FF9900', 'value' => '95', 'text_format' => 'Threshold: 95% |host_description|', 'dashes' => '2', 'dash_offset' => '1', 'hard_return' => 'on')),
        rrd_characterization_item(15, 'VRULE', array('hex' => '000000', 'value' => '1700001800', 'text_format' => 'Change: "deploy"')),
        rrd_characterization_item(16, 'COMMENT', array('text_format' => 'Host |host_description| & <b>bold</b> 50%: done', 'hard_return' => 'on')),
        rrd_characterization_item(17, 'COMMENT', array('text_format' => '', 'hard_return' => 'on')),
        rrd_characterization_item(18, 'TEXTALIGN', array('textalign' => 'center')),
        rrd_characterization_item(19, 'LEGEND', $errors + array('text_format' => 'Legend')),
        rrd_characterization_item(20, 'LEGEND_CAMM', $errors + array('text_format' => 'Legend CAMM')),
    );
}

function rrd_characterization_graph_scenario(array $graph_data_array, array $options = array(), array $graph = array(), ?array $items = null, array $extra = array()): array
{
    $graph = rrd_characterization_graph($graph);
    $items ??= rrd_characterization_items();

    return array_replace(array(
        'files' => array('router_traffic_11.rrd', 'router_errors_12.rrd'),
        'rrd_cfs' => array('AVERAGE', 'MIN', 'MAX', 'LAST'),
        'options' => $options + rrd_characterization_options(),
        'db' => rrd_characterization_graph_db($graph, $items),
        'calls' => array(array('fn' => 'rrdtool_function_graph', 'args' => array(7, 0, $graph_data_array, false, array(), 0))),
    ), $extra);
}

dataset('rrd graph scenarios', function () {
    $window = array('graph_start' => 1700000000, 'graph_end' => 1700003600);
    $area = array(rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in') + array('hex' => '3366CC', 'alpha' => '99', 'text_format' => 'Inbound: 50%')));
    $xport = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n<xport><meta><start>1700000000</start><step>300</step><end>1700000600</end><rows>2</rows><columns>2</columns>"
        . '<legend><entry>Inbound</entry><entry>col1-d</entry></legend></meta><data><row><t>1700000300</t><v>1.0e+00</v><v>NaN</v></row>'
        . "<row><t>1700000600</t><v>2.5e+00</v><v>3.0e+00</v></row></data></xport>\n";

    return array(
        'every item type' => array('graph-items', rrd_characterization_graph_scenario($window)),
        'print_source' => array('graph-print-source', rrd_characterization_graph_scenario($window + array('print_source' => true))),
        'no legend' => array('graph-no-legend', rrd_characterization_graph_scenario($window + array('graph_nolegend' => true))),
        'relative window' => array('graph-relative-window', rrd_characterization_graph_scenario(array('graph_start' => 0, 'graph_end' => 0, 'print_source' => true), array(), array(), $area)),
        'gradient area' => array('graph-gradient', rrd_characterization_graph_scenario($window, array('enable_rrdtool_gradient_support' => 'on'), array(), $area)),
        'business hours' => array('graph-business-hours', rrd_characterization_graph_scenario(
            array('graph_start' => 1699900000, 'graph_end' => 1700400000),
            array('business_hours_enable' => 'on', 'business_hours_start' => '08:00', 'business_hours_end' => '17:30', 'business_hours_max_days' => '8', 'business_hours_color' => 'ffeeaa80', 'business_hours_hideWeekends' => ''),
            array(),
            $area
        )),
        'config fonts, watermark and dark theme' => array('graph-fonts-watermark', rrd_characterization_graph_scenario(
            $window,
            array(
                'font_method' => '0', 'title_font' => 'DejaVu Sans Bold', 'title_size' => '3', 'axis_font' => "Mono 'x'", 'axis_size' => '7',
                'legend_font' => '', 'legend_size' => 'big', 'unit_font' => 'Sans', 'unit_size' => '9', 'watermark_font' => 'Sans', 'watermark_size' => '5',
                'rrdtool_watermark' => 'on', 'graph_watermark' => 'Kadupul\'s "graph" & <co>', 'selected_theme' => 'midwinter',
            ),
            array(),
            $area,
            array('cookies' => array('CactiColorMode' => 'dark'))
        )),
        'export to file' => array('graph-export', rrd_characterization_graph_scenario($window + array('export' => true, 'export_filename' => 'rra/graph_7.png', 'graphv' => true), array(), array('image_format_id' => '3'), $area)),
        'missing rrd file' => array('graph-missing-rrd', rrd_characterization_graph_scenario($window + array('print_source' => true), array(), array(), $area, array('files' => array()))),
        'xport' => array('graph-xport', rrd_characterization_graph_scenario(
            $window,
            array(),
            array(),
            array(
                $area[0],
                rrd_characterization_item(2, 'LINE1', rrd_characterization_ds('traffic_out') + array('hex' => '002A97', 'cdef_id' => '1')),
                rrd_characterization_item(3, 'STACK', rrd_characterization_ds('errors') + array('hex' => 'FF0000', 'text_format' => 'Errors: "all"')),
                rrd_characterization_item(4, 'GPRINT_LAST', rrd_characterization_ds('errors') + array('text_format' => 'Now', 'gprint_text' => '%8.2lf')),
            ),
            array(
                'replies' => array('xport' => $xport),
                'calls' => array(array('fn' => 'rrdtool_function_xport', 'args' => array(7, 0, $window + array('export_csv' => true), array(), 0))),
            )
        )),
    );
});

test('graph command matches its golden', function (string $golden, array $scenario) {
    $output = rrd_characterization_run($this, $scenario);
    expect($output['results'])->toHaveCount(1);
    $observed = rrd_characterization_observed($output['results'][0]);
    // A relative window prints wall-clock dates in its legend.
    if ($golden === 'graph-relative-window') {
        $observed['printed'] = preg_replace('#\d{4}/\d{2}/\d{2} \d{2}\\\\:\d{2}\\\\:\d{2}#', '<date>', $observed['printed'], -1, $dates);
        expect($dates)->toBe(2);
    }
    // xport also fills its by-reference metadata argument.
    if ($scenario['calls'][0]['fn'] === 'rrdtool_function_xport') {
        $observed['xport_meta'] = $output['results'][0]['args'][3];
    }
    rrd_characterization_golden($golden, $observed);
})->with('rrd graph scenarios');

test('graph options match their golden for each scale and axis setting', function () {
    $window = array('graph_start' => 1700000000, 'graph_end' => 1700003600);
    $cases = array(
        'autoscale 1 ignores limits' => array(array('auto_scale_opts' => '1'), $window),
        'autoscale 2 keeps lower limit' => array(array('auto_scale_opts' => '2', 'lower_limit' => '-5'), $window),
        'autoscale 2 skips non-numeric lower limit' => array(array('auto_scale_opts' => '2', 'lower_limit' => 'abc'), $window),
        'autoscale 3 keeps upper limit' => array(array('auto_scale_opts' => '3', 'upper_limit' => '1e3'), $window),
        'autoscale 4 keeps both limits' => array(array('auto_scale_opts' => '4', 'upper_limit' => '100', 'lower_limit' => '10'), $window),
        'fixed limits' => array(array('auto_scale' => '', 'upper_limit' => "100'; x", 'lower_limit' => '-1'), $window),
        'fixed without limits' => array(array('auto_scale' => '', 'upper_limit' => '', 'lower_limit' => ''), $window),
        'logarithmic with si units' => array(array('auto_scale_log' => 'on', 'scale_log_units' => 'on', 'auto_scale_rigid' => ''), $window),
        'si units need logarithmic' => array(array('scale_log_units' => 'on'), $window),
        'units and grid' => array(array('unit_value' => '1:5', 'unit_exponent_value' => '3', 'alt_y_grid' => 'on', 'base_value' => '1024'), $window),
        'non-numeric exponent' => array(array('unit_exponent_value' => '-3', 'base_value' => '1001'), $window),
        'right axis and formatters' => array(array(
            'right_axis' => '2:0', 'right_axis_label' => 'bytes "out"', 'right_axis_format' => '4', 'no_gridfit' => 'on',
            'unit_length' => '10', 'tab_width' => '30', 'dynamic_labels' => 'on', 'force_rules_legend' => 'on',
            'legend_position' => 'south', 'legend_direction' => 'bottomup', 'left_axis_formatter' => 'numeric', 'right_axis_formatter' => 'timestamp',
        ), $window),
        'empty title and label' => array(array('title_cache' => '', 'vertical_label' => '', 'slope_mode' => ''), $window),
        'overrides and output file' => array(array(), array('graph_height' => '150', 'graph_width' => 'abc', 'output_filename' => 'out.png', 'image_format' => 'png')),
        'no legend and export' => array(array(), array('graph_nolegend' => true, 'graph_height' => '150', 'graph_width' => '300', 'export' => true, 'export_filename' => 'x.svg')),
    );
    $calls = array();
    foreach ($cases as $case) {
        $calls[] = array('fn' => 'rrd_function_process_graph_options', 'args' => array(1700000000, 1700003600, rrd_characterization_graph($case[0]), $case[1]));
    }
    $db = rrd_characterization_graph_db(rrd_characterization_graph(), array());
    $output = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'db' => $db, 'calls' => $calls));
    $observed = array();
    foreach (array_keys($cases) as $index => $name) {
        $observed[$name] = explode(" \\\n", $output['results'][$index]['returned']);
    }
    rrd_characterization_golden('graph-options', $observed);

    // get_rrdtool_version() caches per process, so RRDtool 1.3 needs its own run.
    $old = rrd_characterization_run($this, array('options' => array('rrdtool_version' => '1.3.0') + rrd_characterization_options(), 'db' => $db, 'calls' => array($calls[11])));
    rrd_characterization_golden('graph-options-rrdtool-1.3', explode(" \\\n", $old['results'][0]['returned']));
});
