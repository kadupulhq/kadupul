<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

function rrd_characterization_observe_all(array $output): array
{
    return array_map('rrd_characterization_observed', $output['results']);
}

test('create source matches its golden for each data source shape', function () {
    $rras = array(
        array('rrd_step' => '300', 'x_files_factor' => '0.5', 'steps' => '1', 'rows' => '600', 'consolidation_function_id' => '1', 'rra_order' => '600'),
        array('rrd_step' => '300', 'x_files_factor' => '0.5', 'steps' => '6', 'rows' => '700', 'consolidation_function_id' => '1', 'rra_order' => '4200'),
        array('rrd_step' => '300', 'x_files_factor' => '0.5', 'steps' => '1', 'rows' => '600', 'consolidation_function_id' => '3', 'rra_order' => '600'),
        array('rrd_step' => '300', 'x_files_factor' => '0.5', 'steps' => '288', 'rows' => '797', 'consolidation_function_id' => '4', 'rra_order' => '229536'),
    );
    $templated = array(
        array('id' => '201', 'data_source_name' => 'traffic_in', 'rrd_heartbeat' => '600', 'rrd_minimum' => '0', 'rrd_maximum' => '|query_ifSpeed|', 'data_source_type_id' => '2'),
        array('id' => '202', 'data_source_name' => '', 'rrd_heartbeat' => '600', 'rrd_minimum' => '10', 'rrd_maximum' => '5', 'data_source_type_id' => '2'),
        array('id' => '203', 'data_source_name' => 'drops', 'rrd_heartbeat' => '600', 'rrd_minimum' => '0', 'rrd_maximum' => '0', 'data_source_type_id' => '4'),
        array('id' => '204', 'data_source_name' => 'rate', 'rrd_heartbeat' => '600', 'rrd_minimum' => '0', 'rrd_maximum' => ' |query_ifAlias| ', 'data_source_type_id' => '3'),
        array('id' => '205', 'data_source_name' => 'gauge', 'rrd_heartbeat' => '120', 'rrd_minimum' => '-5', 'rrd_maximum' => '-10', 'data_source_type_id' => '1'),
        array('id' => '206', 'data_source_name' => 'dderive', 'rrd_heartbeat' => '600', 'rrd_minimum' => 'U', 'rrd_maximum' => ' U ', 'data_source_type_id' => '7'),
    );
    $db = array(
        array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(11), 'result' => array('name' => 'Traffic', 'data_source_path' => '<path_rra>/router_traffic_11.rrd')),
        array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(12), 'result' => array('name' => 'Plain', 'data_source_path' => '<path_rra>/plain_12.rrd')),
        array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(13), 'result' => array('name' => 'Empty', 'data_source_path' => '<path_rra>/empty_13.rrd')),
        array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(14), 'result' => array('name' => 'Nested', 'data_source_path' => '<path_rra>/3/nested_14.rrd')),
        array('sql' => 'LEFT JOIN data_source_profiles_cf AS dspc', 'params' => array(13), 'result' => array()),
        array('sql' => 'LEFT JOIN data_source_profiles_cf AS dspc', 'params' => array(12), 'result' => array(array('rrd_step' => '60') + $rras[0])),
        array('sql' => 'LEFT JOIN data_source_profiles_cf AS dspc', 'result' => $rras),
        array('sql' => 'SELECT data_template_id FROM data_local', 'params' => array(12), 'result' => '0'),
        array('sql' => 'SELECT data_template_id FROM data_local', 'result' => '5'),
        array('sql' => 'INNER JOIN graph_templates_item AS gti ON dtr.id = gti.task_item_id WHERE local_data_id', 'result' => $templated),
        array('sql' => 'FROM data_template_rrd AS dtr WHERE local_data_id', 'result' => array(
            array('id' => '301', 'data_source_name' => 'value', 'rrd_heartbeat' => '120', 'rrd_minimum' => 'U', 'rrd_maximum' => '|query_ifSpeed|', 'data_source_type_id' => '1'),
        )),
        array('sql' => 'SELECT host_id, snmp_query_id, snmp_index FROM data_local', 'params' => array(12), 'result' => array('host_id' => '4', 'snmp_query_id' => '0', 'snmp_index' => '')),
        array('sql' => 'SELECT host_id, snmp_query_id, snmp_index FROM data_local', 'result' => array('host_id' => '3', 'snmp_query_id' => '1', 'snmp_index' => '2')),
        array('sql' => 'field_name="ifHighSpeed"', 'params' => array(3, 1, 2), 'result' => '1000'),
        array('sql' => 'field_name="ifHighSpeed"', 'result' => ''),
        array('sql' => 'field_name="ifSpeed"', 'result' => ''),
        array('sql' => 'dtr.data_source_name, dtd.name FROM data_template_rrd', 'params' => array(202), 'result' => array('data_source_name' => '', 'name' => 'Errors in/out (per sec.)')),
        array('sql' => 'dtr.data_source_name, dtd.name FROM data_template_rrd', 'params' => array(301), 'result' => array('data_source_name' => 'value', 'name' => 'Plain')),
        array('sql' => 'field_name, field_value FROM host_snmp_cache', 'result' => array(array('field_name' => 'ifAlias', 'field_value' => '42'))),
    );
    foreach ($templated as $source) {
        if ($source['data_source_name'] !== '') {
            $db[] = array('sql' => 'dtr.data_source_name, dtd.name FROM data_template_rrd', 'params' => array($source['id']), 'result' => array('data_source_name' => $source['data_source_name'], 'name' => 'Traffic'));
        }
    }
    $scenario = array(
        'options' => array('default_interface_speed' => '', 'extended_paths' => '') + rrd_characterization_options(),
        'db' => $db,
        'calls' => array(
            array('fn' => 'rrdtool_function_create', 'args' => array(11, true)),
            array('fn' => 'rrdtool_function_create', 'args' => array(12, true)),
            array('fn' => 'rrdtool_function_create', 'args' => array(13, true)),
            // Showing the source with structured paths creates no directory.
            array('fn' => 'rrdtool_function_create', 'args' => array(14, true), 'options' => array('extended_paths' => 'on')),
            array('fn' => 'is_dir', 'args' => array('rra/3')),
        ),
    );
    rrd_characterization_golden('create-source', rrd_characterization_observe_all(rrd_characterization_run($this, $scenario)));
});

test('tune command matches its golden', function () {
    $db = array(
        array('sql' => 'dtr.data_source_name, dtd.name FROM data_template_rrd', 'result' => array('data_source_name' => 'traffic_in', 'name' => 'Traffic')),
        array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(21), 'result' => array('name' => 'Traffic', 'data_source_path' => '<path_rra>/router_traffic_21.rrd')),
        array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(22), 'result' => array('name' => 'Missing', 'data_source_path' => '<path_rra>/missing_22.rrd')),
    );
    $all = array('data_source_id' => 21, 'data-source-type' => '3', 'heartbeat' => '600', 'minimum' => 'U', 'maximum' => '1e9', 'data-source-rename' => "in'bound \"x\"");
    $scenario = array(
        'files' => array('router_traffic_21.rrd'),
        'options' => rrd_characterization_options(),
        'db' => $db,
        'calls' => array(
            array('fn' => 'rrdtool_function_tune', 'args' => array($all)),
            array('fn' => 'rrdtool_function_tune', 'args' => array(array('heartbeat' => '', 'minimum' => '0', 'maximum' => '', 'data-source-type' => '', 'data-source-rename' => '') + $all)),
            array('fn' => 'rrdtool_function_tune', 'args' => array(array('heartbeat' => '', 'minimum' => '', 'maximum' => '', 'data-source-type' => '', 'data-source-rename' => '') + $all)),
            array('fn' => 'rrdtool_function_tune', 'args' => array(array('heartbeat' => '', 'minimum' => '', 'maximum' => '5', 'data-source-type' => '99', 'data-source-rename' => '') + $all)),
            array('fn' => 'rrdtool_function_tune', 'args' => array(array('data_source_id' => 22) + $all)),
            array('fn' => 'rrdtool_function_tune', 'args' => array(array('data-source-rename' => "in\0bound") + $all)),
            // Array arguments are quoted for the pipe; a NUL is refused before RRDtool starts.
            array('fn' => 'rrdtool_execute', 'args' => array(array('info', "<path_rra>/router\0traffic_21.rrd"), false, 1)),
            // A line break in an array argument is refused too, not removed, since removing it names another file.
            array('fn' => 'rrdtool_execute', 'args' => array(array('info', "rra/router\ntraffic_21.rrd"), false, 1)),
            array('fn' => 'rrdtool_execute', 'args' => array(array('info', "rra/router\rtraffic_21.rrd"), false, 1)),
        ),
    );
    rrd_characterization_golden('tune-command', rrd_characterization_observe_all(rrd_characterization_run($this, $scenario)));
});

test('fetch command and parsing match their golden', function () {
    $reply = " traffic_in traffic_out\n\n1700000300: 1.0000000000e+00 nan\n1700000600: -nan 2.5000000000e+03\n1700000900: 3 bogus\n\n";
    $scenario = array(
        'options' => rrd_characterization_options(),
        'replies' => array('fetch' => $reply),
        'db' => array(
            array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => array('name' => 'Traffic', 'data_source_path' => '<path_rra>/router_traffic_11.rrd')),
            array('sql' => 'SELECT dsp.step, dspr.steps, dspr.rows, dspr.timespan FROM data_source_profiles', 'result' => array(array('step' => '300', 'steps' => '1', 'rows' => '4000000', 'timespan' => '86400'))),
        ),
        'calls' => array(
            array('fn' => 'rrdtool_function_fetch', 'args' => array(11, 1700000000, 1700001000)),
            array('fn' => 'rrdtool_function_fetch', 'args' => array(11, 1700000000, 1700001000, 60, true, null, 'MAX')),
            array('fn' => 'rrdtool_function_fetch', 'args' => array(0, 1700000000, 1700001000, 0, false, 'rra/other.rrd', 'LAST')),
            array('fn' => 'rrdtool_function_fetch', 'args' => array(0, 1700000000, 1700001000)),
        ),
    );
    rrd_characterization_golden('fetch', rrd_characterization_observe_all(rrd_characterization_run($this, $scenario)));
});

/** A data source whose path has a space and a quote, and one whose path has a NUL. */
function rrd_characterization_path_quoting_scenario(array $options = array()): array
{
    return array(
        'files' => array("it's a.rrd"),
        'options' => $options + array('extended_paths' => '', 'default_interface_speed' => '') + rrd_characterization_options(),
        'replies' => array('fetch' => " value\n\n1700000300: 1.0000000000e+00\n", 'last' => "1700000300\n"),
        'db' => array(
            array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(31), 'result' => array('name' => 'Quoted', 'data_source_path' => "<path_rra>/it's a.rrd")),
            array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(32), 'result' => array('name' => 'Nul', 'data_source_path' => "<path_rra>/it\0s.rrd")),
            array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(33), 'result' => array('name' => 'New', 'data_source_path' => "<path_rra>/new 'one'.rrd")),
            array('sql' => 'SELECT dsp.step, dspr.steps, dspr.rows, dspr.timespan FROM data_source_profiles', 'result' => array(array('step' => '300', 'steps' => '1', 'rows' => '600', 'timespan' => '86400'))),
            array('sql' => 'LEFT JOIN data_source_profiles_cf AS dspc', 'result' => array(array('rrd_step' => '300', 'x_files_factor' => '0.5', 'steps' => '1', 'rows' => '600', 'consolidation_function_id' => '1', 'rra_order' => '600'))),
            array('sql' => 'SELECT data_template_id FROM data_local', 'result' => '0'),
            array('sql' => 'FROM data_template_rrd AS dtr WHERE local_data_id', 'result' => array(
                array('id' => '301', 'data_source_name' => 'value', 'rrd_heartbeat' => '600', 'rrd_minimum' => '0', 'rrd_maximum' => '|query_ifAlias|', 'data_source_type_id' => '1'),
            )),
            array('sql' => 'SELECT host_id, snmp_query_id, snmp_index FROM data_local', 'result' => array('host_id' => '3', 'snmp_query_id' => '1', 'snmp_index' => '2')),
            // Boost reads the same rows with its own queries.
            array('sql' => 'FROM data_template_rrd AS dtr WHERE dtr.local_data_id', 'result' => array(
                array('id' => '301', 'data_source_name' => 'value', 'rrd_heartbeat' => '600', 'rrd_minimum' => '0', 'rrd_maximum' => '|query_ifAlias|', 'data_source_type_id' => '1'),
            )),
            array('sql' => 'SELECT * FROM data_local WHERE id', 'result' => array('id' => '33', 'data_template_id' => '0', 'host_id' => '3', 'snmp_query_id' => '1', 'snmp_index' => '2')),
            array('sql' => 'field_name="ifHighSpeed"', 'result' => ''),
            array('sql' => 'field_name="ifSpeed"', 'result' => ''),
            array('sql' => 'dtr.data_source_name, dtd.name FROM data_template_rrd', 'result' => array('data_source_name' => 'value', 'name' => 'Quoted')),
            // A device value in the maximum must stay inside its DS argument.
            array('sql' => 'field_name, field_value FROM host_snmp_cache', 'result' => array(array('field_name' => 'ifAlias', 'field_value' => "10 --daemon x'y"))),
        ),
    );
}

test('commands on an RRD path with a space and a quote match their golden', function () {
    $path = "rra/it's a.rrd";
    $scenario = rrd_characterization_path_quoting_scenario();
    $scenario['calls'] = array(
        array('fn' => 'rrdtool_function_info', 'args' => array(31)),
        array('fn' => 'rrdtool_function_fetch', 'args' => array(31, 1700000000, 1700001000, 300)),
        array('fn' => 'rrdtool_function_create', 'args' => array(33, true)),
        array('fn' => 'boost_rrdtool_get_last_update_time', 'args' => array($path, false)),
        array('fn' => 'rrdtool_function_create', 'args' => array(33, false)),
        // A NUL is refused before RRDtool starts and the caller sees its usual failure.
        array('fn' => 'rrdtool_function_info', 'args' => array(32)),
        array('fn' => 'rrdtool_function_fetch', 'args' => array(32, 1700000000, 1700001000, 300)),
        array('fn' => 'rrdtool_function_fetch', 'args' => array(0, 1700000000, 1700001000, 300, false, "rra/it\0s.rrd")),
        array('fn' => 'rrdtool_function_create', 'args' => array(32, false)),
        array('fn' => 'rrdtool_function_update', 'args' => array(array("rra/it\0s.rrd" => array('local_data_id' => 32, 'data_template_id' => 0, 'times' => array(1700000600 => array('value' => '5')))))),
    );
    rrd_characterization_golden('path-quoting', rrd_characterization_observe_all(rrd_characterization_run($this, $scenario)));
});

test('a create path RRDtool cannot receive is refused before any directory is made', function () {
    $scenario = rrd_characterization_path_quoting_scenario(array('extended_paths' => 'on'));
    array_unshift($scenario['db'], array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(34), 'result' => array('name' => 'Break', 'data_source_path' => "<path_rra>/new\ndir/x.rrd")));
    $scenario['calls'] = array(
        // Fetch loads lib/boost.php before it looks at its arguments.
        array('fn' => 'rrdtool_function_fetch', 'args' => array(0, 1700000000, 1700001000)),
        array('fn' => 'rrdtool_function_create', 'args' => array(34, false)),
        array('fn' => 'boost_rrdtool_function_create', 'args' => array(34, false, false)),
        array('fn' => 'rrdtool_function_create', 'args' => array(32, false)),
        array('fn' => 'boost_rrdtool_function_create', 'args' => array(32, false, false)),
        array('fn' => 'is_dir', 'args' => array("rra/new\ndir")),
    );
    $results = array_slice(rrd_characterization_run($this, $scenario)['results'], 1);

    foreach (array_slice($results, 0, 4) as $result) {
        expect($result['returned'])->toBeFalse()->and($result['sent'])->toBe(array());
    }
    expect($results[4]['returned'])->toBeFalse();
});

/** The quoting scenario with $minimum stored as the minimum of data source 33. */
function rrd_characterization_minimum_scenario(string $minimum): array
{
    $scenario = rrd_characterization_path_quoting_scenario();
    foreach ($scenario['db'] as $index => $row) {
        if (str_starts_with($row['sql'], 'FROM data_template_rrd AS dtr WHERE')) {
            $scenario['db'][$index]['result'][0]['rrd_minimum'] = $minimum;
        }
    }
    $scenario['calls'] = array(
        // Fetch loads lib/boost.php before it looks at its arguments.
        array('fn' => 'rrdtool_function_fetch', 'args' => array(0, 1700000000, 1700001000)),
        array('fn' => 'rrdtool_function_create', 'args' => array(33, true)),
        array('fn' => 'rrdtool_function_create', 'args' => array(33, false)),
        array('fn' => 'boost_rrdtool_function_create', 'args' => array(33, true, false)),
        array('fn' => 'boost_rrdtool_function_create', 'args' => array(33, false, false)),
    );

    return $scenario;
}

test('a stored minimum other than a number or U refuses the create and sends nothing', function ($minimum) {
    $results = array_slice(rrd_characterization_run($this, rrd_characterization_minimum_scenario($minimum))['results'], 1);

    expect($results)->toHaveCount(4);
    foreach ($results as $result) {
        expect($result['returned'])->toBeFalse()->and($result['sent'])->toBe(array());
    }
})->with(array('5 x', '0;x', '-', '1e', '0x10', ' 0', "0\n", '|query_ifSpeed|', ''));

test('a stored numeric or U minimum is written unchanged', function ($minimum) {
    $results = array_slice(rrd_characterization_run($this, rrd_characterization_minimum_scenario($minimum))['results'], 1);

    foreach (array(0, 2) as $shown) {
        expect($results[$shown]['returned'])->toContain('DS:value:GAUGE:600:' . $minimum . ':');
    }
    foreach (array(1, 3) as $created) {
        expect($results[$created]['sent'])->toHaveCount(1)
            ->and($results[$created]['sent'][0]['stdin'])->toContain(' DS:value:GAUGE:600:' . $minimum . ':');
    }
})->with(array('0', '-5', '2.5', '.5', '5.', '1e3', '-2.5E-3', '+5', 'U'));

test('a line break in a substituted maximum never splits the create command', function () {
    $path = "rra/it's a.rrd";
    $observed = array();
    // Surrounding line breaks are trimmed; one inside the value refuses the create.
    foreach (array("\n100", "100\r\n", "10\n0", "100\0") as $alias) {
        $scenario = rrd_characterization_path_quoting_scenario();
        foreach ($scenario['db'] as $index => $row) {
            if ($row['sql'] === 'field_name, field_value FROM host_snmp_cache') {
                $scenario['db'][$index]['result'][0]['field_value'] = $alias;
            }
        }
        $scenario['calls'] = array(
            // Fetch loads lib/boost.php before it looks at its arguments.
            array('fn' => 'rrdtool_function_fetch', 'args' => array(0, 1700000000, 1700001000)),
            array('fn' => 'rrdtool_function_create', 'args' => array(33, false)),
            array('fn' => 'boost_rrdtool_function_create', 'args' => array(33, false, false)),
        );
        $observed[json_encode($alias)] = array_slice(rrd_characterization_observe_all(rrd_characterization_run($this, $scenario)), 1);
    }
    foreach ($observed as $calls) {
        foreach ($calls as $call) {
            expect(count($call['commands']))->toBeLessThanOrEqual(1);
        }
    }
    rrd_characterization_golden('create-maximum-line-breaks', $observed);
});

test('a NUL in a Boost RRD path writes nothing to RRDtool', function () {
    $scenario = rrd_characterization_path_quoting_scenario();
    $scenario['db'][] = array('sql' => 'SELECT id FROM data_local WHERE id', 'result' => '32');
    $scenario['calls'] = array(
        array('fn' => 'rrdtool_function_fetch', 'args' => array(0, 1700000000, 1700001000)),
        array('fn' => 'boost_rrdtool_function_create', 'args' => array(32, false, false)),
        // The existence check fails for a NUL path, so the update tries to
        // create the file, that is refused, and no update is sent.
        array('fn' => 'boost_rrdtool_function_update', 'args' => array(32, "rra/it\0s.rrd", 'value', '1700000900:6', false)),
    );
    rrd_characterization_golden('boost-nul-path', array_slice(rrd_characterization_observe_all(rrd_characterization_run($this, $scenario)), 1));
});

test('pure helpers match their golden', function () {
    $root = dirname(__DIR__, 4);
    $format = array('graph_start' => 1700000000, 'graph_end' => 1700086400);
    $window = array('graph_opts' => '--start=x', 'graph_defs' => 'DEF:a=x:y:AVERAGE' . " \\\n", 'txt_graph_items' => 'AREA:a', 'graph_id' => 7, 'start' => 1700179200, 'end' => 1700438400);
    $hours = array('business_hours_enable' => 'on', 'business_hours_start' => '09:15', 'business_hours_end' => '17:00', 'business_hours_max_days' => '7', 'business_hours_color' => '00ff0040', 'business_hours_hideWeekends' => '');
    $graph = array('host_id' => '3', 'snmp_query_id' => '1', 'snmp_index' => '2');
    $themefonts = array('title' => array('font' => 'Title Font', 'size' => '14'), 'axis' => array('font' => "O'Brien", 'size' => '2'), 'legend' => array('font' => 'Mono', 'size' => 'x'));
    $calls = array(
        array('fn' => 'escape_command', 'args' => array('graph "x" $(y) `z`')),
        array('fn' => 'rrdtool_escape_string', 'args' => array('say "hi": 50% done')),
        array('fn' => 'rrdtool_escape_string', 'args' => array('say "hi": 50% done', false)),
        array('fn' => 'rrdtool_escape_string', 'args' => array("\\\"already\\: escaped\\\"", false)),
        array('fn' => 'gradient', 'args' => array('a', '#0000a0', '#f0f0f0', 'Inbound "x"', 4)),
        array('fn' => 'gradient', 'args' => array('cdefb', '336699', 'ffcc00', 'ab', 3, '50%', '80')),
        array('fn' => 'gradient', 'args' => array('b', '#102030', '#405060', false, 2, '10')),
        array('fn' => 'gradient', 'args' => array('c', '#000000', '#ffffff', "it's", 2)),
        array('fn' => 'colourBrightness', 'args' => array('#1a2b3c', 0.4)),
        array('fn' => 'colourBrightness', 'args' => array('abcdef', -40)),
        array('fn' => 'colourBrightness', 'args' => array('#FFF', 0.5)),
        array('fn' => 'colourBrightness', 'args' => array('808080', 150)),
        array('fn' => 'colourBrightness', 'args' => array('336699', 1.5)),
        array('fn' => 'add_business_hours', 'args' => array($window), 'options' => array('business_hours_enable' => '')),
        array('fn' => 'add_business_hours', 'args' => array($window), 'options' => $hours),
        array('fn' => 'add_business_hours', 'args' => array($window), 'options' => array('business_hours_hideWeekends' => 'on', 'business_hours_color' => 'red')),
        array('fn' => 'add_business_hours', 'args' => array(array('start' => 1700000000, 'end' => 1701000000) + $window), 'options' => array('business_hours_max_days' => '7')),
        array('fn' => 'add_business_hours', 'args' => array(array('start' => 1700020000, 'end' => 1700030000) + $window), 'options' => array('business_hours_end' => '06:00')),
        array('fn' => 'rrdtool_parse_error', 'args' => array('plain rrdtool failure')),
        // A file outside the install is shown as a custom folder; one inside it relative to rra/.
        array('fn' => 'rrdtool_parse_error', 'args' => array("ERROR: opening 'rra/missing.rrd': No such file or directory")),
        array('fn' => 'rrdtool_parse_error', 'args' => array("ERROR: opening '" . $root . "/rra/missing.rrd': No such file or directory")),
        // Inside the install but not under rra/: the install path is not a folder to show.
        array('fn' => 'rrdtool_parse_error', 'args' => array("ERROR: opening '" . $root . "/missing.rrd': No such file or directory")),
        array('fn' => 'rrdtool_parse_error', 'args' => array("ERROR: opening 'rra/unwritable/x.rrd': Permission denied")),
        array('fn' => 'rrdtool_parse_error', 'args' => array("ERROR: opening '/nonexistent/dir/x.rrd': No such file or directory")),
        array('fn' => 'rrdtool_function_set_font', 'args' => array('title', '', $themefonts)),
        array('fn' => 'rrdtool_function_set_font', 'args' => array('title', 'on', $themefonts)),
        array('fn' => 'rrdtool_function_set_font', 'args' => array('axis', '', $themefonts)),
        array('fn' => 'rrdtool_function_set_font', 'args' => array('legend', '', $themefonts)),
        array('fn' => 'rrdtool_function_set_font', 'args' => array('unit', '', $themefonts)),
        array('fn' => 'rrdtool_set_font', 'args' => array('title', '', array()), 'options' => array('font_method' => '0', 'title_font' => '', 'title_size' => '0')),
        array('fn' => 'rrdtool_function_set_font', 'args' => array('watermark', '', array()), 'options' => array('watermark_font' => 'Sans "Bold"', 'watermark_size' => '4.5')),
        array('fn' => 'rrd_substitute_host_query_data', 'args' => array('no variables here', $graph, array())),
        array('fn' => 'rrd_substitute_host_query_data', 'args' => array('|host_hostname| |host_description| |query_ifAlias| |host_snmp_community|', $graph, array())),
        array('fn' => 'rrd_substitute_host_query_data', 'args' => array('|host_hostname| |query_ifAlias|', array('host_id' => '0'), array('local_data_id' => '11', 'snmp_query_id' => '1', 'snmp_index' => '2'))),
        array('fn' => 'rrd_substitute_host_query_data', 'args' => array('|query_ifSpeed| |input_missing|', array('host_id' => '0', 'snmp_query_id' => '1', 'snmp_index' => '2'), array())),
        array('fn' => 'rrdtool_function_interface_speed', 'args' => array(array('host_id' => '1', 'snmp_query_id' => '1', 'snmp_index' => '1'))),
        array('fn' => 'rrdtool_function_interface_speed', 'args' => array(array('host_id' => '2', 'snmp_query_id' => '1', 'snmp_index' => '1'))),
        array('fn' => 'rrdtool_function_interface_speed', 'args' => array(array('host_id' => '4', 'snmp_query_id' => '1', 'snmp_index' => '1')), 'options' => array('default_interface_speed' => '1000')),
        array('fn' => 'rrdtool_function_interface_speed', 'args' => array(array('host_id' => '4', 'snmp_query_id' => '1', 'snmp_index' => '1')), 'options' => array('default_interface_speed' => '')),
        array('fn' => 'rrdtool_trim_output', 'args' => array("line one\nline two\nOK u:0.01 s:0.02 r:0.03\n")),
        array('fn' => 'rrdtool_trim_output', 'args' => array("no status line\n")),
    );
    foreach (array('0', '1', '2', '3', '4', '5', '9') as $date_format) {
        foreach (array('0', '1', '2') as $datechar) {
            $calls[] = array('fn' => 'rrdtool_function_format_graph_date', 'args' => array($format), 'options' => array('default_date_format' => $date_format, 'default_datechar' => $datechar));
        }
    }
    $calls[] = array('fn' => 'rrdtool_function_format_graph_date', 'args' => array(array('graph_start' => -3600, 'graph_end' => 1700000000)));
    $calls[] = array('fn' => 'rrdtool_function_format_graph_date', 'args' => array(array('graph_start' => 1700000000)));
    $scenario = array(
        'files' => array('unwritable'),
        'options' => rrd_characterization_options(),
        'db' => array(
            array('sql' => 'SELECT host_id FROM data_local', 'result' => '3'),
            array('sql' => 'FROM host AS h LEFT JOIN sites', 'result' => rrd_characterization_host()),
            array('sql' => 'field_name, field_value FROM host_snmp_cache', 'result' => array(array('field_name' => 'ifAlias', 'field_value' => 'uplink'), array('field_name' => 'ifSpeed', 'field_value' => '1000000000'))),
            array('sql' => 'field_name="ifHighSpeed"', 'params' => array(1, 1, 1), 'result' => '10000'),
            array('sql' => 'field_name="ifHighSpeed"', 'result' => ''),
            array('sql' => 'field_name="ifSpeed"', 'params' => array(2, 1, 1), 'result' => '100000000'),
            array('sql' => 'field_name="ifSpeed"', 'result' => null),
        ),
        'calls' => $calls,
    );
    $output = rrd_characterization_run($this, $scenario);
    $observed = array();
    foreach ($calls as $index => $call) {
        $result = $output['results'][$index];
        // is_resource_writable() probes with a uniqid() file name.
        $diagnostics = preg_replace('/[0-9a-f]{13,}\.tmp/', '<probe>.tmp', $result['diagnostics']);
        $args_after = $result['args'];
        array_walk_recursive($args_after, function (&$value) use ($root) {
            $value = is_string($value) ? str_replace($root, '<ROOT>', $value) : $value;
        });
        $observed[] = array('fn' => $call['fn'], 'options' => $call['options'] ?? array(), 'returned' => $result['returned'], 'args_after' => $args_after, 'diagnostics' => $diagnostics);
    }
    rrd_characterization_golden('helpers', $observed);
});

test('proxy payload encryption matches its golden', function () {
    $scenario = array(
        'options' => rrd_characterization_options(),
        'calls' => array(
            // There is no plaintext mode: a key that does not load, a plaintext
            // reply and a malformed frame all fail.
            array('fn' => 'encrypt', 'args' => array('update x.rrd N:1', 'public key')),
            array('fn' => 'decrypt', 'args' => array('OK u:0.00')),
            array('fn' => 'decrypt', 'args' => array('010QUJD')),
            // Without a client key pair, proxy setup stops before it opens a socket.
            array('fn' => '__rrd_proxy_init', 'args' => array('POLLER'), 'options' => array('storage_location' => '1')),
        ),
    );
    rrd_characterization_golden('proxy-encryption', rrd_characterization_observe_all(rrd_characterization_run($this, $scenario)));
});

test('an RRD path with a space and a quote round-trips through RRDtool', function () {
    $binary = getenv('RRDTOOL_TEST_BINARY');
    if (!$binary || !is_executable($binary)) {
        $this->markTestSkipped('RRDTOOL_TEST_BINARY is required');
    }
    $path = "rra/it's a.rrd";
    $scenario = rrd_characterization_path_quoting_scenario(array('path_rrdtool' => $binary));
    unset($scenario['files']);
    foreach ($scenario['db'] as $index => $row) {
        if ($row['sql'] === 'field_name, field_value FROM host_snmp_cache') {
            $scenario['db'][$index]['result'][0]['field_value'] = '100';
        }
        if (($row['params'] ?? null) === array(33)) {
            unset($scenario['db'][$index]);
        }
    }
    $scenario['db'] = array_values($scenario['db']);
    // create --start 0 starts the RRD at midnight today, so samples follow now.
    // 900 s ahead keeps the first sample more than one heartbeat (600 s) after
    // midnight, so it stays unknown even in the first minutes of a day.
    $t = intdiv(time(), 300) * 300 + 900;
    $scenario['calls'] = array(
        // Fetch loads lib/boost.php before it looks at its arguments.
        array('fn' => 'rrdtool_function_fetch', 'args' => array(0, $t, $t)),
        array('fn' => 'rrdtool_function_create', 'args' => array(31, false)),
        array('fn' => 'rrdtool_function_update', 'args' => array(array($path => array('local_data_id' => 31, 'data_template_id' => 0, 'times' => array($t => array('value' => '5')))))),
        array('fn' => 'boost_rrdtool_function_update', 'args' => array(31, $path, 'value', ($t + 300) . ':6', false)),
        array('fn' => 'boost_rrdtool_get_last_update_time', 'args' => array($path, false)),
        array('fn' => 'rrdtool_function_info', 'args' => array(31)),
        array('fn' => 'rrdtool_function_fetch', 'args' => array(31, $t - 300, $t + 300, 300)),
    );
    $results = array_slice(array_column(rrd_characterization_run($this, $scenario)['results'], 'returned'), 1);

    expect($results[0])->toBe('')
        ->and($results[1])->toBe(1)
        ->and($results[2])->toBe('OK')
        ->and($results[3])->toBe((string) ($t + 300))
        ->and($results[4]['last_update'])->toBe((string) ($t + 300))
        ->and($results[4]['ds']['value']['max'])->toBe('1.0000000000e+02')
        // The first sample follows a gap longer than the heartbeat, so it only
        // makes the second one known.
        ->and($results[5]['values'][0])->toBe(array($t + 300 => '6.0000000000e+00'));
});

test('RRD file information HTML matches its golden', function () {
    $info = array(
        'filename' => 'rra/router_traffic_11.rrd', 'rrd_version' => '0003', 'step' => '300', 'last_update' => '1700000000',
        'ds' => array(
            'traffic_in' => array('type' => 'COUNTER', 'minimal_heartbeat' => '600', 'min' => '0', 'max' => '1000000000', 'last_ds' => '12345', 'value' => '1.5', 'unknown_sec' => '0'),
            'traffic_out' => array('type' => 'DERIVE', 'minimal_heartbeat' => '600', 'min' => 'U', 'max' => 'U', 'last_ds' => 'U', 'value' => 'NaN', 'unknown_sec' => 'x'),
            'errors' => array('min' => 'abc', 'max' => 'NaN'),
            'odd' => array('type' => 'GAUGE', 'max' => 'xyz'),
        ),
        'rra' => array(
            '0' => array('cf' => 'AVERAGE', 'rows' => '600', 'cur_row' => '12', 'pdp_per_row' => '1', 'xff' => '0.5', 'cdp_prep' => array(array('value' => 'NaN', 'unknown_datapoints' => '0'))),
            '1' => array('cf' => 'MAX', 'rows' => '700', 'cur_row' => '3', 'pdp_per_row' => '6', 'xff' => '0.25', 'cdp_prep' => array(array('value' => '2.5000000000e+00', 'unknown_datapoints' => '1'))),
            'cacti_2' => array('cf' => 'LAST', 'rows' => '10', 'xff' => '0.5'),
        ),
    );
    $diff = array(
        'step' => 'wrong step',
        'ds' => array('traffic_in' => array('type' => 'x', 'minimal_heartbeat' => 'x'), 'errors' => array('error' => 'missing')),
        'rra' => array('1' => array('rows' => 'x', 'xff' => 'x'), 'cacti_2' => array('error' => 'missing')),
    );
    $calls = array(
        array('fn' => 'define', 'args' => array('CACTI_DATE_TIME_FORMAT', 'Y-m-d H:i:s')),
        array('fn' => 'rrdtool_info2html', 'args' => array($info)),
        array('fn' => 'rrdtool_info2html', 'args' => array($info, $diff)),
        array('fn' => 'rrdtool_info2html', 'args' => array(array('ds' => array(), 'rra' => array()) + $info)),
    );
    $output = rrd_characterization_run($this, array('options' => rrd_characterization_options(), 'calls' => $calls));
    $observed = array();
    foreach (array_slice($output['results'], 1) as $result) {
        $observed[] = array('printed' => explode("\n", $result['printed']), 'diagnostics' => $result['diagnostics']);
    }
    rrd_characterization_golden('info-html', $observed);
});
