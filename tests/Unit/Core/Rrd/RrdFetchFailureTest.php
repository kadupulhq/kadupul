<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Symfony\Component\Process\ExecutableFinder;

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

/** @return array<string, mixed> */
function rrd_fetch_failure_scenario(string $reply, string $name = 'value'): array
{
    return [
        'options' => ['boost_rrd_update_enable' => ''] + rrd_characterization_options(),
        'require' => ['lib/graph_variables.php'],
        'rrd_cfs' => ['AVERAGE', 'MAX'],
        'replies' => ['fetch' => $reply],
        'db' => [
            ['sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => ['name' => 'Fetch', 'data_source_path' => '<path_rra>/missing.rrd']],
        ],
        'calls' => [
            ['fn' => 'rrdtool_function_fetch', 'args' => [11, 1700000000, 1700001000, 300]],
            ['fn' => 'rrdtool_function_stats', 'args' => [[11 => [$name]], 1700000000, 1700001000, 95, 300]],
            ['fn' => 'bandwidth_summation', 'args' => [11, 1700000000, 1700001000, 1, 300]],
        ],
    ];
}

test('failed fetches produce neither data source headers nor zero statistics', function (string $reply, bool $unknown): void {
    $scenario = rrd_fetch_failure_scenario($reply);
    $scenario['calls'][0]['args'][] = $unknown;
    $results = rrd_characterization_run($this, $scenario)['results'];

    expect($results[0]['returned'])->toBe([])
        ->and($results[1]['returned'])->toBe('[]')
        ->and($results[2]['returned'])->toBeNull();
    foreach ($results as $result) {
        expect($result['diagnostics'])->toBe([]);
    }
})->with([
    'missing file LF' => "ERROR: opening 'missing.rrd': No such file or directory\n",
    'missing file CRLF' => "ERROR: opening 'missing.rrd': No such file or directory\r\n",
    'no final newline' => "ERROR: opening 'missing.rrd': No such file or directory",
    'leading whitespace' => "\r\n \tERROR: unknown consolidation function\r\n",
    'multiline diagnostic' => "ERROR: could not read RRD\ninvalid header\n",
])->with([false, true]);

test('successful fetches preserve genuine zero and nonzero statistics', function (string $name, string $value): void {
    $scenario = rrd_fetch_failure_scenario(" $name\n\n1700000300: $value\n1700000600: $value\n", $name);
    $results = rrd_characterization_run($this, $scenario)['results'];
    $stats = json_decode($results[1]['returned'], true, 512, JSON_THROW_ON_ERROR);

    expect($results[0]['returned']['data_source_names'])->toBe([$name])
        ->and($results[0]['returned']['values'][0])->toBe([1700000300 => $value, 1700000600 => $value]);
    foreach (['avg', 'peak'] as $cf) {
        expect($stats[$cf]['nth_percentile_aggregate_total'])->toEqual((float) $value)
            ->and($stats[$cf]['stats_' . $name]['elements'])->toBe(2);
    }
    expect($results[2]['returned'][$name])->toEqual((float) $value * 600);
    foreach ($results as $result) {
        expect($result['diagnostics'])->toBe([]);
    }
})->with([
    'zero' => ['value', '0'],
    'nonzero' => ['value', '2.5'],
    'ERROR is a valid source name' => ['ERROR', '0'],
]);

test('an actual missing RRD is rejected by the complete local fetch path', function (): void {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (new ExecutableFinder())->find('rrdtool');
    if ($binary === null) {
        $this->markTestSkipped('RRDtool is unavailable.');
    }
    expect(is_file($binary) && is_executable($binary))->toBeTrue();
    $scenario = rrd_fetch_failure_scenario('');
    $scenario['options']['path_rrdtool'] = $binary;
    $scenario['calls'] = [$scenario['calls'][0]];
    $result = rrd_characterization_run($this, $scenario)['results'][0];

    expect($result['returned'])->toBe([])->and($result['diagnostics'])->toBe([]);
});
