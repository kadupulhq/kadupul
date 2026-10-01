<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

const RRD_FONT_PARITY_ELEMENTS = array('title', 'axis', 'legend', 'unit', 'watermark');

/** settings_user rows for a viewer; an element left out reads the default. */
function rrd_font_parity_user_rows(array $user): array
{
    $rows = array();
    foreach (array_merge(array('custom_fonts'), ...array_map(fn($element) => array($element . '_font', $element . '_size'), array_merge(RRD_FONT_PARITY_ELEMENTS, array('default')))) as $name) {
        $rows[] = array('sql' => 'FROM settings_user WHERE name = ? AND user_id = ?', 'params' => array($name, 0),
            'result' => array_key_exists($name, $user) ? array('value' => $user[$name]) : array());
    }

    return $rows;
}

/** Site font settings: every element's font and size, so no call inherits the previous call's. */
function rrd_font_parity_site(array $fonts, array $sizes): array
{
    $site = array();
    foreach (RRD_FONT_PARITY_ELEMENTS as $index => $element) {
        $site[$element . '_font'] = $fonts[$index];
        $site[$element . '_size'] = $sizes[$index];
    }

    return $site;
}

/** Run each call against the current code and the frozen original, and return the pairs. */
function rrd_font_parity_pairs($test, array $user, string $version, array $calls): array
{
    $paired = array();
    foreach ($calls as $call) {
        $paired[] = $call;
        $paired[] = array('fn' => 'legacy_' . $call['fn']) + $call;
    }
    $results = rrd_characterization_run($test, array(
        'require' => array('tests/Fixtures/legacy-graph-fonts.php'),
        'options' => array('rrdtool_version' => $version) + rrd_characterization_options(),
        'tables' => array('settings_user'),
        'db' => rrd_font_parity_user_rows($user),
        'calls' => $paired,
    ))['results'];

    foreach ($results as $result) {
        expect($result['diagnostics'])->toBe(array());
    }

    return array_chunk(array_column($results, 'returned'), 2);
}

$sites = array(
    'blank' => rrd_font_parity_site(array('', '', '', '', ''), array('', '', '', '', '')),
    'names and sizes' => rrd_font_parity_site(
        array('DejaVu Sans Bold', 'DejaVu Sans', 'DejaVu Sans Mono', 'Sans', 'Serif Italic'),
        array('12', '7.5', '8', '9', '6')
    ),
    'size bounds' => rrd_font_parity_site(
        array('Noto Sans CJK JP', 'Ubuntu, Cantarell Bold 11', "Caf\u{e9} Sans", '0', 'Sans 7'),
        array('4', '4.01', '72', '72.5', '1e400')
    ),
    'sizes RRDtool cannot draw' => rrd_font_parity_site(
        array("Mono 'x'", 'Sans Bold', 'Sans', 'Sans', 'Sans'),
        array('abc', '-8', ' 10', '0x10', '')
    ),
);
$themes = array('classic', 'dark', 'midwinter', 'modern', 'paper-plane', 'paw', 'sunrise', 'no-such-theme');

test('graph font arguments are byte-identical to the original code', function (array $user, string $version, array $reached) use ($sites, $themes) {
    $calls = array();
    foreach ($sites as $site) {
        foreach (array('0', '1', '') as $method) {
            foreach ($themes as $theme) {
                foreach (array('', 'on') as $no_legend) {
                    $graph = array('graph_theme' => $theme) + ($no_legend === '' ? array() : array('graph_nolegend' => $no_legend));
                    $calls[] = array('fn' => 'rrdtool_function_theme_font_options', 'args' => array($graph), 'options' => array('font_method' => $method) + $site);
                }
            }
            // Plugins call these directly, with their own element names.
            $theme_fonts = array('title' => array('font' => 'Theme Sans', 'size' => '14'), 'axis' => array('font' => 'Theme Sans'), 'unit' => array('font' => 'Theme Sans', 'size' => '500'));
            foreach (array_merge(RRD_FONT_PARITY_ELEMENTS, array('default')) as $element) {
                $calls[] = array('fn' => 'rrdtool_function_set_font', 'args' => array($element, 'on', $theme_fonts), 'options' => array('font_method' => $method) + $site);
                $calls[] = array('fn' => 'rrdtool_set_font', 'args' => array($element), 'options' => array('font_method' => $method) + $site);
            }
        }
    }

    $pairs = rrd_font_parity_pairs($this, $user, $version, $calls);

    expect($pairs)->toHaveCount(count($calls));
    foreach ($pairs as $index => $pair) {
        expect($pair[0])->toBe($pair[1], json_encode($calls[$index]));
    }
    // The matrix has to reach every branch, not only the empty ones.
    $arguments = implode('', array_filter(array_column($pairs, 0), 'is_string'));
    foreach ($reached as $argument) {
        expect($arguments)->toContain($argument);
    }
})->with(array(
    'custom fonts off' => array(array('title_font' => 'Ignored Sans', 'title_size' => '30'), '1.7.2',
        array("--font TITLE:8.4:'DejaVu Sans Bold'", "--font WATERMARK:6:'Arial'", "--font UNIT:72:'0'", "--font AXIS:8:'Sans Bold'", "--font TITLE:12:'Mono '\"'\"'x'\"'\"''")),
    'custom fonts on' => array(array('custom_fonts' => 'on', 'title_font' => 'DejaVu Serif', 'title_size' => '13', 'axis_font' => 'Sans', 'axis_size' => '4.5',
        'legend_font' => 'DejaVu Sans Mono Bold', 'legend_size' => '72', 'unit_font' => '', 'unit_size' => '', 'watermark_font' => 'Sans', 'watermark_size' => '99'), '1.7.2',
        array("--font TITLE:9.1:'DejaVu Serif'", "--font LEGEND:72:'DejaVu Sans Mono Bold'", "--font UNIT:8: ", "--font WATERMARK:72:'Sans'")),
    'custom fonts on with nothing stored' => array(array('custom_fonts' => 'on'), '1.7.2', array("--font TITLE:12: ", "--font LEGEND:10: ")),
    'RRDtool without watermark fonts' => array(array(), '1.2.0', array("--font UNIT:8:'Arial'")),
));

test('the font size helpers keep their original answers', function () {
    $sizes = array('', 'abc', null, false, '-8', '0', '4', '4.01', ' 10', '10.0', '12', '72', '72.01', '1e9', '1e400', '-1e400', '0x10', 9, 7.5);
    $calls = array();
    foreach ($sizes as $size) {
        $calls[] = array('fn' => 'graph_font_size_filter', 'args' => array($size));
        foreach (array(8, 12, '10') as $default) {
            $calls[] = array('fn' => 'graph_font_size', 'args' => array($size, $default));
        }
    }

    $pairs = rrd_font_parity_pairs($this, array(), '1.7.2', $calls);

    expect($pairs)->toHaveCount(count($calls));
    foreach ($pairs as $index => $pair) {
        // graph_font_size() now always answers a float where it once gave back an int
        // default or 72; RRDtool is handed floatval() of it either way.
        if ($calls[$index]['fn'] === 'graph_font_size') {
            expect($pair[0])->toEqual($pair[1], json_encode($calls[$index]));
        } else {
            expect($pair[0])->toBe($pair[1], json_encode($calls[$index]));
        }
    }
});

// Pango and fontconfig have no use for these characters, so the original code
// passed them through to a font RRDtool could never find.
test('a font name fontconfig cannot hold is left to RRDtool', function ($font) {
    $site = rrd_font_parity_site(array($font, $font, $font, $font, $font), array('10', '10', '10', '10', '10'));
    $calls = array(
        array('fn' => 'rrdtool_function_set_font', 'args' => array('axis', '', array()), 'options' => array('font_method' => '0') + $site),
        array('fn' => 'rrdtool_function_set_font', 'args' => array('legend', '', array('legend' => array('font' => $font, 'size' => '9'))), 'options' => array('font_method' => '1') + $site),
    );

    $pairs = rrd_font_parity_pairs($this, array(), '1.7.2', $calls);

    expect(array_column($pairs, 0))->toBe(array("--font AXIS:10: \\\n", "--font LEGEND:9: \\\n"))
        ->and(array_column($pairs, 1))->not->toContain("--font AXIS:10: \\\n");
})->with(array(
    'double quotes' => array('Sans "Bold"'),
    'a font file path' => array('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'),
    'a Windows font file' => array('C:\\Windows\\Fonts\\Arial.ttf'),
    'a fontconfig pattern' => array('Sans:bold'),
    'a line break' => array("Sans\nBold"),
));
