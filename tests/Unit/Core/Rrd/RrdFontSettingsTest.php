<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/** Script lines that define the font setting filters and the resolver they call. */
function rrd_font_settings_filters(string $root): string
{
    return 'require_once ' . var_export($root . '/lib/graph_fonts.php', true) . ';'
        . 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'graph_font_size_filter'), true) . ');';
}

test('every graph font size setting refuses sizes RRDtool cannot draw', function () {
    $names = array('title_size', 'legend_size', 'axis_size', 'unit_size');
    $values = array('', 'abc', '4', '4.5', '12', '72', '72.5', '1e400', '-8');
    $calls = array();
    foreach (array(false, true) as $user_setting) {
        foreach ($names as $name) {
            foreach ($values as $value) {
                $calls[] = array('fn' => 'settings_value_passes_filter', 'args' => array($name, $value, $user_setting));
            }
        }
    }

    $results = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => $calls))['results'];

    $expected = array(false, false, false, true, true, true, false, false, false);
    foreach (array_chunk(array_column($results, 'returned'), count($values)) as $returned) {
        expect($returned)->toBe($expected);
    }
    expect(array_merge(...array_column($results, 'diagnostics')))->toBe(array());
});

test('settings without a filter accept any value', function () {
    $calls = array(
        array('fn' => 'settings_value_passes_filter', 'args' => array('title_font', '', false)),
        array('fn' => 'settings_value_passes_filter', 'args' => array('title_font', 'DejaVu Sans Bold', true)),
        array('fn' => 'settings_value_passes_filter', 'args' => array('no_such_setting', 'anything', true)),
    );

    $results = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => $calls))['results'];

    expect(array_column($results, 'returned'))->toBe(array(true, true, true));
});

test('the profile page leaves a font size it refuses unsaved', function () {
    $root = dirname(__DIR__, 4);
    $script = 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'settings_value_passes_filter'), true) . ');'
        . rrd_font_settings_filters($root)
        . 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/auth_profile.php'), 'api_auth_update_user_setting'), true) . ');'
        . <<<'PHP'
        $writes = array();
        function db_execute_prepared($sql, $params) { $GLOBALS['writes'][] = $params; }
        function kill_session_var($name) {}
        $settings = array();
        $settings_user = array('fonts' => array('title_size' => array('method' => 'textbox', 'default' => '12', 'filter' => FILTER_CALLBACK, 'options' => array('options' => 'graph_font_size_filter'))));
        $_SESSION['sess_user_id'] = 5;
        foreach (array('', '1', '1e400', '9') as $value) {
            api_auth_update_user_setting('title_size', $value);
        }
        echo json_encode($writes);
        PHP;

    $pipes = array();
    $process = proc_open(array(PHP_BINARY, '-r', $script), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $error)
        ->and(json_decode($output, true))->toBe(array(array('title_size', '9', 5)));
});

// RRDtool 1.3 and later hand the value to Pango, which ignores a file path and
// silently draws its fallback font.
test('font settings ask for a Pango font description, not a font file', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/include/global_settings.php');

    expect($source)->not->toMatch('/True Type Font file|Font File|The font file|Pangon/')
        ->and(substr_count($source, 'Enter a Pango font description'))->toBe(4);
});

// The per-user labels reuse the System labels so existing translations still apply.
test('font setting labels keep their translations', function () {
    $root = dirname(__DIR__, 4);
    preg_match_all("/'friendly_name' => __\('([^']*Font[^']*)'\)/", file_get_contents($root . '/include/global_settings.php'), $labels);
    $po = file_get_contents($root . '/locales/po/de-DE.po');

    expect($labels[1])->toHaveCount(19);
    foreach (array_unique($labels[1]) as $label) {
        expect($po)->toContain('msgid "' . $label . '"');
    }
});

test('group graph settings store the default for a font size they refuse', function ($submitted, $stored) {
    $root = dirname(__DIR__, 4);
    $script = 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'settings_value_passes_filter'), true) . ');'
        . rrd_font_settings_filters($root)
        . 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/user_group_admin.php'), 'form_save'), true) . ');'
        . <<<'PHP'
        $writes = array();
        function isset_request_var($name) { return isset($_REQUEST[$name]); }
        function get_request_var($name) { return $_REQUEST[$name]; }
        function get_filter_request_var($name) { return $_REQUEST[$name]; }
        function get_nfilter_request_var($name, $default = '') { return $_REQUEST[$name] ?? $default; }
        function db_execute_prepared($sql, $params) { $GLOBALS['writes'][] = $params; }
        function kill_session_var($name) {}
        function reset_group_perms($id) {}
        function raise_message($id) {}
        $settings = array();
        $settings_user = array('fonts' => array(
            'title_size' => array('method' => 'textbox', 'default' => '12', 'filter' => FILTER_CALLBACK, 'options' => array('options' => 'graph_font_size_filter')),
            'title_font' => array('method' => 'font'),
        ));
        $_REQUEST = array('save_component_graph_settings' => '1', 'id' => '3', 'title_size' => $argv[1], 'title_font' => 'DejaVu Sans');
        register_shutdown_function(function () { echo json_encode($GLOBALS['writes']); });
        form_save();
        PHP;

    $pipes = array();
    $process = proc_open(array(PHP_BINARY, '-r', $script, '--', $submitted), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $error)
        ->and(json_decode($output, true))->toBe(array(array('3', 'title_size', $stored), array('3', 'title_font', 'DejaVu Sans')));
})->with(array(
    'empty' => array('', '12'),
    'at the lower bound' => array('4', '12'),
    'just above the lower bound' => array('4.5', '4.5'),
    'at the upper bound' => array('72', '72'),
    'above the upper bound' => array('72.5', '12'),
    'infinite' => array('1e400', '12'),
));

// user_admin.php and the profile Save All path both store graph settings through save_user_settings().
test('saving all user settings stores the default for a font size they refuse', function ($submitted, $stored) {
    $root = dirname(__DIR__, 4);
    $script = 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'settings_value_passes_filter'), true) . ');'
        . rrd_font_settings_filters($root)
        . 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'save_user_settings'), true) . ');'
        . <<<'PHP'
        $writes = array();
        function isset_request_var($name) { return isset($_REQUEST[$name]); }
        function get_nfilter_request_var($name, $default = '') { return $_REQUEST[$name] ?? $default; }
        function set_request_var($name, $value) { $_REQUEST[$name] = $value; }
        function set_user_setting($name, $value, $user) { $GLOBALS['writes'][] = array($name, $value, $user); }
        $settings = array();
        $settings_user = array('fonts' => array(
            'title_size' => array('method' => 'textbox', 'default' => '12', 'filter' => FILTER_CALLBACK, 'options' => array('options' => 'graph_font_size_filter')),
            'title_font' => array('method' => 'font'),
        ));
        $_REQUEST = array('title_size' => $argv[1], 'title_font' => 'DejaVu Sans');
        save_user_settings(5);
        echo json_encode($writes);
        PHP;

    $pipes = array();
    $process = proc_open(array(PHP_BINARY, '-r', $script, '--', $submitted), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $error)
        ->and(json_decode($output, true))->toBe(array(array('title_size', $stored, 5), array('title_font', 'DejaVu Sans', 5)));
})->with(array(
    'empty' => array('', '12'),
    'at the lower bound' => array('4', '12'),
    'just above the lower bound' => array('4.5', '4.5'),
    'at the upper bound' => array('72', '72'),
    'above the upper bound' => array('72.5', '12'),
    'infinite' => array('1e400', '12'),
));
