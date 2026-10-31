<?php

declare(strict_types=1);
/*
 * Copyright (C) 2004-2026 The Cacti Group
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$unknownRoot = dirname(__DIR__, 4);
$unknownStats = file_get_contents($unknownRoot . '/lib/graph_variables.php');
$unknownFunctions = file_get_contents($unknownRoot . '/lib/functions.php');
if ($unknownStats === false || $unknownFunctions === false) throw new RuntimeException('Cannot read production statistics');
$unknownExtracted = test_php_function_source($unknownFunctions, 'cacti_sizeof');
foreach (array('cacti_percentile_index', 'cacti_stats_calc', 'nth_percentile_fetch_statistics') as $unknownFunction) {
    $unknownExtracted .= test_php_function_source($unknownStats, $unknownFunction);
}
eval('namespace UnknownStatisticsRegression; ' . $unknownExtracted);

test('aggregation excludes unknown and non-finite samples while retaining zero', function (mixed $unknown): void {
    $ids = array(11 => array('value'), 12 => array('value'));
    $fetch = array(
        11 => array('data_source_names' => array('value'), 'values' => array(array(300 => $unknown, 600 => '0', 900 => '10'))),
        12 => array('data_source_names' => array('value'), 'values' => array(array(300 => $unknown, 600 => '0', 900 => '20'))),
    );
    $stats = UnknownStatisticsRegression\nth_percentile_fetch_statistics(95, $ids, $fetch, 'AVERAGE');
    foreach (array('value', 'nth_percentile_maximum', 'nth_percentile_sum') as $name) {
        expect($stats['stats_' . $name]['elements'])->toBe(2)
            ->and((float) $stats['stats_' . $name]['sum'])->toBe(30.0)
            ->and((float) $stats['stats_' . $name]['average'])->toBe(15.0)
            ->and((float) $stats[$name])->toBe(30.0);
    }
})->with(array('U' => array('U'), 'NaN text' => array('NaN'), 'negative NaN text' => array('-nan'), 'NaN float' => array(NAN), 'infinity' => array(INF), 'negative infinity' => array(-INF)));

test('valid contributions survive another source being unknown at the same timestamp', function (): void {
    $ids = array(11 => array('value'), 12 => array('value'));
    $fetch = array(
        11 => array('data_source_names' => array('value'), 'values' => array(array(300 => 'U', 600 => '0', 900 => '10'))),
        12 => array('data_source_names' => array('value'), 'values' => array(array(300 => '5', 600 => '0', 900 => '20'))),
    );
    $stats = UnknownStatisticsRegression\nth_percentile_fetch_statistics(95, $ids, $fetch, 'AVERAGE');
    expect($stats['stats_value']['elements'])->toBe(3)
        ->and((float) $stats['stats_value']['sum'])->toBe(35.0)
        ->and((float) $stats['stats_value']['average'])->toBe(35 / 3)
        ->and((float) $stats['value'])->toBe(30.0);
});

test('already filtered numeric input retains existing statistics', function (): void {
    $ids = array(11 => array('value'));
    $fetch = array(11 => array('data_source_names' => array('value'), 'values' => array(array(300 => '0', 600 => '10', 900 => '20'))));
    $stats = UnknownStatisticsRegression\nth_percentile_fetch_statistics(95, $ids, $fetch, 'MAX');
    expect($stats['stats_value']['elements'])->toBe(3)
        ->and((float) $stats['stats_value']['sum'])->toBe(30.0)
        ->and((float) $stats['stats_value']['average'])->toBe(10.0)
        ->and((float) $stats['value'])->toBe(20.0);
});

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

test('native unknown-preserving fetch data passes through the full statistics helper', function (bool $allUnknown): void {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: '/usr/bin/rrdtool';
    if (!is_executable($binary)) $this->markTestSkipped('Native RRDtool is required');
    $start = 1699999800;
    $path = '<fixture_rra>/unknown.rrd';
    $values = $allUnknown ? ['U', 'U', 'U', 'U'] : ['0', 'U', '10', '20'];
    $updates = [$start . ':0'];
    foreach ($values as $index => $value) $updates[] = ($start + ($index + 1) * 300) . ':' . $value;
    $scenario = [
        'options' => ['path_rrdtool' => $binary, 'boost_rrd_update_enable' => ''] + rrd_characterization_options(),
        'require' => ['lib/graph_variables.php'],
        'db' => [['sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => ['name' => 'Unknown samples', 'data_source_path' => '<path_rra>/unknown.rrd']]],
        'calls' => [
            ['fn' => 'rrdtool_execute', 'args' => [['create', $path, '--start', $start - 600, '--step', '300', 'DS:value:GAUGE:300:U:U', 'RRA:AVERAGE:0.5:1:100'], false, 1]],
            ['fn' => 'rrdtool_execute', 'args' => [array_merge(['update', $path], $updates), false, 1]],
            ['fn' => 'rrdtool_function_fetch', 'args' => [11, $start, $start + 1200, 300, true]],
        ],
    ];
    $result = rrd_characterization_run($this, $scenario)['results'];
    foreach ($result as $call) expect($call['diagnostics'])->toBe([]);
    $fetched = $result[2]['returned'];
    expect(in_array('U', $fetched['values'][0], true))->toBeTrue();
    $handoff = [
        'options' => rrd_characterization_options(),
        'require' => ['lib/graph_variables.php'],
        'calls' => [['fn' => 'nth_percentile_fetch_statistics', 'args' => [95, [11 => ['value']], [11 => $fetched], 'AVERAGE']]],
    ];
    $outcome = rrd_characterization_run($this, $handoff)['results'][0];
    expect($outcome['diagnostics'])->toBe([]);
    $stats = $outcome['returned'];
    if ($allUnknown) {
        expect($stats)->not->toHaveKey('stats_value');
        expect($stats['stats_nth_percentile_sum']['elements'])->toBe(0)
            ->and($stats['nth_percentile_sum'])->toBe(0);
    } else {
        expect($stats['stats_value']['elements'])->toBe(3)
            ->and((float) $stats['stats_value']['sum'])->toBe(30.0)
            ->and((float) $stats['stats_value']['average'])->toBe(10.0);
    }
})->with(['mixed measured and unknown' => [false], 'unknown-only window' => [true]]);
