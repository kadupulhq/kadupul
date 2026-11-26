<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs the shipped lib/rrd.php in the characterization child, so its coverage
 * counts for the maintenance messages: the row-count difference that
 * rrdtool_cacti_compare() reports before a resize, and the refusal of
 * rrd_xml_transform() when the RRDfile is not writable.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

test('the compare report names the stored and wanted row counts', function (): void {
    $profile = [['cf' => 1, 'xff' => '0.5', 'heartbeat' => 600, 'steps' => 1, 'rows' => 10010]];
    $result = rrd_characterization_run($this, [
        'coverage_contract' => 'rrd-observed-window',
        'options' => rrd_characterization_options(),
        'db' => [
            ['sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => ['name' => 'Rows', 'data_source_path' => '<path_rra>/sample.rrd']],
            ['sql' => 'local_data_template_data_id, rrd_step, data_source_profile_id', 'result' => ['local_data_template_data_id' => 0, 'rrd_step' => 300, 'data_source_profile_id' => 1]],
            ['sql' => 'SELECT data_template_id', 'result' => 0],
            ['sql' => 'SELECT DISTINCT dtr.id, dtr.data_source_name', 'result' => [['id' => 1, 'data_source_name' => 'value', 'data_source_type_id' => 1, 'rrd_heartbeat' => 600, 'rrd_minimum' => '0', 'rrd_maximum' => '100']]],
            ['sql' => 'dspc.consolidation_function_id AS cf', 'result' => $profile],
            ['sql' => 'SELECT host_id, snmp_query_id, snmp_index', 'result' => ['host_id' => 3, 'snmp_query_id' => 1, 'snmp_index' => '2']],
            ['sql' => 'field_name="ifHighSpeed"', 'result' => ''],
            ['sql' => 'field_name="ifSpeed"', 'result' => ''],
            ['sql' => 'SELECT heartbeat', 'result' => 600],
        ],
        'calls' => [['fn' => 'rrdtool_cacti_compare', 'args' => [11, [
            'filename' => 'sample.rrd',
            'step' => 300,
            'ds' => ['value' => ['type' => 'GAUGE', 'minimal_heartbeat' => 600, 'min' => '0', 'max' => '100']],
            'rra' => [['cf' => 'AVERAGE', 'pdp_per_row' => 1, 'rows' => 10000, 'xff' => '0.5']],
        ]]]],
    ])['results'][0];

    expect($result['diagnostics'])->toBe([])
        ->and($result['returned']['rra'][0]['rows'])->toBe("The number of rows for Kadupul RRA id '0' is incorrect.  The number of rows is '10000' but should be '10010'")
        ->and($result['returned']['resize'])->toBe(["'sample.rrd' 0 GROW 10"]);
});

test('compare quotes tune and shrink commands, business hours validates hex, and missing-file print_source escapes html', function (): void {
    $profile = [['cf' => 1, 'xff' => '0.5', 'heartbeat' => 600, 'steps' => 1, 'rows' => 9990]];
    $graph = [
        'id' => 7,
        'local_graph_id' => 7,
        'host_id' => 3,
        'snmp_query_id' => 0,
        'snmp_index' => '',
        'graph_template_id' => 0,
        'title_cache' => 'Missing <rrd>',
        'vertical_label' => 'bits',
        'height' => 120,
        'width' => 500,
        'image_format_id' => 1,
        'auto_scale' => 'on',
        'auto_scale_opts' => 2,
        'auto_scale_log' => '',
        'scale_log_units' => '',
        'auto_scale_rigid' => '',
        'auto_padding' => 'on',
        'upper_limit' => 100,
        'lower_limit' => 0,
        'base_value' => 1000,
        'unit_value' => '',
        'unit_exponent_value' => '',
        'unit_length' => '',
        'alt_y_grid' => '',
        'right_axis' => '',
        'right_axis_label' => '',
        'right_axis_format' => 0,
        'right_axis_formatter' => 0,
        'left_axis_formatter' => 0,
        'no_gridfit' => '',
        'tab_width' => 30,
        'legend_position' => 0,
        'legend_direction' => 0,
        'force_rules_legend' => '',
        'dynamic_labels' => '',
        'slope_mode' => '',
        'export' => '',
    ];
    $items = [[
        'graph_templates_item_id' => 1,
        'local_data_id' => 11,
        'task_item_id' => 11,
        'data_source_name' => 'value',
        'graph_type_id' => 4,
        'consolidation_function_id' => 1,
        'cdef_id' => 0,
        'vdef_id' => 0,
        'gprint_id' => 2,
        'text_format' => 'Line',
        'value' => '',
        'hard_return' => '',
        'color_id' => 1,
        'hex' => '3366CC',
        'alpha' => 'FF',
        'line_width' => '1.00',
        'dashes' => '',
        'dash_offset' => '',
        'shift' => '',
        'textalign' => '',
        'sequence' => 1,
    ]];
    $results = rrd_characterization_run($this, [
        'coverage_contract' => 'rrd-observed-window',
        'options' => [
            'business_hours_enable' => 'on',
            'business_hours_start' => '08:00',
            'business_hours_end' => '17:00',
            'business_hours_color' => '112233ff LINE1:inject',
            'business_hours_hideWeekends' => '',
            'boost_rrd_update_enable' => '',
        ] + rrd_characterization_options(),
        'db' => [
            ['sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => ['name' => 'Rows', 'data_source_path' => '<path_rra>/missing<x>.rrd']],
            ['sql' => 'local_data_template_data_id, rrd_step, data_source_profile_id', 'result' => ['local_data_template_data_id' => 0, 'rrd_step' => 300, 'data_source_profile_id' => 1]],
            ['sql' => 'SELECT data_template_id', 'result' => 0],
            ['sql' => 'SELECT DISTINCT dtr.id, dtr.data_source_name', 'result' => [['id' => 1, 'data_source_name' => 'value', 'data_source_type_id' => 1, 'rrd_heartbeat' => 900, 'rrd_minimum' => '5', 'rrd_maximum' => '100']]],
            ['sql' => 'dspc.consolidation_function_id AS cf', 'result' => $profile],
            ['sql' => 'SELECT host_id, snmp_query_id, snmp_index', 'result' => ['host_id' => 3, 'snmp_query_id' => 1, 'snmp_index' => '2']],
            ['sql' => 'field_name="ifHighSpeed"', 'result' => ''],
            ['sql' => 'field_name="ifSpeed"', 'result' => ''],
            ['sql' => 'SELECT heartbeat', 'result' => 900],
            ['sql' => 'FROM graph_templates_graph', 'result' => $graph],
            ['sql' => 'FROM graph_templates_item', 'result' => $items],
            ['sql' => 'FROM data_source_profiles', 'result' => [['step' => 300, 'steps' => 1, 'rows' => 1000, 'timespan' => 86400]]],
            ['sql' => 'SELECT h.*, s.name AS site_name FROM host', 'result' => ['id' => 3, 'hostname' => 'router1', 'description' => 'Router 1', 'snmp_community' => 'supersecret', 'site_name' => 'HQ']],
        ],
        'calls' => [
            ['fn' => 'rrdtool_cacti_compare', 'args' => [11, [
                'filename' => 'sample.rrd',
                'step' => 300,
                'ds' => ['value' => ['type' => 'COUNTER', 'minimal_heartbeat' => 600, 'min' => '0', 'max' => '100']],
                'rra' => [['cf' => 'AVERAGE', 'pdp_per_row' => 1, 'rows' => 10000, 'xff' => '0.5']],
            ]]],
            ['fn' => 'rrd_substitute_host_query_data', 'args' => ['|host_hostname| |host_snmp_community|', ['host_id' => 3, 'snmp_query_id' => 0, 'snmp_index' => ''], []]],
            ['fn' => 'rrdtool_function_graph', 'args' => [7, 0, ['print_source' => 1, 'graph_start' => 1700000000, 'graph_end' => 1700003600], '', [], 0]],
            ['fn' => 'add_business_hours', 'args' => [[
                'graph_start' => 1700179200, // Friday 2023-11-17 00:00:00 UTC
                'graph_end' => 1700438400,   // Monday 2023-11-20 00:00:00 UTC (spans Fri, Sat, Sun)
                'graph_defs' => '',
            ]]],
        ],
    ])['results'];

    expect($results[0]['returned']['tune'])->toBe([
        "'sample.rrd' --data-source-type 'value:GAUGE'",
        "'sample.rrd' --heartbeat 'value:900'",
        "'sample.rrd' --minimum 'value:5'",
    ])
        ->and($results[0]['returned']['resize'])->toBe(["'sample.rrd' 0 SHRINK 10"])
        ->and($results[1]['returned'])->toBe('router1 |host_snmp_community|')
        ->and($results[2]['returned'])->toContain('&lt;x&gt;')
        ->and($results[3]['returned']['graph_defs'])->toContain("'AREA:dslimit")
        ->and($results[3]['returned']['graph_defs'])->toContain('#ccccccff')
        ->and($results[3]['returned']['graph_defs'])->not->toContain('LINE1:inject');
});

test('rrdtool_tune escapes HTML output in web show_source mode', function (): void {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-tune-html-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $prelude = $coverage === null ? '' : 'define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
        file_put_contents($dir . '/tune_html.php', '<?php ' . $prelude
            . 'define("CACTI_CLI", false);'
            . '$config = array("cacti_server_os" => "unix", "rra_path" => ' . var_export($dir, true) . ', "is_web" => true);'
            . 'function read_config_option($name) { return $name === "path_rrdtool" ? "/usr/bin/rrdtool" : ""; }'
            . 'function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }'
            . 'function html_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8"); }'
            . 'function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }'
            . 'function html_header($cols) {} function html_end_box() {}'
            . 'require ' . var_export($root . '/lib/rrd.php', true) . ';'
            . 'rrdtool_tune("<b>err</b>.rrd", array("step" => "<script>alert(1)</script>", "tune" => array("\'<img src=x>\' --heartbeat \'v:600\'"), "resize" => array("\'<b>x</b>.rrd\' 0 GROW 10")), true);');
        $process = proc_open(
            [PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/tune_html.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error);
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
            unlink($reports[0]);
        }
        expect($output)
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->toContain('&lt;img src=x&gt;')
            ->toContain('&lt;b&gt;x&lt;/b&gt;.rrd')
            ->not->toContain('<script>')
            ->not->toContain('<img src=x>');
    } finally {
        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});

test('a transformation refuses a read-only RRDfile', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root can write a read-only file');
    }
    $result = rrd_characterization_run($this, [
        'coverage_contract' => 'rrd-observed-window',
        'line_mode' => true,
        'options' => rrd_characterization_options(),
        'files' => ['7.rrd'],
        'replies' => ['dump' => "<rrd><version>0003</version><step>300</step></rrd>\n"],
        'calls' => [
            ['fn' => 'chmod', 'args' => ['<fixture_rra>/7.rrd', 0400]],
            ['fn' => 'rrd_rra_delete', 'args' => [['<fixture_rra>/7.rrd'], [], false]],
        ],
    ])['results'][1];

    expect($result['diagnostics'])->toBe([])
        ->and($result['returned']['err_msg'] ?? null)->toMatch('~\AERROR: RRDfile \S*/7\.rrd not writable\z~');
});
