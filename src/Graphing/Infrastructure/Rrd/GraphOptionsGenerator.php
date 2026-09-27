<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Rrd;

/** Build legacy RRDtool graph options without changing their wire representation. */
final class GraphOptionsGenerator
{
    /** Generate the options portion of a graph command.
     *
     * @param int|string $graph_start Start bound passed to RRDtool.
     * @param int|string $graph_end End bound passed to RRDtool.
     * @param array<string, mixed> $graph Graph variables, updated during processing.
     * @param array<string, mixed> $graph_data_array Graph options and metadata.
     * @param \DateTimeImmutable|null $now Shared time snapshot.
     *
     * @return string RRDtool graph options.
     */
    public function build($graph_start, $graph_end, &$graph, &$graph_data_array, ?\DateTimeImmutable $now = null): string
    {
        global $config, $image_types;

        include($config['include_path'] . '/global_arrays.php');

        /* define some variables */
        $scale               = '';
        $rigid               = '';
        $unit_value          = '';
        $version             = get_rrdtool_version();
        $unit_exponent_value = '';

        if ($graph['auto_scale'] == 'on') {
            switch ($graph['auto_scale_opts']) {
                case '1': /* autoscale ignores lower, upper limit */
                    $scale = '--alt-autoscale' . RRD_NL;
                    break;
                case '2': /* autoscale-max, accepts a given lower limit */
                    $scale = '--alt-autoscale-max' . RRD_NL;
                    if (is_numeric($graph['lower_limit'])) {
                        $scale .= '--lower-limit=' . rrdtool_pipe_quote($graph['lower_limit']) . RRD_NL;
                    }
                    break;
                case '3': /* autoscale-min, accepts a given upper limit */
                    $scale = '--alt-autoscale-min' . RRD_NL;
                    if (is_numeric($graph['upper_limit'])) {
                        $scale .= '--upper-limit=' . rrdtool_pipe_quote($graph['upper_limit']) . RRD_NL;
                    }
                    break;
                case '4': /* auto_scale with limits */
                    $scale = '--alt-autoscale' . RRD_NL;
                    if (is_numeric($graph['upper_limit'])) {
                        $scale .= '--upper-limit=' . rrdtool_pipe_quote($graph['upper_limit']) . RRD_NL;
                    }
                    if (is_numeric($graph['lower_limit'])) {
                        $scale .= '--lower-limit=' . rrdtool_pipe_quote($graph['lower_limit']) . RRD_NL;
                    }
                    break;
            }
        } else {
            if ($graph['upper_limit'] != '') {
                $scale =  '--upper-limit=' . rrdtool_pipe_quote_substituted($graph['upper_limit'], $graph) . RRD_NL;
            }
            if ($graph['lower_limit'] != '') {
                $scale .= '--lower-limit=' . rrdtool_pipe_quote_substituted($graph['lower_limit'], $graph) . RRD_NL;
            }
        }

        if ($graph['auto_scale_log'] == 'on') {
            $scale .= '--logarithmic' . RRD_NL;
        }

        /* --units=si only defined for logarithmic y-axis scaling, even if it doesn't hurt on linear graphs */
        if ($graph['scale_log_units'] == 'on' && $graph['auto_scale_log'] == 'on') {
            $scale .= '--units=si' . RRD_NL;
        }

        if ($graph['auto_scale_rigid'] == 'on') {
            $rigid = '--rigid' . RRD_NL;
        }

        if ($graph['unit_value'] != '') {
            $unit_value = '--y-grid=' . rrdtool_pipe_quote_substituted($graph['unit_value'], $graph) . RRD_NL;
        }

        if (preg_match('/^[0-9]+$/', $graph['unit_exponent_value'])) {
            $unit_exponent_value = '--units-exponent=' . rrdtool_pipe_quote($graph['unit_exponent_value']) . RRD_NL;
        }

        /*
         * optionally you can specify and array that overrides some of the db's values, lets set
         * that all up here
         */

        /* override: graph height (in pixels) */
        if (isset($graph_data_array['graph_height'])) {
            $graph_height = $graph_data_array['graph_height'];
        } else {
            $graph_height = $graph['height'];
        }

        /* override: graph width (in pixels) */
        if (isset($graph_data_array['graph_width'])) {
            $graph_width = $graph_data_array['graph_width'];
        } else {
            $graph_width = $graph['width'];
        }

        /* override: skip drawing the legend? */
        if (isset($graph_data_array['graph_nolegend'])) {
            $graph_legend = '--no-legend' . RRD_NL;
        } else {
            $graph_legend = '';
        }

        /* export options */
        if (isset($graph_data_array['export'])) {
            $graph_opts = $graph_data_array['export_filename'] . RRD_NL;
        } else {
            if (empty($graph_data_array['output_filename'])) {
                $graph_opts = '-' . RRD_NL;
            } else {
                $graph_opts = $graph_data_array['output_filename'] . RRD_NL;
            }
        }

        if (isset($graph_data_array['image_format']) && $graph_data_array['image_format'] == 'png') {
            $graph['image_format_id'] = 1;
        }

        /* basic graph options */
        $graph_opts .=
            '--imgformat=' . $image_types[$graph['image_format_id']] . RRD_NL .
            '--start=' . rrdtool_pipe_quote($graph_start) . RRD_NL .
            '--end=' . rrdtool_pipe_quote($graph_end) . RRD_NL;

        $graph_opts .= '--pango-markup ' . RRD_NL;

        if (read_config_option('rrdtool_watermark') == 'on') {
            $graph_opts .= '--disable-rrdtool-tag ' . RRD_NL;
        }

        $quoted_text = array();
        foreach ($graph as $key => $value) {
            switch ($key) {
                case 'title_cache':
                    if (!empty($value)) {
                        $graph_opts .= '--title=' . rrd_substituted_text_placeholder($quoted_text, '--title', $value, $graph) . RRD_NL;
                    }
                    break;
                case 'alt_y_grid':
                    if ($value == CHECKED) {
                        $graph_opts .= '--alt-y-grid' . RRD_NL;
                    }
                    break;
                case 'height':
                    if (isset($graph_data_array['graph_height']) && preg_match('/^[0-9]+$/', $graph_data_array['graph_height'])) {
                        $graph_opts .= '--height=' . $graph_data_array['graph_height'] . RRD_NL;
                    } else {
                        $graph_opts .= '--height=' . $value . RRD_NL;
                    }
                    break;
                case 'width':
                    if (isset($graph_data_array['graph_width']) && preg_match('/^[0-9]+$/', $graph_data_array['graph_width'])) {
                        $graph_opts .= '--width=' . $graph_data_array['graph_width'] . RRD_NL;
                    } else {
                        $graph_opts .= '--width=' . $value . RRD_NL;
                    }
                    break;
                case 'graph_nolegend':
                    if (isset($graph_data_array['graph_nolegend'])) {
                        $graph_opts .= '--no-legend' . RRD_NL;
                    } else {
                        $graph_opts .= '';
                    }
                    break;
                case 'base_value':
                    if ($value == 1000 || $value == 1024) {
                        $graph_opts .= '--base=' . $value . RRD_NL;
                    }
                    break;
                case 'vertical_label':
                    if (!empty($value)) {
                        $graph_opts .= '--vertical-label=' . rrd_substituted_text_placeholder($quoted_text, '--vertical-label', $value, $graph) . RRD_NL;
                    }
                    break;
                case 'slope_mode':
                    if ($value == CHECKED) {
                        $graph_opts .= '--slope-mode' . RRD_NL;
                    }
                    break;
                case 'right_axis':
                    if (!empty($value)) {
                        $graph_opts .= '--right-axis ' . rrdtool_pipe_quote_substituted($value, $graph) . RRD_NL;
                    }
                    break;
                case 'right_axis_label':
                    if (!empty($value)) {
                        $graph_opts .= '--right-axis-label ' . rrdtool_pipe_quote_substituted($value, $graph) . RRD_NL;
                    }
                    break;
                case 'right_axis_format':
                    if (!empty($value)) {
                        $format = db_fetch_cell_prepared('SELECT gprint_text from graph_templates_gprint WHERE id = ?', array($value));
                        $graph_opts .= '--right-axis-format ' . rrdtool_pipe_quote_substituted(trim(str_replace('%s', '', $format)), $graph) . RRD_NL;
                    }
                    break;
                case 'no_gridfit':
                    if ($value == CHECKED) {
                        $graph_opts .= '--no-gridfit' . RRD_NL;
                    }
                    break;
                case 'unit_length':
                    if (!empty($value)) {
                        $graph_opts .= '--units-length ' . rrdtool_pipe_quote_substituted($value, $graph) . RRD_NL;
                    }
                    break;
                case 'tab_width':
                    if (!empty($value)) {
                        $graph_opts .= '--tabwidth ' . rrdtool_pipe_quote_substituted($value, $graph) . RRD_NL;
                    }
                    break;
                case 'dynamic_labels':
                    if ($value == CHECKED) {
                        $graph_opts .= '--dynamic-labels' . RRD_NL;
                    }
                    break;
                case 'force_rules_legend':
                    if ($value == CHECKED) {
                        $graph_opts .= '--force-rules-legend' . RRD_NL;
                    }
                    break;
                case 'legend_position':
                case 'legend_direction':
                case 'left_axis_formatter':
                case 'right_axis_formatter':
                    // Each option's RRDtool flag is its column name with dashes.
                    if (cacti_version_compare($version, '1.4', '>=')) {
                        if (!empty($value)) {
                            $graph_opts .= '--' . str_replace('_', '-', $key) . ' ' . rrdtool_pipe_quote_substituted($value, $graph) . RRD_NL;
                        }
                    }
                    break;
            }
        }

        $graph_opts .= "$rigid" . trim("$scale$unit_value$unit_exponent_value$graph_legend", "\n\r " . RRD_NL) . RRD_NL;

        /* add a date to the graph legend */
        $graph_opts .= rrdtool_function_format_graph_date($graph_data_array, $now);

        /* process theme and font styling options */
        $graph_opts .= rrdtool_function_theme_font_options($graph_data_array);

        $graph_opts = strtr($graph_opts, $quoted_text);

        /* if the user desires a watermark set it */
        $watermark = str_replace("'", '"', read_config_option('graph_watermark'));
        if ($watermark != '') {
            $graph_opts .= '--watermark ' . rrdtool_pipe_quote($watermark) . RRD_NL;
        }

        return $graph_opts;

    }
}
