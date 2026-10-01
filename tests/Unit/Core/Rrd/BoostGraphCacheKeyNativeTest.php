<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

function boost_cache_key_run(array $scenario, $coverage)
{
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/boost-cache-key-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/boost-cache-key-native.php',
                $root, $directory, base64_encode(serialize($scenario)), $coverage !== null ? '1' : '0'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (array_merge(glob($directory . '/cache/*'), glob($directory . '/*')) as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($directory);
    }
}

$custom = array('custom_fonts' => 'on', 'legend_font' => 'DejaVuSans', 'legend_size' => '8');
$browserZone = array('config' => array('client_timezone_support' => 'on'), 'user' => array('client_timezone_support' => 'on'));
$shift = array('day_shift_start' => '07:00', 'day_shift_end' => '19:00');

test('a viewer whose rendered graph differs never receives another viewer\'s cached graph', function ($writer, $reader) {
    $observed = boost_cache_key_run(array('writer' => $writer, 'reader' => $reader), $this->getTestResultObject()->getCodeCoverage());
    expect($observed['served'])->toBeFalse()
        ->and($observed['written'])->toHaveCount(1)
        ->and($observed['files']['writer'])->not->toBe($observed['files']['reader']);
})->with(array(
    'custom fonts against defaults' => array(array('user' => array('custom_fonts' => 'on', 'legend_size' => '5000')), array()),
    'legend size' => array(array('user' => array_merge($custom, array('legend_size' => '5000'))), array('user' => $custom)),
    'title font' => array(array('user' => array_merge($custom, array('title_font' => 'Serif'))), array('user' => $custom)),
    'font name that is not UTF-8' => array(array('user' => array_merge($custom, array('legend_font' => "Sans\xff"))), array('user' => $custom)),
    'watermark size' => array(array('user' => array_merge($custom, array('watermark_size' => '20'))), array('user' => $custom)),
    'site default font' => array(array('config' => array('axis_font' => 'Serif')), array()),
    'colour mode' => array(array('color_mode' => 'dark'), array('color_mode' => 'light')),
    'date format' => array(array('user' => array('default_date_format' => '1')), array('user' => array('default_date_format' => '3'))),
    'date separator' => array(array('user' => array('default_datechar' => '0')), array('user' => array('default_datechar' => '1'))),
    'user date format against site default' => array(array('user' => array('default_date_format' => '3')), array()),
    'requested graph theme' => array(array('graph' => array('graph_theme' => 'dark')), array('graph' => array('graph_theme' => 'classic'))),
    'function-scoped requested theme' => array(array('graph' => array('graph_theme' => 'dark'), 'function_scope' => true), array('graph' => array())),
    'function-scoped size' => array(array('graph' => array('graph_height' => 300, 'graph_width' => 900), 'function_scope' => true), array('graph' => array())),
    'client time zone' => array(array('php_tz' => 'America/New_York', 'tz' => 'GMT+5'), array('php_tz' => 'Asia/Kolkata', 'tz' => 'IST')),
    'client time zone against server zone' => array(array('php_tz' => 'Asia/Tokyo', 'tz' => 'GMT-9'), array()),
    'PHP zone alone' => array(array('php_tz' => 'Europe/Berlin'), array('php_tz' => 'Europe/London')),
    'RRDtool TZ alone' => array(array('tz' => 'GMT-2'), array('tz' => 'GMT+3')),
    'locale' => array(array('lang' => 'de_DE.UTF-8'), array('lang' => 'fr_FR.UTF-8')),
    'locale against unset' => array(array('lang' => 'ja_JP.UTF-8'), array()),
    'viewer language under a fixed LANG' => array(array('lang' => 'C', 'locale' => 'de-DE', 'country' => 'de'),
        array('lang' => 'C', 'locale' => 'en-US', 'country' => 'us')),
    'viewer locale alone under a fixed LANG' => array(array('lang' => 'C', 'locale' => 'fr-CA', 'country' => 'ca'),
        array('lang' => 'C', 'locale' => 'en-CA', 'country' => 'ca')),
    'viewer country under a fixed LANG' => array(array('lang' => 'C', 'locale' => 'en-GB', 'country' => 'gb'),
        array('lang' => 'C', 'locale' => 'en-GB', 'country' => 'us')),
    'image format' => array(array('graph' => array('image_format' => 'png')), array('graph' => array('image_format' => 'svg+xml'))),
    'graphv against graph output' => array(array('graph' => array('graphv' => true)), array('graph' => array())),
    'browser zone set by cacti_time_zone_set()' => array(array_merge($browserZone, array('cookie_offset' => 300)),
        array_merge($browserZone, array('cookie_offset' => -540))),
    'first weekday on This Week' => array(array('preset' => 'GT_THIS_WEEK', 'user' => array('first_weekdayid' => '0')),
        array('preset' => 'GT_THIS_WEEK', 'user' => array('first_weekdayid' => '1'))),
    'first weekday on Previous Week' => array(array('preset' => 'GT_PREV_WEEK', 'user' => array('first_weekdayid' => '1')),
        array('preset' => 'GT_PREV_WEEK', 'user' => array('first_weekdayid' => '6'))),
    'day shift start' => array(array('preset' => 'GT_DAY_SHIFT', 'user' => $shift),
        array('preset' => 'GT_DAY_SHIFT', 'user' => array_merge($shift, array('day_shift_start' => '08:00')))),
    'day shift end' => array(array('preset' => 'GT_DAY_SHIFT', 'user' => $shift),
        array('preset' => 'GT_DAY_SHIFT', 'user' => array_merge($shift, array('day_shift_end' => '17:30')))),
    'graph start one day apart' => array(array('graph' => array('graph_height' => 150, 'graph_width' => 600, 'graph_start' => 1790380800, 'graph_end' => 1790510400)),
        array('graph' => array('graph_height' => 150, 'graph_width' => 600, 'graph_start' => 1790467200, 'graph_end' => 1790510400))),
));

test('viewers that render the same graph share one cache file', function ($writer, $reader) {
    $observed = boost_cache_key_run(array('writer' => $writer, 'reader' => $reader), $this->getTestResultObject()->getCodeCoverage());
    expect($observed['served'])->toBe('PNG rendered for the writer')
        ->and($observed['files']['writer'])->toBe($observed['files']['reader'])
        ->and($observed['written'])->toBe(array(basename($observed['files']['writer'])));
})->with(array(
    'default fonts' => array(array(), array()),
    'identical custom fonts' => array(array('user' => $custom), array('user' => $custom)),
    'stored fonts with custom fonts off' => array(array('user' => array('legend_size' => '5000')), array()),
    'theme font method ignores user fonts' => array(array('config' => array('font_method' => 1), 'user' => array_merge($custom, array('legend_size' => '5000'))),
        array('config' => array('font_method' => 1))),
    'unknown colour mode' => array(array('color_mode' => 'sepia'), array()),
    'user date format equal to site default' => array(array('user' => array('default_date_format' => '1', 'default_datechar' => '1')), array()),
    'site date format changed for both' => array(array('config' => array('default_date_format' => 4)), array('config' => array('default_date_format' => 4))),
    'function-scoped caller' => array(array('graph' => array('graph_theme' => 'dark', 'graph_height' => 150, 'graph_width' => 600), 'function_scope' => true),
        array('graph' => array('graph_theme' => 'dark', 'graph_height' => 150, 'graph_width' => 600))),
    'same client time zone' => array(array('php_tz' => 'America/New_York', 'tz' => 'GMT+5'), array('php_tz' => 'America/New_York', 'tz' => 'GMT+5')),
    'same locale' => array(array('lang' => 'de_DE.UTF-8'), array('lang' => 'de_DE.UTF-8')),
    'same viewer language under a fixed LANG' => array(array('lang' => 'C', 'locale' => 'fr-FR', 'country' => 'fr'),
        array('lang' => 'C', 'locale' => 'fr-FR', 'country' => 'fr')),
    'same image format and graphv' => array(array('graph' => array('image_format' => 'png', 'graphv' => true)),
        array('graph' => array('image_format' => 'png', 'graphv' => true))),
    'same browser zone set by cacti_time_zone_set()' => array(array_merge($browserZone, array('cookie_offset' => 300)),
        array_merge($browserZone, array('cookie_offset' => 300))),
    'browser zone ignored with client time zones off' => array(array('user' => array('client_timezone_support' => 'on'), 'cookie_offset' => 300),
        array('user' => array('client_timezone_support' => 'on'), 'cookie_offset' => -540)),
    'same first weekday on This Week' => array(array('preset' => 'GT_THIS_WEEK', 'user' => array('first_weekdayid' => '1')),
        array('preset' => 'GT_THIS_WEEK', 'user' => array('first_weekdayid' => '1'))),
    'first weekday on a preset that ignores it' => array(array('preset' => 'GT_LAST_DAY', 'user' => array('first_weekdayid' => '0')),
        array('preset' => 'GT_LAST_DAY', 'user' => array('first_weekdayid' => '1'))),
    'same day shift' => array(array('preset' => 'GT_DAY_SHIFT', 'user' => $shift), array('preset' => 'GT_DAY_SHIFT', 'user' => $shift)),
));

test('the cache file name keeps its layout and appends a fixed-length render key', function () {
    $full = boost_cache_key_run(array('writer' => array(), 'reader' => array('graph' => array('graph_nolegend' => true))), $this->getTestResultObject()->getCodeCoverage());
    expect(basename($full['files']['writer']))->toMatch('/^modern_lgi_7_rrai_1_height_150_width_600_rk_[0-9a-f]{64}\.png$/')
        ->and(basename($full['files']['reader']))->toMatch('/^modern_lgi_7_rrai_1_rk_[0-9a-f]{64}_thumb\.png$/');
});

test('an expired cache file is not served even when the render key matches', function () {
    $observed = boost_cache_key_run(array('writer' => array(), 'reader' => array(), 'age' => 301), $this->getTestResultObject()->getCodeCoverage());
    expect($observed['served'])->toBeFalse()->and($observed['written'])->toHaveCount(1);
});


/**
 * Two page loads of graph 7 through the real rrdtool_function_graph() with the
 * PNG cache on, so the cache check, the window defaults and the cache write run
 * in the order a request runs them.
 */
function boost_cache_render_twice($test, array $first, array $second): array
{
    $items = array(rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in') + array('hex' => '00CF00', 'text_format' => 'Inbound')));
    $calls = array();
    foreach (array($first, $second) as $request) {
        $calls[] = array('fn' => 'rrdtool_function_graph', 'args' => array(7, 0, $request['graph'], false, array(), 0), 'options' => $request['options'] ?? array());
    }

    return rrd_characterization_run($test, array(
        'files' => array('router_traffic_11.rrd'),
        // The child runs in its temporary directory, so the cache lands in its rra folder.
        'options' => array('boost_png_cache_enable' => 'on', 'boost_png_cache_directory' => 'rra') + rrd_characterization_options(),
        'db' => rrd_characterization_graph_db(rrd_characterization_graph(), $items),
        'replies' => array('graph' => 'PNG rendered by RRDtool'),
        'writes' => array('UPDATE snmpagent_cache', 'UPDATE `snmpagent_cache`'),
        'calls' => $calls,
    ))['results'];
}

test('a repeated render is served from the cache file the first render wrote', function ($graph) {
    list($first, $second) = boost_cache_render_twice($this, array('graph' => $graph), array('graph' => $graph));

    expect($first['sent'])->not->toBe(array())
        ->and($second['sent'])->toBe(array())
        ->and($second['returned'])->toBe($first['returned'])
        ->and(array_merge($first['diagnostics'], $second['diagnostics']))->toBe(array());
})->with(array(
    // graph_image.php?local_graph_id=N&rra_id=R: the render fills in the window after the cache check.
    'no window' => array(array()),
    'relative window' => array(array('graph_start' => -3600)),
    'explicit window' => array(array('graph_start' => 1699913600, 'graph_end' => 1700000000)),
));

test('a repeated render for a viewer who sees a different graph renders again', function ($first, $second) {
    list(, $repeat) = boost_cache_render_twice($this, $first, $second);

    expect($repeat['sent'])->not->toBe(array());
})->with(array(
    'requested theme' => array(array('graph' => array('graph_theme' => 'modern')), array('graph' => array('graph_theme' => 'dark'))),
    'site font size' => array(array('graph' => array(), 'options' => array('font_method' => '0')), array('graph' => array(), 'options' => array('legend_size' => '10'))),
    'window' => array(array('graph' => array('graph_start' => -86400)), array('graph' => array('graph_start' => -3600))),
));
