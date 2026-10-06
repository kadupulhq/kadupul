<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

/** Script lines that define the font setting filters and the resolver they call. */
function rrd_font_settings_filters(string $root): string
{
    return 'require_once ' . var_export($root . '/lib/graph_fonts.php', true) . ';'
        . 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'graph_font_size_filter'), true) . ');'
        . 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'user_setting_value_allowed'), true) . ');';
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
        array('fn' => 'settings_value_passes_filter', 'args' => array('graph_dateformat', '', false)),
        array('fn' => 'settings_value_passes_filter', 'args' => array('default_date_format', 'anything', true)),
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
        function is_view_allowed($name) { return true; }
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
test('loaded System and profile font settings ask for Pango descriptions', function () {
    $result = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => array(
        array('fn' => 'rrd_characterization_setting_definitions', 'args' => array()),
    )))['results'][0];
    expect($result['diagnostics'])->toBe(array());
    $definitions = $result['returned'];
    $systemFonts = array();
    foreach ($definitions['system'] as $section) {
        foreach ($section as $name => $definition) {
            if (($definition['method'] ?? '') === 'font') {
                $systemFonts[$name] = $definition;
            }
        }
    }
    expect(array_keys($systemFonts))->toBe(array('path_rrdtool_default_font', 'title_font', 'legend_font', 'axis_font', 'unit_font'));
    foreach (array_merge(array_values($systemFonts), array_values(array_filter($definitions['user']['fonts'], static fn($setting) => ($setting['method'] ?? '') === 'font'))) as $definition) {
        expect($definition['placeholder'])->toBe('Enter a Pango font description')
            ->and($definition['description'])->toContain('Pango font description')
            ->and($definition['options']['options'])->toBe('graph_font_name_filter');
    }
    expect(array_keys(array_filter($definitions['user']['fonts'], static fn($setting) => ($setting['method'] ?? '') === 'font')))
        ->toBe(array('title_font', 'legend_font', 'axis_font', 'unit_font'));
});

test('loaded CSP settings advertise supported reporting and explain direct enforcement', function () {
    $result = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => array(
        array('fn' => 'rrd_characterization_setting_definitions', 'args' => array()),
    )))['results'][0];
    expect($result['diagnostics'])->toBe(array());
    $policy = null;
    foreach ($result['returned']['system'] as $section) {
        if (isset($section['content_security_policy_script'])) {
            $policy = $section['content_security_policy_script'];
        }
    }
    expect($policy)->not->toBeNull();
    expect(array_keys($policy['array']))->toBe(array(0, 'unsafe-eval', 'nonce-report'))
        ->and($policy['array']['nonce-report'])->toBe('Nonce Mode - Reporting Only')
        ->and($policy['description'])->toContain('without blocking them', 'enforcing nonce mode through direct configuration');
});

// The per-user labels reuse the System labels so existing translations still apply.
test('font setting labels keep their translations', function () {
    $root = dirname(__DIR__, 4);
    $result = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => array(
        array('fn' => 'rrd_characterization_setting_definitions', 'args' => array()),
    )))['results'][0];
    expect($result['diagnostics'])->toBe(array());
    $labels = array();
    foreach ($result['returned'] as $sections) {
        foreach ($sections as $fields) {
            foreach ($fields as $field) {
                if (str_contains($field['friendly_name'] ?? '', 'Font')) {
                    $labels[] = $field['friendly_name'];
                }
            }
        }
    }
    $po = file_get_contents($root . '/locales/po/de-DE.po');
    expect($po)->not->toBeFalse();
    expect($labels)->toHaveCount(19);
    foreach (array_unique($labels) as $label) {
        expect($po)->toContain('msgid "' . $label . '"');
    }
});

// Frozen from the complete real be32 settings include before the shared builder.
test('loaded graph font metadata preserves original field order values and types', function (string $context) {
    $root = dirname(__DIR__, 4);
    $json = file_get_contents($root . '/tests/Golden/graph-font-settings.json');
    if ($json === false) {
        throw new RuntimeException('Unable to read original font metadata');
    }
    $expected = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $result = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => array(
        array('fn' => 'rrd_characterization_setting_definitions', 'args' => array()),
    )))['results'][0];
    expect($result['diagnostics'])->toBe(array());
    if ($context === 'system') {
        $fields = $result['returned']['system']['visual'];
        $actual = array_intersect_key($fields, $expected['system']);
        $keys = array_keys($fields);
        $start = array_search('path_rrdtool_default_font', $keys, true);
        expect($start)->not->toBeFalse();
        expect(array_slice($keys, $start, 10))->toBe(array_merge(array_keys($expected['system']), array('business_hours_header')));
    } else {
        $actual = $result['returned']['user']['fonts'];
    }
    expect($actual)->toBe($expected[$context]);
})->with(array('System metadata' => 'system', 'profile metadata' => 'user'));

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
        function user_group_exists($id) { return $id === '3'; }
        function user_group_execute_child($id, $sql, $params) { return db_execute_prepared($sql, $params); }
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
test('saving all user settings preserves a stored font size when its replacement is refused', function ($submitted, $stored) {
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
        echo json_encode(array('writes' => $writes, 'errors' => array_keys($_SESSION['sess_error_fields'] ?? array())));
        PHP;

    $pipes = array();
    $process = proc_open(array(PHP_BINARY, '-r', $script, '--', $submitted), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $error)
        ->and(json_decode($output, true))->toBe(array(
            'writes' => $stored === null ? array(array('title_font', 'DejaVu Sans', 5)) : array(array('title_size', $stored, 5), array('title_font', 'DejaVu Sans', 5)),
            'errors' => $stored === null ? array('title_size') : array(),
        ));
})->with(array(
    'empty' => array('', null),
    'at the lower bound' => array('4', null),
    'just above the lower bound' => array('4.5', '4.5'),
    'at the upper bound' => array('72', '72'),
    'above the upper bound' => array('72.5', null),
    'infinite' => array('1e400', null),
));

/** A directory holding a stand-in fc-list that lists DejaVu Sans and DejaVu Sans Mono. */
function rrd_font_settings_fc_list(): string
{
    $directory = sys_get_temp_dir() . '/rrd-font-settings-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/fc-list', "#!/bin/sh\nprintf 'DejaVu Sans\\nDejaVu Sans Mono\\n'\n");
    chmod($directory . '/fc-list', 0700);

    return $directory;
}

/** Run $script with only $path to find programs on, and return what it printed as JSON. */
function rrd_font_settings_php(string $script, string $path, array $argv = array(), ?array $registration = null)
{
    $pipes = array();
    $command = array_merge(array(PHP_BINARY, '-r', $script, '--'), $argv);
    $coverageDirectory = null;
    if ($registration !== null) {
        $command = child_coverage_command($command, $coverageDirectory, $registration);
    }
    // Keep the original executable-discovery boundary. Only the parent's
    // explicitly configured runtime extension directory is inherited for PCOV.
    $environment = array('PATH' => $path);
    if ($coverageDirectory !== null && getenv('PHP_INI_SCAN_DIR') !== false) {
        $environment['PHP_INI_SCAN_DIR'] = getenv('PHP_INI_SCAN_DIR');
    }
    try {
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $environment);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if ($registration !== null) {
            child_coverage_collect($coverageDirectory);
        }

        return $result;
    } finally {
        if ($coverageDirectory !== null && is_dir($coverageDirectory)) {
            foreach (glob($coverageDirectory . '/*') as $file) {
                unlink($file);
            }
            rmdir($coverageDirectory);
        }
    }
}

/** Script lines that load the font name filter with a logger that records what it is given. */
function rrd_font_settings_name_filter(string $root): string
{
    return 'require ' . var_export($root . '/include/global_constants.php', true) . ';'
        . 'require ' . var_export($root . '/include/vendor/autoload.php', true) . ';'
        . rrd_font_settings_filters($root)
        . 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'graph_font_name_filter'), true) . ');'
        . '$logged = array(); function cacti_log($message) { $GLOBALS[\'logged\'][] = $message; }';
}

test('font names are checked against the fonts fontconfig lists', function () {
    $root = dirname(__DIR__, 4);
    $directory = rrd_font_settings_fc_list();
    $names = array('', 'DejaVu Sans', 'dejavu sans mono bold 9', 'Roboto, DejaVu Sans', 'monospace', 'Roboto Mono', "Roboto 'x'", '/usr/share/fonts/DejaVuSans.ttf', 'Sans:bold', "Sans\nBold", str_repeat('A', 256));
    $script = rrd_font_settings_name_filter($root) . '
        $answers = array();
        foreach (json_decode($argv[1], true) as $name) {
            $answers[] = graph_font_name_filter($name);
        }
        echo json_encode(array($answers, $logged));';

    try {
        $installed = rrd_font_settings_php($script, $directory, array(json_encode($names)));
        $unchecked = rrd_font_settings_php($script, $directory . '/none', array(json_encode($names)));
    } finally {
        unlink($directory . '/fc-list');
        rmdir($directory);
    }

    expect($installed)->toBe(array(array('', 'DejaVu Sans', 'dejavu sans mono bold 9', 'Roboto, DejaVu Sans', 'monospace', false, false, false, false, false, false), array()))
        // Without fc-list only the form of the name is checked, and each unchecked name is logged.
        ->and($unchecked[0])->toBe(array('', 'DejaVu Sans', 'dejavu sans mono bold 9', 'Roboto, DejaVu Sans', 'monospace', 'Roboto Mono', "Roboto 'x'", false, false, false, false))
        ->and($unchecked[1])->toHaveCount(6)
        ->and($unchecked[1][4])->toBe("NOTE: Graph font 'Roboto Mono' was saved without checking that it is installed, because fc-list is not available");
});

test('every loaded graph font setting checks its font name', function () {
    $result = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => array(
        array('fn' => 'rrd_characterization_setting_definitions', 'args' => array()),
    )))['results'][0];
    expect($result['diagnostics'])->toBe(array());
    $fields = array();
    foreach (array_merge(array_values($result['returned']['system']), array_values($result['returned']['user'])) as $section) {
        foreach ($section as $name => $field) {
            if (($field['method'] ?? '') === 'font') {
                $fields[] = array($name, $field);
            }
        }
    }
    expect(array_column($fields, 0))->toBe(array('path_rrdtool_default_font', 'title_font', 'legend_font', 'axis_font', 'unit_font', 'title_font', 'legend_font', 'axis_font', 'unit_font'));
    foreach ($fields as [$name, $field]) {
        expect($field['filter'])->toBe(FILTER_CALLBACK)
            ->and($field['options'])->toBe(array('options' => 'graph_font_name_filter'))
            ->and($field['max_length'])->toBe($name === 'path_rrdtool_default_font' ? '255' : '100')
            ->and(array_key_exists('default', $field))->toBeFalse()
            ->and(array_keys($field))->toBe(array('friendly_name', 'description', 'method', 'placeholder', 'max_length', 'filter', 'options'));
    }
});

/**
 * Post each request in $requests to settings.php over a site that stores
 * $stored, and return the writes, messages and error fields of each save.
 */
function rrd_font_settings_save(array $requests, array $stored): array
{
    $root = dirname(__DIR__, 4);
    $directory = rrd_font_settings_fc_list();
    mkdir($directory . '/site/include', 0700, true);
    mkdir($directory . '/site/lib', 0700);
    file_put_contents($directory . '/site/lib/poller.php', '<?php');
    // settings.php includes these relative to the working directory.
    file_put_contents($directory . '/site/include/auth.php', '<?php
        $config = array("poller_id" => 1);
        $local_db_cnn_id = false;
        $writes = array();
        $stored = json_decode($argv[2], true);
        $_SESSION = array();
        function set_default_action() {}
        function get_filter_request_var($name) { return $_REQUEST[$name]; }
        function get_request_var($name) { return $_REQUEST[$name] ?? ""; }
        function get_nfilter_request_var($name) { return $_REQUEST[$name] ?? ""; }
        function isset_request_var($name) { return isset($_REQUEST[$name]); }
        function db_qstr($value) { return "\'" . $value . "\'"; }
        function db_execute_prepared($sql, $params) { $GLOBALS["writes"][$params[0]] = $params[1]; }
        function db_execute($sql) {}
        function db_fetch_assoc($sql) { return array(); }
        function array_rekey($array) { return $array; }
        function cacti_sizeof($array) { return count($array); }
        function read_config_option($name, $force = false) { return $GLOBALS["stored"][$name] ?? ($name == "poller_interval" ? 300 : ""); }
        function snmpagent_global_settings_update() {}
        function api_plugin_hook_function($name) {}
        function kill_session_var($name) {}
        function raise_message($id) { $GLOBALS["messages"][] = $id; }
        function __($text) { return $text; }
        $settings = array("visual" => array(
            "font_method" => array("method" => "drop_array"),
            "path_rrdtool_default_font" => array("method" => "font", "filter" => FILTER_CALLBACK, "options" => array("options" => "graph_font_name_filter")),
            "title_font" => array("method" => "font", "filter" => FILTER_CALLBACK, "options" => array("options" => "graph_font_name_filter")),
            "title_size" => array("method" => "textbox", "filter" => FILTER_CALLBACK, "options" => array("options" => "graph_font_size_filter")),
        ));
        register_shutdown_function(function () {
            echo json_encode(array($GLOBALS["writes"], $GLOBALS["messages"], $_SESSION["sess_error_fields"] ?? array()), JSON_THROW_ON_ERROR);
            $GLOBALS["nativeChildCoverageMarkers"] = array("settings-controller-executed", "font-write-outcome-captured");
        });
        ');
    $script = rrd_font_settings_name_filter($root) . '
        $_REQUEST = json_decode($argv[1], true) + array("action" => "save", "tab" => "visual");
        chdir(' . var_export($directory . '/site', true) . ');
        require ' . var_export($root . '/settings.php', true) . ';';

    $saves = array();
    try {
        foreach ($requests as $request) {
            $registration = child_coverage_registration(
                __FILE__,
                'font-settings-native-save',
                array($request, $stored),
                array('settings-controller-executed', 'font-write-outcome-captured'),
                array('settings.php'),
                array(
                    'tests/Helpers/PhpSource.php', 'settings.php', 'lib/graph_fonts.php',
                    'src/Graphing/Domain/Font/GraphFontMethod.php', 'src/Graphing/Domain/Font/GraphFont.php',
                    'src/Graphing/Domain/Font/GraphFontProfile.php', 'src/Graphing/Domain/Font/GraphFontResolver.php',
                    'src/Graphing/Infrastructure/Fontconfig/InstalledFontFamilies.php',
                )
            );
            $registration['collectorPrelude'] = 'define("FONT_SETTINGS_NATIVE_TEST_COVERAGE", true);';
            $saves[] = rrd_font_settings_php($script, $directory, array(json_encode($request), json_encode($stored)), $registration);
        }
    } finally {
        unlink($directory . '/site/include/auth.php');
        unlink($directory . '/site/lib/poller.php');
        rmdir($directory . '/site/include');
        rmdir($directory . '/site/lib');
        rmdir($directory . '/site');
        unlink($directory . '/fc-list');
        rmdir($directory);
    }

    return $saves;
}

test('System settings refuse a font that is not installed and save one that is', function () {
    [$saved, $refused, $unchanged] = rrd_font_settings_save(array(
        array('font_method' => '0', 'path_rrdtool_default_font' => 'DejaVu Sans', 'title_font' => 'DejaVu Sans Mono Bold', 'title_size' => '10'),
        array('font_method' => '0', 'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf', 'title_font' => 'Roboto', 'title_size' => '10'),
        // The rows are visible in System mode, so a stale stored font is shown in red.
        array('font_method' => '0', 'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf', 'title_font' => 'DejaVu Sans', 'title_size' => '10'),
    ), array('font_method' => '0', 'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf', 'title_font' => 'DejaVu Sans'));

    expect($saved)->toBe(array(array('font_method' => '0', 'path_rrdtool_default_font' => 'DejaVu Sans', 'title_font' => 'DejaVu Sans Mono Bold', 'title_size' => '10'), array(1), array()))
        ->and($refused)->toBe(array(array('font_method' => '0', 'title_size' => '10'), array(35, 3), array('path_rrdtool_default_font' => 'path_rrdtool_default_font', 'title_font' => 'title_font')))
        ->and($unchanged)->toBe(array(array('font_method' => '0', 'title_font' => 'DejaVu Sans', 'title_size' => '10'), array(35, 3), array('path_rrdtool_default_font' => 'path_rrdtool_default_font')));
});

// Theme mode hides the font rows, but the browser still posts their stored values.
test('Theme settings save the hidden fonts they already store, installed or not', function () {
    $stored = array('font_method' => '1', 'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf', 'title_font' => 'Roboto');

    [$unchanged, $changed, $switched] = rrd_font_settings_save(array(
        array('font_method' => '1', 'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf', 'title_font' => 'Roboto', 'title_size' => '10'),
        array('font_method' => '1', 'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf', 'title_font' => 'Roboto Mono', 'title_size' => '10'),
        array('font_method' => '0', 'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf', 'title_font' => 'Roboto', 'title_size' => '10'),
    ), $stored);

    expect($unchanged)->toBe(array(array('font_method' => '1', 'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf', 'title_font' => 'Roboto', 'title_size' => '10'), array(1), array()))
        // A value that differs from the stored one did not come from the hidden row, so it is still checked.
        ->and($changed)->toBe(array(array('font_method' => '1', 'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf', 'title_size' => '10'), array(35, 3), array('title_font' => 'title_font')))
        // Switching to System shows the rows, so the stored fonts are checked.
        ->and($switched)->toBe(array(array('font_method' => '0', 'title_size' => '10'), array(35, 3), array('path_rrdtool_default_font' => 'path_rrdtool_default_font', 'title_font' => 'title_font')));
});

test('user and group graph settings keep a font that is not installed unsaved', function () {
    $root = dirname(__DIR__, 4);
    $directory = rrd_font_settings_fc_list();
    $settings_user = '$settings = array(); $settings_user = array("fonts" => array(
        "title_font" => array("method" => "font", "filter" => FILTER_CALLBACK, "options" => array("options" => "graph_font_name_filter")),
        "legend_font" => array("method" => "font", "filter" => FILTER_CALLBACK, "options" => array("options" => "graph_font_name_filter")),
    ));';
    $request = 'function isset_request_var($name) { return isset($_REQUEST[$name]); }
        function get_request_var($name) { return $_REQUEST[$name]; }
        function get_filter_request_var($name) { return $_REQUEST[$name]; }
        function get_nfilter_request_var($name, $default = "") { return $_REQUEST[$name] ?? $default; }
        $_REQUEST = array("save_component_graph_settings" => "1", "id" => "3", "title_font" => "Roboto", "legend_font" => "DejaVu Sans Mono");';
    $functions = file_get_contents($root . '/lib/functions.php');
    $group = rrd_font_settings_name_filter($root)
        . 'eval(' . var_export(test_php_function_source($functions, 'settings_value_passes_filter'), true) . ');'
        . 'eval(' . var_export(test_php_function_source(file_get_contents($root . '/user_group_admin.php'), 'form_save'), true) . ');'
        . $settings_user . $request . '
        $writes = array();
        $messages = array();
        function db_execute_prepared($sql, $params) { $GLOBALS["writes"][] = $params; }
        function kill_session_var($name) {}
        function user_group_exists($id) { return $id === "3"; }
        function user_group_execute_child($id, $sql, $params) { return db_execute_prepared($sql, $params); }
        function reset_group_perms($id) {}
        function raise_message($id) { $GLOBALS["messages"][] = $id; }
        register_shutdown_function(function () { echo json_encode(array($GLOBALS["writes"], $GLOBALS["messages"], $_SESSION["sess_error_fields"] ?? array())); });
        form_save();';
    $user = rrd_font_settings_name_filter($root)
        . 'eval(' . var_export(test_php_function_source($functions, 'settings_value_passes_filter'), true) . ');'
        . 'eval(' . var_export(test_php_function_source($functions, 'save_user_settings'), true) . ');'
        . $settings_user . $request . '
        $writes = array();
        function set_request_var($name, $value) { $_REQUEST[$name] = $value; }
        function set_user_setting($name, $value, $user) { $GLOBALS["writes"][] = array($user, $name, $value); }
        save_user_settings(5);
        echo json_encode($writes);';

    try {
        $group_writes = rrd_font_settings_php($group, $directory);
        $user_writes = rrd_font_settings_php($user, $directory);
    } finally {
        unlink($directory . '/fc-list');
        rmdir($directory);
    }

    // The group page reports the refusal as System settings do, not as a successful save.
    expect($group_writes)->toBe(array(array(array('3', 'legend_font', 'DejaVu Sans Mono')), array(35, 3), array('title_font' => 'title_font')))
        ->and($user_writes)->toBe(array(array(5, 'legend_font', 'DejaVu Sans Mono')));
});
