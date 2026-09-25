<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

/**
 * poller_realtime.php and graph_realtime.php set force_storage_location_local
 * on an installation that stores RRDs through the proxy. rrdtool_execute()
 * then uses the local pipe, but several callers still test storage_location
 * alone and send the proxy-only file_exists, is_dir and mkdir verbs to it.
 */
function rrd_forced_local_scenario(): array
{
    $rras = array(array('rrd_step' => '300', 'x_files_factor' => '0.5', 'steps' => '1', 'rows' => '600', 'consolidation_function_id' => '1', 'rra_order' => '600'));
    $forced = array('force_storage_location_local' => true);

    return array(
        'line_mode' => true,
        // The main RRD of data source 11 is on this host.
        'files' => array('router_traffic_11.rrd'),
        'options' => array('storage_location' => '1', 'extended_paths' => '', 'default_interface_speed' => '') + rrd_characterization_options(),
        'replies' => array('fetch' => " value\n\n1700000300: 1.0000000000e+00\n", 'info' => "filename = \"x\"\n"),
        'db' => array(
            array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(11), 'result' => array('name' => 'Traffic', 'data_source_path' => '<path_rra>/router_traffic_11.rrd')),
            array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(12), 'result' => array('name' => 'Nested', 'data_source_path' => '<path_rra>/3/nested_12.rrd')),
            array('sql' => 'SELECT dsp.step, dspr.steps, dspr.rows, dspr.timespan FROM data_source_profiles', 'result' => array(array('step' => '300', 'steps' => '1', 'rows' => '600', 'timespan' => '86400'))),
            array('sql' => 'LEFT JOIN data_source_profiles_cf AS dspc', 'result' => $rras),
            array('sql' => 'SELECT data_template_id FROM data_local', 'result' => '0'),
            array('sql' => 'FROM data_template_rrd AS dtr WHERE local_data_id', 'result' => array(
                array('id' => '301', 'data_source_name' => 'value', 'rrd_heartbeat' => '600', 'rrd_minimum' => '0', 'rrd_maximum' => '100', 'data_source_type_id' => '1'),
            )),
            array('sql' => 'FROM data_template_rrd AS dtr WHERE dtr.local_data_id', 'result' => array(
                array('id' => '301', 'data_source_name' => 'value', 'rrd_heartbeat' => '600', 'rrd_minimum' => '0', 'rrd_maximum' => '100', 'data_source_type_id' => '1'),
            )),
            array('sql' => 'SELECT host_id, snmp_query_id, snmp_index FROM data_local', 'result' => array('host_id' => '3', 'snmp_query_id' => '0', 'snmp_index' => '')),
            array('sql' => 'SELECT * FROM data_local WHERE id', 'result' => array('id' => '11', 'data_template_id' => '0', 'host_id' => '3', 'snmp_query_id' => '0', 'snmp_index' => '')),
            array('sql' => 'field_name="ifHighSpeed"', 'result' => ''),
            array('sql' => 'field_name="ifSpeed"', 'result' => ''),
            array('sql' => 'dtr.data_source_name, dtd.name FROM data_template_rrd', 'result' => array('data_source_name' => 'value', 'name' => 'Traffic')),
        ),
        'calls' => array(
            // Fetch loads lib/boost.php before it looks at its arguments.
            array('fn' => 'rrdtool_function_fetch', 'args' => array(0, 1700000000, 1700001000), 'config' => $forced),
            array('fn' => 'rrdtool_uses_proxy', 'args' => array()),
            // The file is on this host, and the answer is still false.
            array('fn' => 'rrdtool_file_exists', 'args' => array('rra/router_traffic_11.rrd')),
            // The existence check fails, so the existing RRD is created again;
            // RRDtool create replaces a file that is already there.
            array('fn' => 'rrdtool_function_create', 'args' => array(11, false)),
            array('fn' => 'boost_rrdtool_function_create', 'args' => array(11, false, false)),
            // poller_realtime.php updates its cache file; the missing-file branch
            // creates the data source's main RRD before the update.
            array('fn' => 'rrdtool_function_update', 'args' => array(array('rra/realtime/user_abc_11.rrd' => array('local_data_id' => 11, 'data_template_id' => 0, 'times' => array(1700000600 => array('value' => '5')))))),
            // Readers go through rrdtool_execute() alone and stay local and consistent.
            array('fn' => 'rrdtool_function_info', 'args' => array(11)),
            array('fn' => 'rrdtool_function_fetch', 'args' => array(11, 1700000000, 1700001000, 300)),
            // A structured path sends is_dir and mkdir to the local pipe; RRDtool makes the directory.
            array('fn' => 'rrdtool_function_create', 'args' => array(12, false), 'options' => array('extended_paths' => 'on')),
            array('fn' => 'is_dir', 'args' => array('rra/3')),
            // Resize is refused as if remote, though the pipe is local.
            array('fn' => 'rrdtool_tune', 'args' => array('rra/router_traffic_11.rrd', array('resize' => array("'rra/router_traffic_11.rrd' 0 GROW 10")), false)),
            // Without storage_location the same create sees the file and stops.
            array('fn' => 'rrdtool_function_create', 'args' => array(11, false), 'options' => array('storage_location' => '0', 'extended_paths' => '')),
        ),
    );
}

test('known inconsistency: forced local storage still runs proxy-only existence checks on the local pipe', function () {
    $results = array_map('rrd_characterization_observed', rrd_characterization_run($this, rrd_forced_local_scenario())['results']);
    $observed = array_slice($results, 1);

    expect($observed[0]['returned'])->toBeFalse()
        ->and($observed[1]['returned'])->toBeFalse()
        ->and($observed[2]['commands'][1]['stdin'][0])->toStartWith('create ')
        ->and($observed[3]['commands'][1]['stdin'][0])->toStartWith('create ')
        ->and($observed[8]['returned'])->toBeTrue()
        ->and($observed[9]['returned'])->toBeFalse()
        ->and($observed[9]['commands'])->toBe(array())
        ->and($observed[10]['returned'])->toBe(-1)
        ->and($observed[10]['commands'])->toBe(array());
    rrd_characterization_golden('forced-local-transport', $observed);
});

test('RRDtool answers file_exists, is_dir and mkdir as the line-mode fake does', function () {
    $binary = getenv('RRDTOOL_TEST_BINARY');
    if (!$binary || !is_executable($binary)) {
        $this->markTestSkipped('RRDTOOL_TEST_BINARY is required');
    }
    $directory = sys_get_temp_dir() . '/rrd-forced-local-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $process = proc_open(array($binary, '-'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory, array('PATH' => getenv('PATH'), 'LANG' => 'C', 'LC_ALL' => 'C'));
        fwrite($pipes[0], "file_exists x.rrd\nis_dir a\nmkdir 'a'\nmkdir 'b/c'\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $made = is_dir($directory . '/a');
    } finally {
        foreach (array('/a', '/b/c', '/b') as $child) {
            if (is_dir($directory . $child)) {
                rmdir($directory . $child);
            }
        }
        rmdir($directory);
    }

    $lines = explode("\n", $stdout);
    expect($lines[0])->toBe("ERROR: unknown function 'file_exists'")
        ->and($lines[1])->toBe("ERROR: unknown function 'is_dir'")
        ->and($lines[2])->toStartWith('OK u:')
        ->and($lines[3])->toStartWith('ERROR: mkdir ')
        ->and($lines[3])->toEndWith('b/c: No such file or directory')
        ->and($made)->toBeTrue();
});
