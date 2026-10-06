<?php

// SPDX-FileCopyrightText: 2004-2026 The Cacti Group
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// lib/rrd.php font functions and the lib/functions.php size helpers they call,
// as of 4a0e446ed before GraphFontResolver, renamed with a legacy_ prefix.
// RrdGraphFontResolverParityTest compares them with the current functions.
// Do not edit them to match new behavior.

function legacy_rrdtool_function_theme_font_options(&$graph_data_array)
{
    global $config;

    /* implement theme colors */
    $graph_opts = '';
    $themefonts = array();
    $themecolors = 'rrdcolors';
    $themeborder = 'rrdborder';


    if (isset($graph_data_array['graph_theme'])) {
        $theme = cacti_validate_theme($graph_data_array['graph_theme']);
    } else {
        $theme = get_selected_theme();
    }

    $rrdtheme = $config['base_path'] . '/include/themes/' . $theme . '/rrdtheme.php';

    if (file_exists($rrdtheme) && is_readable($rrdtheme)) {
        $rrdversion = get_rrdtool_version();
        include($rrdtheme);

        if (isset($_COOKIE['CactiColorMode']) && in_array($_COOKIE['CactiColorMode'], array('dark', 'light', 'dark-dimmed'))) {
            $themecolors = 'rrdcolors_' . $_COOKIE['CactiColorMode'];
            $themeborder = 'rrdborder_' . $_COOKIE['CactiColorMode'];
            if (!isset($$themecolors) || !is_array($$themecolors)) {
                $themecolors = 'rrdcolors';
                $themeborder = 'rrdborder';
            }
        }

        if (isset($$themecolors) && is_array($$themecolors)) {
            foreach ($$themecolors as $colortag => $color) {
                $graph_opts .= '--color ' . strtoupper($colortag) . '#' . strtoupper($color) . RRD_NL;
            }
        }

        if (isset($$themeborder) && cacti_version_compare($rrdversion, '1.4', '>=')) {
            $graph_opts .= "--border " . $$themeborder . RRD_NL;
        }

        if (isset($rrdfonts)) {
            $themefonts = $rrdfonts;
        }
    }

    /* title fonts */
    $graph_opts .= legacy_rrdtool_function_set_font('title', ((!empty($graph_data_array['graph_nolegend'])) ? $graph_data_array['graph_nolegend'] : ''), $themefonts);

    /* axis fonts */
    $graph_opts .= legacy_rrdtool_function_set_font('axis', '', $themefonts);

    /* legend fonts */
    $graph_opts .= legacy_rrdtool_function_set_font('legend', '', $themefonts);

    /* unit fonts */
    $graph_opts .= legacy_rrdtool_function_set_font('unit', '', $themefonts);

    /* watermark fonts */
    if (isset($rrdversion) && cacti_version_compare($rrdversion, '1.3', '>')) {
        $graph_opts .= legacy_rrdtool_function_set_font('watermark', '', $themefonts);
    }

    return $graph_opts;
}

function legacy_rrdtool_set_font($type, $no_legend = '', $themefonts = array())
{
    return legacy_rrdtool_function_set_font($type, $no_legend, $themefonts);
}

function legacy_rrdtool_function_set_font($type, $no_legend, $themefonts)
{
    global $config;

    if (read_config_option('font_method') == 0) {
        if (read_user_setting('custom_fonts') == 'on') {
            $font = read_user_setting($type . '_font');
            $size = read_user_setting($type . '_size');
        } else {
            $font = read_config_option($type . '_font');
            $size = read_config_option($type . '_size');
        }
    } elseif (isset($themefonts[$type]['font']) && isset($themefonts[$type]['size'])) {
        $font = $themefonts[$type]['font'];
        $size = $themefonts[$type]['size'];
    } else {
        return;
    }

    if ($font != '') {
        /* verifying all possible pango font params is too complex to be tested here
         * so we only escape the font
         */
        $font = rrdtool_pipe_quote($font);
    }

    if ($type == 'title') {
        $size = legacy_graph_font_size($size, 12);

        if (!empty($no_legend)) {
            $size = $size * .70;
        }
    } else {
        $size = legacy_graph_font_size($size, 8);
    }

    return '--font ' . strtoupper($type) . ':' . floatval($size) . ':' . $font . RRD_NL;
}

function legacy_graph_font_size_filter($size)
{
    if (!is_numeric($size)) {
        return false;
    }

    $points = (float) $size;

    if (!is_finite($points) || $points <= 4 || $points > 72) {
        return false;
    }

    return $size;
}

function legacy_graph_font_size($size, $default)
{
    if (is_numeric($size) && is_finite((float) $size) && (float) $size > 72) {
        return 72;
    }

    if (legacy_graph_font_size_filter($size) === false) {
        return $default;
    }

    return (float) $size;
}
