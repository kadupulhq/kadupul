<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

/** Run $calls in System font mode and return what each call returned. */
function rrd_font_arguments_run($test, array $calls, array $scenario = array()): array
{
    $scenario += array(
        'options' => array('font_method' => '0', 'title_font' => 'Sans', 'legend_font' => 'Mono') + rrd_characterization_options(),
        'calls' => $calls,
    );

    return array_map(function ($result) {
        expect($result['diagnostics'])->toBe(array());

        return $result['returned'];
    }, rrd_characterization_run($test, $scenario)['results']);
}

test('font sizes RRDtool cannot draw fall back or are capped', function () {
    $sizes = array('', 'abc', '-5', '2', '4', '4.5', '12', ' 10', '72', '72.5', '99999', '1e9', '1e400', '-1e400');
    $calls = array();
    foreach ($sizes as $size) {
        $calls[] = array('fn' => 'rrdtool_function_set_font', 'args' => array('title', '', array()), 'options' => array('title_size' => $size), 'catch' => true);
        // Thumbnails scale the title, which threw a TypeError for a non-numeric size.
        $calls[] = array('fn' => 'rrdtool_function_set_font', 'args' => array('title', 'on', array()), 'options' => array('title_size' => $size), 'catch' => true);
        $calls[] = array('fn' => 'rrdtool_function_set_font', 'args' => array('legend', '', array()), 'options' => array('legend_size' => $size), 'catch' => true);
    }
    $theme = array('axis' => array('font' => 'Theme Sans', 'size' => '1e400'), 'unit' => array('font' => 'Theme Sans', 'size' => '500'));
    $calls[] = array('fn' => 'rrdtool_function_set_font', 'args' => array('axis', '', $theme), 'options' => array('font_method' => '1'), 'catch' => true);
    $calls[] = array('fn' => 'rrdtool_function_set_font', 'args' => array('unit', '', $theme), 'options' => array('font_method' => '1'), 'catch' => true);

    $returned = rrd_font_arguments_run($this, $calls);

    foreach ($returned as $argument) {
        expect($argument)->toBeString()->toMatch('/^--font [A-Z]+:[0-9]+(\.[0-9]+)?:/');
        preg_match('/^--font [A-Z]+:([0-9.]+):/', $argument, $size);
        expect((float) $size[1])->toBeGreaterThan(3)->toBeLessThanOrEqual(72);
    }
    // '' for the title, the thumbnail title and the legend.
    expect(array_slice($returned, 0, 3))->toBe(array("--font TITLE:12:'Sans' \\\n", "--font TITLE:8.4:'Sans' \\\n", "--font LEGEND:8:'Mono' \\\n"));
    rrd_characterization_golden('font-arguments', $returned);
});

test('a user with custom fonts and a cleared title size still gets thumbnails', function () {
    $user = function (string $name, string $value): array {
        return array('sql' => 'FROM settings_user WHERE name = ? AND user_id = ?', 'params' => array($name, 0), 'result' => array('value' => $value));
    };
    $scenario = array(
        'tables' => array('settings_user'),
        'db' => array($user('custom_fonts', 'on'), $user('title_font', 'User Sans'), $user('title_size', '')),
    );

    $returned = rrd_font_arguments_run($this, array(array('fn' => 'rrdtool_function_set_font', 'args' => array('title', 'on', array()), 'catch' => true)), $scenario);

    expect($returned)->toBe(array("--font TITLE:8.4:'User Sans' \\\n"));
});

test('graph_font_size keeps sizes RRDtool can draw and replaces the rest', function () {
    $sizes = array('', 'abc', '4', '4.01', '8', '12.5', '72', '72.01', '1e400', '-1e400', '-8', '0x10', null, array());
    $calls = array_map(function ($size) {
        return array('fn' => 'graph_font_size', 'args' => array($size, 9));
    }, $sizes);

    // Results come back through JSON, which drops the .0 of a whole float.
    expect(rrd_font_arguments_run($this, $calls))->toBe(array(9, 9, 9, 4.01, 8, 12.5, 72, 72, 9, 9, 9, 9, 9, 9));
});

test('graph_font_size hands back the default it was given unchanged', function () {
    $calls = array(
        array('fn' => 'graph_font_size', 'args' => array('', '10')),
        array('fn' => 'graph_font_size', 'args' => array('abc', 8.5)),
        array('fn' => 'graph_font_size', 'args' => array(null, 72)),
        array('fn' => 'graph_font_size', 'args' => array('4', '72')),
    );

    expect(rrd_font_arguments_run($this, $calls))->toBe(array('10', 8.5, 72, '72'));
});

test('a local RRDtool is given the Default Font only when it names a font', function ($font, $environment) {
    $calls = array(
        array('fn' => 'putenv', 'args' => array('RRD_DEFAULT_FONT')),
        // A boolean command with no pipe starts its own RRDtool process through __rrd_init().
        array('fn' => 'rrdtool_execute', 'args' => array(array('info', 'rra/router_traffic_21.rrd'), false, 4)),
        array('fn' => 'getenv', 'args' => array('RRD_DEFAULT_FONT')),
        array('fn' => 'rrdtool_default_font', 'args' => array()),
    );

    $returned = rrd_font_arguments_run($this, $calls, array('line_mode' => true, 'options' => array('path_rrdtool_default_font' => $font) + rrd_characterization_options()));

    expect($returned[2])->toBe($environment)
        ->and($returned[3])->toBe($environment === false ? '' : $environment);
})->with(array(
    'a family' => array('DejaVu Sans', 'DejaVu Sans'),
    'a description with a style and size' => array('DejaVu Sans Bold 9', 'DejaVu Sans Bold 9'),
    'none' => array('', false),
    'a font file path' => array('/usr/share/fonts/DejaVuSans.ttf', false),
    'a line break' => array("Sans\nBold", false),
));
