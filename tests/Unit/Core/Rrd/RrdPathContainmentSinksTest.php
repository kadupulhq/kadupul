<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

/*
 * Runs the real lib/rrd.php and lib/boost.php against a fake RRDtool and a real
 * temporary RRA directory. Every call below names the same RRD path, and the
 * fake records what it was sent, so a refused path shows as no command at all.
 */

/**
 * @return array<string, mixed>
 */
function rrd_containment_scenario(string $stored, string $path, array $extra = array()): array
{
    $update = array($path => array('local_data_id' => 11, 'data_template_id' => 0, 'times' => array(1700000600 => array('value' => '5'))));

    return $extra + array(
        // An update waits for the writer to answer each line, as the poller's session does.
        'line_mode' => true,
        'options' => rrd_characterization_options(),
        'replies' => array('fetch' => " value\n\n1700000300: 1.0000000000e+00\n", 'last' => "1700000300\n", 'dump' => ''),
        'db' => array(
            array('sql' => 'SELECT name, data_source_path FROM data_template_data', 'params' => array(11), 'result' => array('name' => 'Traffic', 'data_source_path' => $stored)),
            array('sql' => 'SELECT dsp.step, dspr.steps, dspr.rows, dspr.timespan FROM data_source_profiles', 'result' => array(array('step' => '300', 'steps' => '1', 'rows' => '600', 'timespan' => '86400'))),
            array('sql' => 'LEFT JOIN data_source_profiles_cf AS dspc', 'result' => array(array('rrd_step' => '300', 'x_files_factor' => '0.5', 'steps' => '1', 'rows' => '600', 'consolidation_function_id' => '1', 'rra_order' => '600'))),
            array('sql' => 'SELECT data_template_id FROM data_local', 'result' => '0'),
            array('sql' => 'SELECT host_id, snmp_query_id, snmp_index FROM data_local', 'result' => array('host_id' => '3', 'snmp_query_id' => '1', 'snmp_index' => '2')),
            array('sql' => 'field_name="ifHighSpeed"', 'result' => ''),
            array('sql' => 'field_name="ifSpeed"', 'result' => ''),
            array('sql' => 'dtr.data_source_name, dtd.name FROM data_template_rrd', 'result' => array('data_source_name' => 'value', 'name' => 'Traffic')),
            array('sql' => 'FROM data_template_rrd AS dtr WHERE local_data_id', 'result' => array(
                array('id' => '301', 'data_source_name' => 'value', 'rrd_heartbeat' => '120', 'rrd_minimum' => 'U', 'rrd_maximum' => 'U', 'data_source_type_id' => '1'),
            )),
            array('sql' => 'field_name, field_value FROM host_snmp_cache', 'result' => array()),
        ),
        'calls' => array(
            // The first call also loads lib/boost.php for the Boost writer calls below.
            0 => array('fn' => 'rrdtool_function_fetch', 'args' => array(11, 1700000000, 1700001000, 300)),
            1 => array('fn' => 'rrdtool_function_info', 'args' => array(11)),
            2 => array('fn' => 'rrdtool_function_create', 'args' => array(11, true)),
            3 => array('fn' => 'get_rrd_cfs', 'args' => array(11)),
            4 => array('fn' => 'rrdtool_function_update', 'args' => array($update, false, array(), array())),
            5 => array('fn' => 'boost_rrdtool_get_last_update_time', 'args' => array($path, false)),
            6 => array('fn' => 'boost_rrdtool_function_update', 'args' => array(11, $path, 'value', ' 1700000600:5', false)),
            7 => array('fn' => 'boost_rrdtool_function_create', 'args' => array(11, false, false)),
            8 => array('fn' => 'rrd_rra_delete', 'args' => array(array($path), array(array('cf' => 'AVERAGE', 'pdp_per_row' => 1, 'xff' => 0.5, 'rows' => 600)), true)),
        ),
    );
}

/**
 * @param array<int, array<string, mixed>> $results
 *
 * @return array<int, int> the number of commands the fake RRDtool received, by call
 */
function rrd_containment_sent(array $results): array
{
    return array_map(static fn(array $result): int => count($result['sent']), $results);
}

test('a path inside the RRA directory reaches RRDtool through every data source sink', function () {
    $scenario = rrd_containment_scenario('<path_rra>/inside.rrd', '<fixture_rra>/inside.rrd', array('files' => array('inside.rrd')));
    $results = rrd_characterization_run($this, $scenario)['results'];
    $sent = rrd_containment_sent($results);

    // Create with show_source prints the command instead of sending it.
    expect($results[2]['returned'])->toContain('create')
        ->and($results[6]['returned'])->toBe('OK')
        // The file exists, so Boost stops after its path check instead of creating it again.
        ->and($results[7]['returned'])->toBe(-1);
    foreach (array(0, 1, 3, 4, 5, 6, 8) as $call) {
        expect($sent[$call])->toBeGreaterThan(0, 'call ' . $call);
    }
});

test('a path outside the RRA directory is refused by every data source sink', function (string $kind, array $extra, string $stored, string $path) {
    $scenario = rrd_containment_scenario($stored, $path, $extra + array('files' => array('inside.rrd'), 'directories' => array('outside')));
    $results = rrd_characterization_run($this, $scenario)['results'];

    expect(rrd_containment_sent($results))->toBe(array_fill(0, 9, 0), $kind)
        ->and($results[0]['returned'])->toBe(array())
        ->and($results[1]['returned'])->toBeFalse()
        ->and($results[2]['returned'])->toBeFalse()
        ->and($results[3]['returned'])->toBe(array())
        ->and($results[4]['returned'])->toBeFalse()
        // The poller consumes these samples and keeps them in the dead letter table.
        ->and(array_values($results[4]['args'][3]))->toBe(array('RRD path is outside the RRA directory'))
        ->and(array_values($results[4]['args'][2])[0])->toBe(array(1700000600 => false))
        ->and($results[6]['returned'])->toBe('ERROR: Invalid RRD path')
        ->and($results[7]['returned'])->toBeFalse()
        ->and($results[8]['returned'])->toHaveKey('err_msg');
})->with(array(
    'absolute path elsewhere' => array('outside', array(), '<fixture_dir>/outside/secret.rrd', '<fixture_dir>/outside/secret.rrd'),
    'sibling with the same prefix' => array('sibling', array('directories' => array('rra-other')), '<fixture_dir>/rra-other/secret.rrd', '<fixture_dir>/rra-other/secret.rrd'),
    'link leaving the RRA directory' => array('link', array('links' => array('rra/escape' => '<dir>/outside'), 'files' => array('escape/secret.rrd')), '<path_rra>/escape/secret.rrd', '<fixture_rra>/escape/secret.rrd'),
    'dangling link to a directory' => array('dangling', array('links' => array('rra/dangling' => '<dir>/not-yet')), '<path_rra>/dangling/new.rrd', '<fixture_rra>/dangling/new.rrd'),
    'parent segment through a missing directory' => array('dotdot', array(), '<path_rra>/missing/../../outside/secret.rrd', '<fixture_rra>/missing/../../outside/secret.rrd'),
));

test('a new structured path below the RRA directory is accepted before its directories exist', function () {
    $scenario = rrd_containment_scenario('<path_rra>/9/3/new.rrd', '<fixture_rra>/9/3/new.rrd');
    $scenario['calls'] = array_slice($scenario['calls'], 0, 3);
    $results = rrd_characterization_run($this, $scenario)['results'];

    expect($results[2]['returned'])->toContain('create ')
        ->and(rrd_containment_sent($results)[1])->toBeGreaterThan(0);
});

test('a graph reads only RRD files inside the RRA directory', function (string $stored, bool $allowed) {
    $graph = rrd_characterization_graph(array('title_cache' => 'Traffic', 'vertical_label' => 'bits'));
    $items = array(rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in') + array('hex' => '00CF00')));
    $scenario = array(
        'files' => array('inside.rrd'),
        'directories' => array('outside'),
        'rrd_cfs' => array('AVERAGE'),
        'options' => rrd_characterization_options(),
        'db' => rrd_characterization_graph_db($graph, $items),
        'calls' => array(array('fn' => 'rrdtool_function_graph', 'args' => array(7, 0, array(
            'graph_start' => 1700000000, 'graph_end' => 1700003600, 'output_filename' => '/dev/null', 'print_source' => true,
        ), false, array(), 0))),
    );
    foreach ($scenario['db'] as $index => $entry) {
        if (($entry['sql'] ?? '') === 'SELECT name, data_source_path FROM data_template_data') {
            $scenario['db'][$index]['result']['data_source_path'] = $stored;
        }
    }
    $output = rrd_characterization_run($this, $scenario)['results'][0];

    if ($allowed) {
        expect($output['printed'])->toContain('DEF:');
    } else {
        expect($output['returned'])->toContain('outside the RRA directory')->and($output['printed'])->not->toContain('DEF:');
    }
})->with(array(
    'inside' => array('<path_rra>/inside.rrd', true),
    'outside' => array('<fixture_dir>/outside/secret.rrd', false),
));

test('tuning changes only an RRD file inside the RRA directory', function (string $stored, string $path, array $extra, bool $allowed) {
    $tune = array('data_source_id' => 11, 'data-source-type' => '3', 'heartbeat' => '600', 'minimum' => 'U', 'maximum' => '', 'data-source-rename' => '');
    $scenario = rrd_containment_scenario($stored, $path, $extra + array('files' => array('inside.rrd'), 'directories' => array('outside')));
    $scenario['line_mode'] = false;
    // rrdtool_tune() chooses between text and HTML output by this constant, which the application defines at startup.
    $scenario['calls'] = array(
        array('fn' => 'define', 'args' => array('CACTI_CLI', true)),
        array('fn' => 'rrdtool_function_tune', 'args' => array($tune)),
        array('fn' => 'rrdtool_tune', 'args' => array($path, array('tune' => array($path . ' --heartbeat value:600')), false)),
    );
    $results = rrd_characterization_run($this, $scenario)['results'];

    // The writer logs one entry per tune it ran, and none for a file it was not given.
    expect(count($results[1]['sent']))->toBe($allowed ? 1 : 0)
        ->and(count($results[2]['sent']))->toBe($allowed ? 1 : 0)
        ->and($results[2]['returned'])->toBe($allowed ? null : false);
})->with(array(
    'inside' => array('<path_rra>/inside.rrd', '<fixture_rra>/inside.rrd', array(), true),
    'outside' => array('<fixture_dir>/outside/secret.rrd', '<fixture_dir>/outside/secret.rrd', array('files' => array('../outside/secret.rrd')), false),
    'link leaving the RRA directory' => array('<path_rra>/escape/secret.rrd', '<fixture_rra>/escape/secret.rrd', array('links' => array('rra/escape' => '<dir>/outside'), 'files' => array('escape/secret.rrd')), false),
));
