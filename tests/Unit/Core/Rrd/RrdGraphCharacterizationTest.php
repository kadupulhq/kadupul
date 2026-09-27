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

/**
 * Every value a quoted argument can carry: a quote, a space, a percent sign, a
 * colon and a double quote, in a data source path, legends, GPRINT and COMMENT
 * text and a font path. RRDtool must be able to read the result, so the data
 * source names stay valid and the graph goes to /dev/null.
 */
/** Replace the ifAlias value that |query_ifAlias| substitutes. */
function rrd_characterization_if_alias(array $db, string $alias): array
{
    foreach ($db as $index => $row) {
        if ($row['sql'] === 'field_name, field_value FROM host_snmp_cache') {
            $db[$index]['result'][0]['field_value'] = $alias;
        }
    }

    return $db;
}

function rrd_characterization_quoting_scenario(): array
{
    $in = rrd_characterization_ds('traffic_in');
    $out = rrd_characterization_ds('traffic_out');
    $items = array(
        rrd_characterization_item(1, 'AREA', $in + array('hex' => '00CF00', 'text_format' => 'In\'s "peak" 50%: a b')),
        rrd_characterization_item(2, 'GPRINT_LAST', $in + array('text_format' => 'Now:', 'gprint_text' => '%8.2lf %s it\'s "q"')),
        rrd_characterization_item(3, 'LINE1', $out + array('hex' => '002A97')),
        rrd_characterization_item(4, 'GPRINT', $out + array('text_format' => 'Out\'s:', 'gprint_text' => '%6.1lf%% \'x\'', 'consolidation_function_id' => '4')),
        rrd_characterization_item(5, 'COMMENT', array('text_format' => 'Don\'t "stop" 100%: now', 'hard_return' => 'on')),
    );
    $scenario = rrd_characterization_graph_scenario(
        array('graph_start' => 1700000000, 'graph_end' => 1700003600, 'output_filename' => '/dev/null'),
        array('font_method' => '0', 'title_font' => "fonts/My Font's.ttf", 'title_size' => '10', 'axis_font' => 'DejaVu Sans', 'axis_size' => '8'),
        // Substituted values are not HTML-escaped, so this alias avoids & and <
        // that Pango would warn about.
        array('title_cache' => "Router's |query_ifAlias|", 'vertical_label' => 'bits', 'right_axis' => '1:0', 'right_axis_label' => '|query_ifAlias| out'),
        $items,
        array('files' => array("router's traffic_11.rrd"))
    );
    $scenario['db'] = rrd_characterization_if_alias($scenario['db'], 'O\'Brien "core" 50%: a b');
    foreach ($scenario['db'] as $index => $row) {
        if ($row['sql'] === 'SELECT name, data_source_path FROM data_template_data' && $row['params'] === array(11)) {
            $scenario['db'][$index]['result']['data_source_path'] = "rra/router's traffic_11.rrd";
        }
    }

    return $scenario;
}

/**
 * Every magic CDEF variable, on items that repeat a data source (traffic_in
 * twice), share a data source name across files (traffic_in in the errors
 * file) or have no data source at all (the HRULE falls back to the poller
 * interval). The RRD step differs from the poller interval so the two _PI
 * sources stay apart.
 */
function rrd_characterization_magic_cdef_scenario(): array
{
    $in = rrd_characterization_ds('traffic_in');
    $similar = array('data_template_rrd_id' => '104', 'local_data_id' => '12', 'local_data_template_rrd_id' => '4', 'rrd_minimum' => '0', 'rrd_maximum' => 'U', 'data_source_name' => 'traffic_in', 'snmp_query_id' => '1', 'snmp_index' => '2');
    $items = array(
        rrd_characterization_item(1, 'AREA', $in + array('hex' => '00CF00', 'text_format' => 'In', 'cdef_id' => '3')),
        rrd_characterization_item(2, 'STACK', $in + array('hex' => '00FF00', 'text_format' => 'In again', 'cdef_id' => '4')),
        rrd_characterization_item(3, 'LINE1', rrd_characterization_ds('traffic_out') + array('hex' => '002A97', 'text_format' => 'Out', 'cdef_id' => '3')),
        rrd_characterization_item(4, 'LINE2', $similar + array('hex' => 'FF0000', 'text_format' => 'Similar in', 'cdef_id' => '3')),
        rrd_characterization_item(5, 'LINE3', rrd_characterization_ds('errors') + array('hex' => '0000FF', 'text_format' => 'Errors', 'cdef_id' => '5')),
        rrd_characterization_item(6, 'HRULE', array('hex' => 'FF9900', 'value' => '95', 'text_format' => 'Rule', 'cdef_id' => '3')),
    );
    $scenario = rrd_characterization_graph_scenario(array('graph_start' => 1700000000, 'graph_end' => 1700003600), array(), array(), $items);
    foreach ($scenario['db'] as $index => $row) {
        if ($row['sql'] === 'SELECT rrd_step FROM data_template_data WHERE local_data_id') {
            $scenario['db'][$index]['result'] = '60';
        }
    }
    $scenario['db'] = array_merge(
        $scenario['db'],
        rrd_characterization_rpn('cdef', 3, array(
            41 => array('6', 'ALL_DATA_SOURCES_DUPS,ALL_DATA_SOURCES_NODUPS,SIMILAR_DATA_SOURCES_DUPS,SIMILAR_DATA_SOURCES_NODUPS'),
            42 => array('6', 'COUNT_ALL_DS_DUPS,COUNT_ALL_DS_NODUPS,COUNT_SIMILAR_DS_DUPS,COUNT_SIMILAR_DS_NODUPS'),
            43 => array('6', 'CURRENT_DATA_SOURCE_PI,CURRENT_DATA_SOURCE,+,+,+,+,+,+,+,+,+'),
        )),
        // Each _PI name also contains its plain name, which is still accumulated
        // but replaced only after the _PI form.
        rrd_characterization_rpn('cdef', 4, array(
            51 => array('6', 'ALL_DATA_SOURCES_DUPS_PI,ALL_DATA_SOURCES_NODUPS_PI,SIMILAR_DATA_SOURCES_DUPS_PI,SIMILAR_DATA_SOURCES_NODUPS_PI,COUNT_ALL_DS_DUPS,+,+,+,+'),
        )),
        rrd_characterization_rpn('cdef', 5, array(
            61 => array('6', 'SIMILAR_DATA_SOURCES_DUPS,COUNT_SIMILAR_DS_NODUPS,/,ALL_DATA_SOURCES_DUPS_PI,*'),
        )),
    );

    return $scenario;
}

dataset('rrd graph scenarios', function () {
    $window = array('graph_start' => 1700000000, 'graph_end' => 1700003600);
    $area = array(rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in') + array('hex' => '3366CC', 'alpha' => '99', 'text_format' => 'Inbound: 50%')));
    $xport = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n<xport><meta><start>1700000000</start><step>300</step><end>1700000600</end><rows>2</rows><columns>2</columns>"
        . '<legend><entry>Inbound</entry><entry>col1-d</entry></legend></meta><data><row><t>1700000300</t><v>1.0e+00</v><v>NaN</v></row>'
        . "<row><t>1700000600</t><v>2.5e+00</v><v>3.0e+00</v></row></data></xport>\n";

    // A substituted value is not HTML-escaped and must not end the quoted argument.
    $quoting = array('title_cache' => 'Link |host_description|', 'vertical_label' => '|host_description| bps');
    $quoting_db = rrd_characterization_graph_db(rrd_characterization_graph($quoting), $area);
    foreach ($quoting_db as $index => $row) {
        if ($row['sql'] === 'FROM host AS h LEFT JOIN sites') {
            $quoting_db[$index]['result']['description'] = 'O\'Brien "core" \\ 50%: a b';
        }
    }

    // CR and LF in a substituted value would end the command line; they are removed.
    $newline_db = $quoting_db;
    foreach ($newline_db as $index => $row) {
        if ($row['sql'] === 'FROM host AS h LEFT JOIN sites') {
            $newline_db[$index]['result']['description'] = "core\r\nedge\rleft\nright";
        }
    }

    // Different quoted values in the title and the vertical label show a swapped placeholder.
    $pair = array('title_cache' => 'Link |host_description|', 'vertical_label' => '|query_ifAlias| bps');
    $pair_db = rrd_characterization_if_alias(rrd_characterization_graph_db(rrd_characterization_graph($pair), $area), 'it\'s "alias"');
    foreach ($pair_db as $index => $row) {
        if ($row['sql'] === 'FROM host AS h LEFT JOIN sites') {
            $pair_db[$index]['result']['description'] = 'O\'Brien "host"';
        }
    }

    $nul_items = array_merge($area, array(rrd_characterization_item(2, 'COMMENT', array('text_format' => 'Host |host_description|'))));
    $nul_db = rrd_characterization_graph_db(rrd_characterization_graph(), $nul_items);
    foreach ($nul_db as $index => $row) {
        if ($row['sql'] === 'FROM host AS h LEFT JOIN sites') {
            $nul_db[$index]['result']['description'] = "core\0edge";
        }
    }

    $right_axis = array('right_axis' => '1:0', 'right_axis_label' => '|query_ifAlias|');
    $right_axis_db = rrd_characterization_graph_db(rrd_characterization_graph($right_axis), $area);

    return array(
        'every item type' => array('graph-items', rrd_characterization_graph_scenario($window)),
        'print_source' => array('graph-print-source', rrd_characterization_graph_scenario($window + array('print_source' => true))),
        'no legend' => array('graph-no-legend', rrd_characterization_graph_scenario($window + array('graph_nolegend' => true))),
        'relative window' => array('graph-relative-window', rrd_characterization_graph_scenario(array('graph_start' => 0, 'graph_end' => 0, 'print_source' => true), array(), array(), $area)),
        'gradient area' => array('graph-gradient', rrd_characterization_graph_scenario($window, array('enable_rrdtool_gradient_support' => 'on'), array(), $area)),
        // gradient() is reached only from graph generation, whose wrapper turns the refusal into the error answer.
        'a NUL in a gradient legend' => array('graph-gradient-nul', rrd_characterization_graph_scenario(
            $window + array('print_source' => true),
            array('enable_rrdtool_gradient_support' => 'on'),
            array(),
            array(rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in') + array('hex' => '3366CC', 'text_format' => "In\0bound")))
        )),
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
        'magic CDEF variables' => array('graph-cdef-magic', rrd_characterization_magic_cdef_scenario()),
        'quotes, spaces, percent, colon and double quotes in arguments' => array('graph-pipe-quoting', rrd_characterization_quoting_scenario()),
        'a NUL in a substituted comment' => array('graph-unrepresentable', rrd_characterization_graph_scenario(
            $window + array('print_source' => true),
            array(),
            array(),
            $nul_items,
            array('db' => $nul_db)
        )),
        'a quote in a substituted right axis label' => array('graph-substituted-right-axis', rrd_characterization_graph_scenario(
            $window,
            array(),
            $right_axis,
            $area,
            array('db' => rrd_characterization_if_alias($right_axis_db, 'it\'s --daemon x'))
        )),
        'a NUL in a substituted right axis label' => array('graph-substituted-right-axis-nul', rrd_characterization_graph_scenario(
            $window + array('print_source' => true),
            array(),
            $right_axis,
            $area,
            array('db' => rrd_characterization_if_alias($right_axis_db, "core\0edge"))
        )),
        'quotes in a substituted title' => array('graph-substituted-quotes', rrd_characterization_graph_scenario($window, array(), $quoting, $area, array('db' => $quoting_db))),
        'CR and LF in a substituted title and vertical label' => array('graph-substituted-newlines', rrd_characterization_graph_scenario($window, array(), $quoting, $area, array('db' => $newline_db))),
        'different quoted values in the title and vertical label' => array('graph-substituted-pair', rrd_characterization_graph_scenario($window, array(), $pair, $area, array('db' => $pair_db))),
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

test('a generated graph command renders in RRDtool', function () {
    $binary = getenv('RRDTOOL_TEST_BINARY');
    if (!$binary || !is_executable($binary)) {
        $this->markTestSkipped('RRDTOOL_TEST_BINARY is required');
    }
    $output = rrd_characterization_run($this, rrd_characterization_quoting_scenario());
    $graph = array_values(array_filter($output['results'][0]['sent'], function ($sent) {
        return strncmp($sent['stdin'], 'graph ', 6) === 0;
    }));
    expect($graph)->toHaveCount(1);

    $directory = sys_get_temp_dir() . '/rrd-graph-roundtrip-' . bin2hex(random_bytes(8));
    mkdir($directory . '/rra', 0700, true);
    try {
        // Paths in the command are relative to the directory RRDtool runs in.
        $create = "create 'rra/router'\"'\"'s traffic_11.rrd' --start 1699990000 --step 300 DS:traffic_in:GAUGE:600:U:U DS:traffic_out:GAUGE:600:U:U"
            . ' RRA:AVERAGE:0.5:1:100 RRA:MIN:0.5:1:100 RRA:MAX:0.5:1:100 RRA:LAST:0.5:1:100';
        // Fontconfig warns on stderr when it has no writable cache, as on CI
        // runners, so give it one inside the scratch directory.
        $environment = array('PATH' => getenv('PATH'), 'LANG' => 'C', 'LC_ALL' => 'C', 'HOME' => $directory, 'XDG_CACHE_HOME' => $directory . '/cache');
        $process = proc_open(array($binary, '-'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory, $environment);
        fwrite($pipes[0], $create . "\n" . $graph[0]['stdin']);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }

    expect($stderr)->toBe('');
    expect($stdout)->not->toContain('ERROR');
    // One OK for create and one for graph, which first prints the image size.
    expect(preg_match_all('/^OK u:/m', $stdout))->toBe(2);
    expect($stdout)->toMatch('/^\d+x\d+$/m');
});

/**
 * A graph scenario whose RRD paths are the ones in $paths, by local data id,
 * written as get_data_source_path() stores them.
 */
function rrd_characterization_proxy_graph_scenario(array $graph_data_array, array $items, array $paths): array
{
    $scenario = rrd_characterization_graph_scenario($graph_data_array, array(), array(), $items);
    foreach ($scenario['db'] as $index => $row) {
        if ($row['sql'] === 'SELECT name, data_source_path FROM data_template_data' && isset($paths[$row['params'][0]])) {
            $scenario['db'][$index]['result']['data_source_path'] = $paths[$row['params'][0]];
        }
    }

    return $scenario;
}

/** Reduce a proxy run to what its golden records: the commands the proxy received, split like the local ones. */
function rrd_characterization_proxy_observed(array $output): array
{
    $result = $output['results'][0];
    $received = array();
    foreach ($output['received'] as $command) {
        $received[] = rrd_characterization_segments(rrd_characterization_clock($command, $result['clock']));
    }

    return array('returned' => $result['returned'], 'printed' => $result['printed'], 'diagnostics' => $result['diagnostics'], 'received' => $received);
}

test('a graph through the RRDtool proxy sends DEF paths bare and relative, and legends quoted', function () {
    $in = rrd_characterization_ds('traffic_in');
    $items = array(
        rrd_characterization_item(1, 'AREA', $in + array('hex' => '00CF00', 'text_format' => 'Inbound "peak": a  b')),
        rrd_characterization_item(2, 'LINE1', rrd_characterization_ds('errors') + array('hex' => 'FF0000', 'text_format' => 'Errors')),
        rrd_characterization_item(3, 'GPRINT_LAST', $in + array('text_format' => 'Now:', 'gprint_text' => '%8.2lf %s')),
    );
    $scenario = rrd_characterization_proxy_graph_scenario(
        array('graph_start' => 1700000000, 'graph_end' => 1700003600),
        $items,
        array(11 => '<path_rra>/router_traffic_11.rrd', 12 => '<path_rra>/errors/router_errors_12.rrd')
    );
    $output = rrd_characterization_proxy_run($this, $scenario, 3);
    rrd_characterization_golden('graph-proxy', rrd_characterization_proxy_observed($output));
});

test('a DEF path the RRDtool proxy cannot carry refuses the graph before it is sent', function (array $paths, string $golden) {
    $area = array(rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in') + array('hex' => '3366CC', 'text_format' => 'Inbound')));
    $scenario = rrd_characterization_proxy_graph_scenario(array('graph_start' => 1700000000, 'graph_end' => 1700003600, 'get_error' => true), $area, $paths);
    $output = rrd_characterization_proxy_run($this, $scenario, 3);
    $observed = rrd_characterization_proxy_observed($output);
    expect(array_filter($observed['received'], function ($command) {
        return strncmp($command[0], 'graph', 5) === 0;
    }))->toBe(array());
    rrd_characterization_golden($golden, $observed);
})->with(array(
    // rrdproxy would end the path at the colon.
    'a colon' => array(array(11 => '<path_rra>/router:traffic_11.rrd'), 'graph-proxy-def-colon'),
    // rrdproxy refuses an absolute path, as for a realtime cache outside the RRA directory.
    'outside the RRA directory' => array(array(11 => '/var/cache/realtime/user_1_11.rrd'), 'graph-proxy-def-outside'),
    // The file check is refused first, so nothing reaches the proxy at all.
    'a blank' => array(array(11 => '<path_rra>/router traffic_11.rrd'), 'graph-proxy-def-blank'),
));
