<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Graphing\Infrastructure\Rrd;

if (!defined('RRD_NL')) {
    define(__NAMESPACE__ . '\\RRD_NL', " \\\n");
}
if (!defined('CHECKED')) {
    define(__NAMESPACE__ . '\\CHECKED', 'on');
}

function read_config_option($key)
{
    return $GLOBALS['rrd_graph_options_config'][$key] ?? '';
}

function get_rrdtool_version()
{
    return $GLOBALS['rrd_graph_options_version'] ?? '1.8.0';
}

function rrdtool_pipe_quote($value)
{
    return '"' . $value . '"';
}

function rrdtool_pipe_quote_substituted($value, $graph)
{
    return '"' . $value . '"';
}

function rrd_substituted_text_placeholder(&$quoted_text, $option, $value, $graph)
{
    $quoted_text[$option] = $value;

    return '__GRAPH_TEXT__';
}

function cacti_version_compare($version, $compare, $operator)
{
    return version_compare($version, $compare, $operator);
}

function db_fetch_cell_prepared($sql, $params)
{
    return 'Format %s';
}

function rrdtool_function_format_graph_date($graph_data_array, $now)
{
    return '';
}

function rrdtool_function_theme_font_options($graph_data_array)
{
    return '';
}

require_once dirname(__DIR__, 4) . '/src/Graphing/Infrastructure/Rrd/GraphOptionsGenerator.php';

\test('graph options generator covers scale, display, axis and export branches', function () {
    $includePath = sys_get_temp_dir() . '/rrd-graph-options-' . bin2hex(random_bytes(8));
    mkdir($includePath, 0700);
    file_put_contents($includePath . '/global_arrays.php', '<?php $image_types = array(1 => "PNG", 2 => "SVG");');
    $GLOBALS['config'] = array('include_path' => $includePath);
    $GLOBALS['rrd_graph_options_config'] = array('rrdtool_watermark' => 'on', 'graph_watermark' => 'Kadupul');
    $generator = new GraphOptionsGenerator();

    try {
        $graph = array(
            'auto_scale' => 'on', 'auto_scale_opts' => '3', 'upper_limit' => '100', 'lower_limit' => '',
            'auto_scale_log' => 'on', 'scale_log_units' => 'on', 'auto_scale_rigid' => 'on',
            'unit_value' => '1:5', 'unit_exponent_value' => '3', 'height' => '400', 'width' => '600',
            'graph_nolegend' => 'on', 'image_format_id' => 2, 'title_cache' => 'Traffic', 'alt_y_grid' => 'on',
            'base_value' => '1024', 'vertical_label' => 'bits', 'slope_mode' => 'on', 'right_axis' => '2:0',
            'right_axis_label' => 'out', 'right_axis_format' => '7', 'no_gridfit' => 'on', 'unit_length' => '10',
            'tab_width' => '20', 'dynamic_labels' => 'on', 'force_rules_legend' => 'on', 'legend_position' => 'south',
            'legend_direction' => 'bottomup', 'left_axis_formatter' => 'numeric', 'right_axis_formatter' => 'timestamp',
        );
        $options = array('graph_start' => 1700000000, 'graph_end' => 1700003600, 'graph_height' => '150', 'graph_width' => '300', 'graph_nolegend' => true, 'image_format' => 'png');
        $rendered = $generator->build(1700000000, 1700003600, $graph, $options);

        expect($rendered)->toContain('--alt-autoscale-min')
            ->and($rendered)->toContain('--upper-limit="100"')
            ->and($rendered)->toContain('--disable-rrdtool-tag')
            ->and($rendered)->toContain('--imgformat=PNG')
            ->and($rendered)->toContain('--height=150')
            ->and($rendered)->toContain('--width=300')
            ->and($rendered)->toContain('--no-legend')
            ->and($rendered)->toContain('--right-axis-format')
            ->and($rendered)->toContain('--force-rules-legend')
            ->and($rendered)->toContain('--right-axis-formatter');

        $graph['auto_scale_opts'] = '4';
        $graph['lower_limit'] = '10';
        $rendered = $generator->build(1700000000, 1700003600, $graph, array());
        expect($rendered)->toContain('--alt-autoscale')
            ->and($rendered)->toContain('--upper-limit="100"')
            ->and($rendered)->toContain('--lower-limit="10"');

        $graph['auto_scale_opts'] = '1';
        $rendered = $generator->build(1700000000, 1700003600, $graph, array('export' => true, 'export_filename' => 'traffic.svg'));
        expect($rendered)->toContain('traffic.svg')
            ->and($rendered)->toContain('--alt-autoscale');

        $GLOBALS['rrd_graph_options_config']['rrdtool_watermark'] = '';
        $GLOBALS['rrd_graph_options_config']['graph_watermark'] = '';
        $GLOBALS['rrd_graph_options_version'] = '1.3.0';
        $graph['auto_scale'] = '';
        $graph['upper_limit'] = '100';
        $graph['lower_limit'] = '10';
        $graph['unit_exponent_value'] = '-3';
        $rendered = $generator->build(1700000000, 1700003600, $graph, array('graph_width' => 'abc'));
        expect($rendered)->toContain('--upper-limit="100"')
            ->and($rendered)->toContain('--lower-limit="10"')
            ->and($rendered)->not->toContain('--legend-position')
            ->and($rendered)->not->toContain('--disable-rrdtool-tag');
    } finally {
        unlink($includePath . '/global_arrays.php');
        rmdir($includePath);
        unset($GLOBALS['config'], $GLOBALS['rrd_graph_options_config'], $GLOBALS['rrd_graph_options_version']);
    }
});
