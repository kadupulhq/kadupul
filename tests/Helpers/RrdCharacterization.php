<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Shared by the lib/rrd.php characterization tests. The goldens record what
// the code does today, including output that looks wrong, so moving it into
// src/Graphing/ can prove nothing changed. Rewrite a golden only for an
// intended behavior change: run the tests with RRD_GOLDEN_UPDATE=1 and review
// the diff.

/**
 * Run $calls in a child process that loads the real lib/rrd.php against fixture
 * rows. With $fatal the child must die instead, and the result is its exit
 * status and raw output, since it never reaches the per-call report.
 */
function rrd_characterization_run($test, array $scenario, bool $fatal = false): array
{
    $root = dirname(__DIR__, 2);
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    $directory = sys_get_temp_dir() . '/rrd-characterization-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($directory . '/rra', 0700);
    foreach ($scenario['files'] ?? array() as $file) {
        touch($directory . '/rra/' . $file);
    }
    file_put_contents($directory . '/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
    // Stands in for RRDtool: records each invocation exactly as PHP made it and
    // answers the commands whose output the caller parses.
    $rrdtool = '#!' . PHP_BINARY . "\n<?php\n" . <<<'FAKE'
        $scenario = json_decode(file_get_contents(__DIR__ . '/scenario.json'), true);
        // Line mode answers each line as `rrdtool -` does, so an acknowledged
        // pipe gets its reply. RRDtool refuses file_exists and is_dir, which
        // only rrdproxy knows, and runs mkdir itself without creating parents;
        // RrdForcedLocalTransportTest checks both against the real binary.
        if (!empty($scenario['line_mode'])) {
            while (($line = fgets(STDIN)) !== false) {
                $line = trim($line, " \r\n");
                $command = strtok($line, ' ');
                if ($command === 'quit') {
                    break;
                }
                file_put_contents(__DIR__ . '/stdin.log', json_encode(array('argv' => array_slice($argv, 1), 'stdin' => $line), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                if ($command === 'file_exists' || $command === 'is_dir') {
                    echo "ERROR: unknown function '" . $command . "'\n";
                    continue;
                }
                if ($command === 'mkdir') {
                    $path = str_replace("'\"'\"'", "'", substr($line, 7, -1));
                    echo @mkdir($path) ? "OK u:0.00 s:0.00 r:0.00\n" : 'ERROR: mkdir ' . $path . ": No such file or directory\n";
                    continue;
                }
                echo ($scenario['replies'][$command] ?? '') . "OK u:0.00 s:0.00 r:0.00\n";
            }
            exit(0);
        }
        $input = stream_get_contents(STDIN);
        file_put_contents(__DIR__ . '/stdin.log', json_encode(array('argv' => array_slice($argv, 1), 'stdin' => $input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        $command = strtok(ltrim($input), " \r\n");
        if ($command === 'info') {
            foreach ($scenario['rrd_cfs'] ?? array('AVERAGE') as $index => $cf) {
                echo 'rra[' . $index . '].cf = "' . $cf . "\"\n";
            }
        } elseif (isset($scenario['replies'][$command])) {
            echo $scenario['replies'][$command];
        }
        if ($input !== '') {
            echo "OK u:0.00 s:0.00 r:0.00\n";
        }
        FAKE;
    file_put_contents($directory . '/rrdtool', $rrdtool);
    chmod($directory . '/rrdtool', 0700);
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/rrd-characterization.php', $root, $directory, $coverage === null ? '0' : '1'),
            // A file, not a pipe, so a noisy child cannot block on a full stderr.
            array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $directory . '/stderr', 'w')),
            $pipes,
            // Relative RRD paths keep wrapped print_source output independent of the temp directory.
            $directory
        );
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $error = file_get_contents($directory . '/stderr');
        if ($fatal) {
            PHPUnit\Framework\Assert::assertSame(255, $status, $error . $output);
        } else {
            PHPUnit\Framework\Assert::assertSame(0, $status, $error . $output);
            PHPUnit\Framework\Assert::assertSame('', $error);
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }

        if ($fatal) {
            return array('status' => $status, 'stdout' => str_replace($directory, '<DIR>', $output), 'stderr' => str_replace(array($directory, $root), array('<DIR>', '<ROOT>'), $error));
        }

        return json_decode(str_replace($directory, '<DIR>', $output), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (array_merge(glob($directory . '/rra/*/*'), glob($directory . '/rra/*'), glob($directory . '/*')) as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($directory);
    }
}

/**
 * As rrd_characterization_run(), with storage_location on and the fake proxy
 * in place of rrdproxy. The one call gets a session from rrd_init() as
 * argument $rrdp_argument, and 'received' lists every command the proxy
 * decrypted, in order.
 */
function rrd_characterization_proxy_run($test, array $scenario, int $rrdp_argument): array
{
    require_once __DIR__ . '/RrdFakeProxy.php';
    $client = rrd_proxy_interop_key();
    $proxy = rrd_proxy_interop_key();
    $directory = sys_get_temp_dir() . '/rrd-characterization-proxy-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $server = null;
    try {
        list($server, $stdout, $port) = rrd_fake_proxy_start($directory, array(
            'proxy_private_key' => $proxy['private'], 'proxy_public_key' => $proxy['public'],
            'client_fingerprint' => $client['fingerprint'],
        ));
        $scenario['options'] = array(
            'storage_location' => '1', 'rrdp_server' => '127.0.0.1', 'rrdp_port' => $port,
            'rsa_public_key' => $client['public'], 'rsa_private_key' => $client['private'], 'rrdp_fingerprint' => $proxy['fingerprint'],
        ) + ($scenario['options'] ?? array());
        expect($scenario['calls'])->toHaveCount(1);
        $scenario['calls'][0]['rrdp_argument'] = $rrdp_argument;
        $output = rrd_characterization_run($test, $scenario);
        $output['received'] = rrd_fake_proxy_finish($directory, $server, $stdout);

        return $output;
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
        }
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

/**
 * Split an RRDtool command where a folded continuation line starts a new
 * option or graph element. Joining the parts with two spaces restores it
 * byte for byte, so the golden stays exact and still diffs line by line.
 */
function rrd_characterization_segments(string $command): array
{
    return preg_split('/  (?=--|[A-Z]+[0-9.]*:)/', $command);
}

/**
 * Magic CDEF variables embed time() minus the RRA step. Business hours also
 * emit TIME,<n>,GT, but with absolute times far in the past, so only values
 * within a day of the call are rewritten. When the call spans a second
 * boundary the step is the one candidate that is a whole number of minutes.
 */
function rrd_characterization_clock(string $text, array $clock): string
{
    return preg_replace_callback('/TIME,(\d{9,}),GT/', function ($match) use ($clock) {
        $ages = array_filter(range($clock[0] - $match[1], $clock[1] - $match[1]), function ($age) use ($clock) {
            return $age >= 0 && $age <= 86400 && ($clock[0] === $clock[1] || $age % 60 === 0);
        });

        return count($ages) === 1 ? 'TIME,<now-' . reset($ages) . '>,GT' : $match[0];
    }, $text);
}

/** Reduce a child result to what a golden records. */
function rrd_characterization_observed(array $result): array
{
    $commands = array();
    foreach ($result['sent'] as $sent) {
        $stdin = rrd_characterization_clock($sent['stdin'], $result['clock']);
        expect(implode('  ', rrd_characterization_segments($stdin)))->toBe($stdin);
        $commands[] = array('argv' => $sent['argv'], 'stdin' => rrd_characterization_segments($stdin));
    }
    $printed = rrd_characterization_clock($result['printed'], $result['clock']);

    // Arguments are recorded after the call because several functions report
    // through by-reference parameters.
    return array('returned' => $result['returned'], 'printed' => $printed, 'diagnostics' => $result['diagnostics'], 'commands' => $commands, 'args' => $result['args']);
}

function rrd_characterization_golden(string $name, $actual): void
{
    $path = dirname(__DIR__) . '/Fixtures/rrd-characterization/' . $name . '.json';
    $encoded = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (getenv('RRD_GOLDEN_UPDATE') === '1') {
        file_put_contents($path, $encoded);
    }
    PHPUnit\Framework\Assert::assertFileExists($path);
    PHPUnit\Framework\Assert::assertSame(file_get_contents($path), $encoded, 'Golden ' . $name);
}

function rrd_characterization_graph(array $overrides = array()): array
{
    return array_replace(array(
        'local_graph_id' => '7', 'host_id' => '3', 'snmp_query_id' => '1', 'snmp_index' => '2',
        'title_cache' => 'Traffic & <errors> "quoted" \'single\' 100% a:b caf' . "\u{e9}" . ' |host_hostname| |query_ifAlias|',
        'vertical_label' => 'bits/s & <b> "q" 5% x:y ' . "\u{65e5}\u{672c}",
        'slope_mode' => 'on', 'auto_scale' => 'on', 'auto_scale_opts' => '4', 'auto_scale_log' => '',
        'scale_log_units' => '', 'auto_scale_rigid' => 'on', 'auto_padding' => 'on', 'base_value' => '1000',
        'upper_limit' => '100', 'lower_limit' => '0', 'height' => '200', 'width' => '700', 'image_format_id' => '1',
        'unit_value' => '', 'unit_exponent_value' => '', 'alt_y_grid' => '',
        'right_axis' => '', 'right_axis_label' => '', 'right_axis_format' => '0', 'no_gridfit' => '',
        'unit_length' => '', 'tab_width' => '40', 'dynamic_labels' => '', 'force_rules_legend' => '',
        'legend_position' => '', 'legend_direction' => '', 'right_axis_formatter' => '', 'left_axis_formatter' => '',
    ), $overrides);
}

function rrd_characterization_item(int $id, string $type, array $overrides = array()): array
{
    // Values of the GRAPH_ITEM_TYPE_* constants in include/global_constants.php.
    $types = array(
        'COMMENT' => 1, 'HRULE' => 2, 'VRULE' => 3, 'LINE1' => 4, 'LINE2' => 5, 'LINE3' => 6, 'AREA' => 7,
        'STACK' => 8, 'GPRINT' => 9, 'LEGEND' => 10, 'GPRINT_LAST' => 11, 'GPRINT_MAX' => 12, 'GPRINT_MIN' => 13,
        'GPRINT_AVERAGE' => 14, 'LEGEND_CAMM' => 15, 'LINESTACK' => 20, 'TIC' => 30, 'TEXTALIGN' => 40,
    );

    return array_replace(array(
        'graph_templates_item_id' => (string) $id, 'cdef_id' => '0', 'vdef_id' => '0', 'text_format' => '',
        'value' => '', 'hard_return' => '', 'consolidation_function_id' => '1', 'graph_type_id' => (string) $types[$type],
        'gprint_text' => null, 'hex' => null, 'alpha' => 'FF', 'line_width' => '1.00', 'dashes' => '',
        'shift' => '', 'dash_offset' => '', 'textalign' => '', 'snmp_query_id' => null, 'snmp_index' => null,
        'data_template_rrd_id' => null, 'local_data_id' => null, 'rrd_minimum' => null, 'rrd_maximum' => null,
        'data_source_name' => null, 'local_data_template_rrd_id' => null,
    ), $overrides);
}

function rrd_characterization_ds(string $name): array
{
    $sources = array(
        'traffic_in' => array('data_template_rrd_id' => '101', 'local_data_id' => '11', 'local_data_template_rrd_id' => '1', 'rrd_minimum' => '0', 'rrd_maximum' => '|query_ifSpeed|'),
        'traffic_out' => array('data_template_rrd_id' => '102', 'local_data_id' => '11', 'local_data_template_rrd_id' => '2', 'rrd_minimum' => '0', 'rrd_maximum' => '1000000'),
        'errors' => array('data_template_rrd_id' => '103', 'local_data_id' => '12', 'local_data_template_rrd_id' => '3', 'rrd_minimum' => 'U', 'rrd_maximum' => 'U'),
    );

    return $sources[$name] + array('data_source_name' => $name, 'snmp_query_id' => '1', 'snmp_index' => '2');
}

/** Fixture rows for one CDEF or VDEF: $items maps item id to array(type, value). */
function rrd_characterization_rpn(string $table, int $id, array $items): array
{
    $rows = array();
    $entries = array();
    foreach ($items as $item => $definition) {
        $rows[] = array('id' => (string) $item, 'type' => $definition[0], 'value' => $definition[1]);
        $entries[] = array('sql' => 'SELECT type, value FROM ' . $table . '_items WHERE id', 'params' => array($item), 'result' => array('type' => $definition[0], 'value' => $definition[1]));
    }
    $list = $table === 'cdef' ? 'SELECT id, type, value FROM cdef_items' : 'SELECT * FROM vdef_items';

    return array_merge(array(array('sql' => $list, 'params' => array($id), 'result' => $rows)), $entries);
}

function rrd_characterization_host(): array
{
    return array(
        'id' => '3', 'hostname' => 'router-1.example', 'description' => 'Core <router> & "edge"', 'site_name' => 'HQ',
        'notes' => '', 'location' => 'rack:1', 'polling_time' => '0.1', 'avg_time' => '1', 'cur_time' => '1',
        'availability' => '100', 'snmp_sysUpTimeInstance' => '0', 'snmp_sysDescr' => '', 'snmp_sysObjectID' => '',
        'snmp_sysContact' => '', 'snmp_sysName' => '', 'snmp_sysLocation' => '', 'snmp_community' => 'public',
        'snmp_version' => '2', 'snmp_username' => '', 'snmp_port' => '161', 'snmp_timeout' => '500',
        'external_id' => '', 'status_event_count' => '0', 'status_fail_date' => '0000-00-00 00:00:00',
        'status_rec_date' => '0000-00-00 00:00:00', 'status_last_error' => '', 'min_time' => '0', 'max_time' => '0',
        'total_polls' => '0', 'failed_polls' => '0', 'host_template_id' => '0', 'status' => '3', 'disabled' => '',
        'snmp_password' => 'secret', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '', 'snmp_priv_protocol' => '',
        'snmp_context' => '', 'snmp_engine_id' => '', 'ping_retries' => '1', 'max_oids' => '10',
    );
}

/** Options every scenario starts from; a scenario or call overrides any of them. */
function rrd_characterization_options(): array
{
    return array(
        'path_rrdtool' => './rrdtool', 'selected_theme' => 'modern', 'font_method' => '1', 'rrdtool_version' => '1.7.2',
        'default_date_format' => '4', 'default_datechar' => '1', 'graph_dateformat' => 'Y-m-d H:i:s',
        'date' => '2023-11-14 22:13:20', 'max_title_length' => '80', 'poller_interval' => '300',
        'graph_watermark' => '', 'rrdtool_watermark' => '', 'auth_method' => '1',
    );
}

/** Rows for graph 7 on host 3: two RRD files, three data sources, two CDEFs and one VDEF. */
function rrd_characterization_graph_db(array $graph, array $items): array
{
    return array_merge(
        array(
            array('sql' => 'SELECT dtr.local_data_id FROM graph_templates_item AS gti INNER JOIN data_template_rrd', 'result' => array(array('local_data_id' => '11'), array('local_data_id' => '12'))),
            array('sql' => 'SELECT dsp.step, dspr.steps, dspr.rows, dspr.timespan FROM data_source_profiles', 'result' => array(array('step' => '300', 'steps' => '1', 'rows' => '4000000', 'timespan' => '86400'))),
            array('sql' => 'SELECT DISTINCT dspr.id, dsp.step, dspr.steps', 'result' => array(array('id' => '1', 'step' => '300', 'steps' => '1', 'rows' => '4000000', 'name' => 'Daily', 'rrd_step' => '300', 'timespan' => '86400'))),
            array('sql' => 'FROM graph_templates_graph AS gtg INNER JOIN graph_local', 'result' => $graph),
            array('sql' => 'FROM graph_templates_item AS gti LEFT JOIN data_template_rrd', 'result' => $items),
            array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(11), 'result' => array('name' => 'Traffic', 'data_source_path' => 'rra/router_traffic_11.rrd')),
            array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(12), 'result' => array('name' => 'Errors', 'data_source_path' => 'rra/router_errors_12.rrd')),
            array('sql' => 'SELECT rrd_step FROM data_template_data WHERE local_data_id', 'result' => '300'),
            array('sql' => 'FROM host AS h LEFT JOIN sites', 'result' => rrd_characterization_host()),
            array('sql' => 'field_name, field_value FROM host_snmp_cache', 'result' => array(
                array('field_name' => 'ifAlias', 'field_value' => 'uplink \'A\' "B" & <C>: 10%'),
                array('field_name' => 'ifSpeed', 'field_value' => '1000000000'),
            )),
            array('sql' => 'SELECT gprint_text from graph_templates_gprint', 'result' => '%8.2lf %s'),
        ),
        // CDEF 1 is CURRENT_DATA_SOURCE,8,* and CDEF 2 is SIMILAR_DATA_SOURCES_NODUPS,CURRENT_DS_MAXIMUM_VALUE,/
        rrd_characterization_rpn('cdef', 1, array(11 => array('4', 'CURRENT_DATA_SOURCE'), 12 => array('6', '8'), 13 => array('2', '3'))),
        rrd_characterization_rpn('cdef', 2, array(21 => array('4', 'SIMILAR_DATA_SOURCES_NODUPS'), 22 => array('4', 'CURRENT_DS_MAXIMUM_VALUE'), 23 => array('2', '4'))),
        // VDEF 1 is CURRENT_DATA_SOURCE,95,PERCENT
        rrd_characterization_rpn('vdef', 1, array(31 => array('4', 'CURRENT_DATA_SOURCE'), 32 => array('6', '95'), 33 => array('1', '8'))),
    );
}
