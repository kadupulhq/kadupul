<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use Symfony\Component\Process\ExecutableFinder;

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';
require_once dirname(__DIR__, 3) . '/Helpers/GraphXport.php';

$observedWindowCases = [];
foreach ([0, 1, 20, 11000] as $count) {
    foreach (['fetch', 'export'] as $operation) {
        foreach ([0, 120] as $offset) {
            $observedWindowCases[$operation . ' N=' . $count . ' offset=' . $offset] = [$count, $operation, $offset, false, false];
        }
    }
}
$observedWindowCases['selected five-minute archive with one-minute samples available'] = [3, 'export', 0, true, false];
$observedWindowCases['relative export uses the captured application clock'] = [20, 'export', 120, false, true];

test('native fetch and graph export retain the selected observed samples', function (int $count, string $operation, int $offset, bool $finerArchive, bool $relative): void {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (new ExecutableFinder())->find('rrdtool');
    if (!$binary || !is_executable($binary)) {
        $this->markTestSkipped('Native RRDtool is required.');
    }

    $sampleStart = 1699999800;
    $sampleEnd = $sampleStart + $count * 300;
    $start = $sampleStart + $offset;
    $end = $sampleEnd + $offset + ($count === 0 ? 60 : 0);
    $path = '<fixture_rra>/router_traffic_11.rrd';
    $baseStep = $finerArchive ? 60 : 300;
    $archiveSteps = intdiv(300, $baseStep);
    $updates = [($sampleStart - $baseStep) . ':-1', $sampleStart . ':0'];
    for ($index = 1; $index <= $count + 1; $index++) {
        for ($sample = 1; $sample <= $archiveSteps; $sample++) {
            $updates[] = ($sampleStart + ($index - 1) * 300 + $sample * $baseStep) . ':' . ($index <= $count ? $index : 100000);
        }
    }
    $archives = ['RRA:AVERAGE:0.5:' . $archiveSteps . ':' . ($count + 10)];
    if ($finerArchive) {
        array_unshift($archives, 'RRA:AVERAGE:0.5:1:' . (($count + 10) * $archiveSteps));
    }
    $graph = rrd_characterization_graph(['title_cache' => 'Observed samples', 'vertical_label' => 'value']);
    $items = [rrd_characterization_item(1, 'LINE1', rrd_characterization_ds('traffic_in') + ['hex' => '3366CC', 'text_format' => 'Samples'])];
    $database = rrd_characterization_graph_db($graph, $items);
    if ($finerArchive) {
        foreach ($database as &$query) {
            if (str_contains($query['sql'], 'FROM data_source_profiles') || str_contains($query['sql'], 'SELECT DISTINCT dspr.id')) {
                $query['result'][0]['step'] = $baseStep;
                $query['result'][0]['steps'] = $archiveSteps;
                if (isset($query['result'][0]['rrd_step'])) {
                    $query['result'][0]['rrd_step'] = $baseStep;
                }
            } elseif (str_starts_with($query['sql'], 'SELECT rrd_step FROM')) {
                $query['result'] = (string) $baseStep;
            }
        }
        unset($query);
    }
    $scenario = [
        'coverage_contract' => 'rrd-observed-window',
        'options' => ['path_rrdtool' => $binary, 'boost_rrd_update_enable' => '', 'font_method' => '0'] + rrd_characterization_options(),
        'db' => $database,
        'calls' => [
            ['fn' => 'rrdtool_execute', 'args' => [array_merge(['create', $path, '--start', $sampleStart - 600, '--step', $baseStep, 'DS:traffic_in:GAUGE:600:U:U'], $archives), false, 1]],
            ['fn' => 'rrdtool_execute', 'args' => [array_merge(['update', $path], $updates), false, 1]],
            $operation === 'fetch'
                ? ['fn' => 'rrdtool_function_fetch', 'args' => [11, $start, $end, 300]]
                : ['fn' => 'rrdtool_function_xport', 'args' => [7, 0, ['graph_start' => $start, 'graph_end' => $end, 'export_csv' => true], [], 0]],
        ],
    ];
    if ($relative) {
        $clockTime = $end + 3600;
        $scenario['calls'][2]['args'][2]['graph_start'] = $start - $clockTime;
        $scenario['calls'][2]['args'][2]['graph_end'] = $end - $clockTime;
        $scenario['calls'][2]['clock_time'] = $clockTime;
        $scenario['calls'][2]['clock_argument'] = 5;
    }
    $results = rrd_characterization_run($this, $scenario)['results'];
    foreach ($results as $result) {
        expect($result['diagnostics'])->toBe([]);
    }
    $observed = $results[2]['returned'];
    if ($operation === 'fetch') {
        if ($count === 0) {
            expect($observed)->not->toHaveKey('values');
            return;
        }
        $values = array_values($observed['values'][0]);
        expect((int) $observed['timestamp']['start_time'])->toBe($sampleStart + 300)
            ->and((int) $observed['timestamp']['end_time'])->toBe($sampleEnd);
    } else {
        expect((int) $observed['meta']['rows'])->toBe($count)
            ->and((int) $observed['meta']['step'])->toBe(300)
            ->and((int) $observed['meta']['start'])->toBe($count === 0 ? $start : $sampleStart + 300)
            ->and((int) $observed['meta']['end'])->toBe($count === 0 ? $end : $sampleEnd);
        $values = array_column($observed['data'], 'col1');
        [$csv] = \Kadupul\Tests\GraphXport\runExport($observed['meta'], $observed['data']);
        $rows = \Kadupul\Tests\GraphXport\parseCsv($csv);
        $header = array_search(['Date', 'Samples'], $rows, true);
        expect($header)->not->toBeFalse();
        $csvData = array_slice($rows, $header + 1);
        expect($csvData)->toHaveCount($count)
            ->and(array_map('floatval', array_column($csvData, 1)))->toBe(array_map('floatval', $values));
        $summary = array_column(array_slice($rows, 0, 8), 1, 0);
        expect((int) $summary['Total Rows'])->toBe($count);
    }
    expect(array_map('floatval', $values))->toBe(($count === 0 ? [] : array_map('floatval', range(1, $count))));
})->with($observedWindowCases);

test('relative numeric fetch bounds share one instant for command and parsing', function (): void {
    $time = time();
    $scenario = [
        'coverage_contract' => 'rrd-observed-window',
        'options' => ['boost_rrd_update_enable' => ''] + rrd_characterization_options(),
        'replies' => ['fetch' => " value\n\n" . ($time - 600) . ": 9\n" . ($time - 300) . ": 10\n" . $time . ": 100000\n"],
        'db' => [
            ['sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => ['name' => 'Window', 'data_source_path' => '<path_rra>/missing.rrd']],
            ['sql' => 'SELECT dsp.step, dspr.steps, dspr.rows, dspr.timespan FROM data_source_profiles', 'result' => [['step' => 300, 'steps' => 1, 'rows' => 1000, 'timespan' => 86400]]],
        ],
        'calls' => [['fn' => 'rrdtool_function_fetch', 'args' => [11, -600, -300, 0]]],
    ];
    $result = rrd_characterization_run($this, $scenario)['results'][0];
    expect($result['diagnostics'])->toBe([]);
    expect(preg_match("/'-s' '([0-9]+)' '-e' '([0-9]+)'/", $result['sent'][0]['stdin'], $bounds))->toBe(1);
    expect((int) $bounds[2] - (int) $bounds[1])->toBe(300)
        ->and((int) $bounds[2])->toBeGreaterThanOrEqual($result['clock'][0] - 300)
        ->and((int) $bounds[2])->toBeLessThanOrEqual($result['clock'][1] - 300)
        ->and($result['returned']['values'][0])->toBe([$time - 600 => '9', $time - 300 => '10']);
});

test('fetch retains existing RRDtool time expressions', function (): void {
    $scenario = [
        'coverage_contract' => 'rrd-observed-window',
        'options' => ['boost_rrd_update_enable' => ''] + rrd_characterization_options(),
        'replies' => ['fetch' => " value\n\n1700000300: 9\n"],
        'db' => [['sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => ['name' => 'Window', 'data_source_path' => '<path_rra>/missing.rrd']]],
        'calls' => [['fn' => 'rrdtool_function_fetch', 'args' => [11, 'end-1h', 'now', 300]]],
    ];
    $result = rrd_characterization_run($this, $scenario)['results'][0];
    expect($result['diagnostics'])->toBe([])
        ->and($result['sent'][0]['stdin'])->toContain("'-s' 'end-1h' '-e' 'now'")
        ->and($result['returned']['values'][0])->toBe([1700000300 => '9']);
});
