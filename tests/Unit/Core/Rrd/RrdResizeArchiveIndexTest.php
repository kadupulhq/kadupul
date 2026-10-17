<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

/** @return array<string, mixed> */
function rrd_resize_index_scenario(int $target, int $change, bool $reversed): array
{
    $profile = [
        ['cf' => 1, 'xff' => '0.5', 'heartbeat' => 600, 'steps' => 1, 'rows' => 10000],
        ['cf' => 1, 'xff' => '0.5', 'heartbeat' => 600, 'steps' => 100, 'rows' => 50],
    ];
    $archives = array_map(static fn(array $rra): array => ['cf' => 'AVERAGE', 'pdp_per_row' => $rra['steps'], 'rows' => $rra['rows'], 'xff' => $rra['xff']], $profile);
    $profile[$target]['rows'] += $change;

    return [
        'options' => rrd_characterization_options(),
        'db' => [
            ['sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => ['name' => 'Archive order', 'data_source_path' => '<path_rra>/sample.rrd']],
            ['sql' => 'local_data_template_data_id, rrd_step, data_source_profile_id', 'result' => ['local_data_template_data_id' => 0, 'rrd_step' => 300, 'data_source_profile_id' => 1]],
            ['sql' => 'SELECT data_template_id', 'result' => 0],
            ['sql' => 'SELECT DISTINCT dtr.id, dtr.data_source_name', 'result' => [['id' => 1, 'data_source_name' => 'value', 'data_source_type_id' => 1, 'rrd_heartbeat' => 600, 'rrd_minimum' => '0', 'rrd_maximum' => '100']]],
            ['sql' => 'dspc.consolidation_function_id AS cf', 'result' => $profile],
            ['sql' => 'SELECT host_id, snmp_query_id, snmp_index', 'result' => ['host_id' => 3, 'snmp_query_id' => 1, 'snmp_index' => '2']],
            ['sql' => 'field_name="ifHighSpeed"', 'result' => ''],
            ['sql' => 'field_name="ifSpeed"', 'result' => ''],
            ['sql' => 'SELECT heartbeat', 'result' => 600],
        ],
        'info' => [
            'filename' => 'sample.rrd',
            'step' => 300,
            'ds' => ['value' => ['type' => 'GAUGE', 'minimal_heartbeat' => 600, 'min' => '0', 'max' => '100']],
            'rra' => $reversed ? array_reverse($archives) : $archives,
        ],
    ];
}

/** @return array<string, mixed> */
function rrd_resize_index_compare(TestCase $test, array $scenario): array
{
    $scenario['calls'] = [['fn' => 'rrdtool_cacti_compare', 'args' => [11, $scenario['info']]]];
    $result = rrd_characterization_run($test, $scenario)['results'][0];
    expect($result['diagnostics'])->toBe([])->and($result['sent'])->toBe([]);

    return $result['returned'];
}

test('resize recommendations use the matched file archive in either order', function (int $target, int $change, bool $reversed): void {
    $diff = rrd_resize_index_compare($this, rrd_resize_index_scenario($target, $change, $reversed));
    if ($change === 0) {
        expect($diff)->toBe([]);

        return;
    }
    $fileIndex = $reversed ? 1 - $target : $target;
    $direction = $change > 0 ? 'GROW' : 'SHRINK';
    expect($diff['resize'])->toBe(['sample.rrd ' . $fileIndex . ' ' . $direction . ' ' . abs($change)])
        ->and(array_keys($diff['rra']))->toBe([$fileIndex]);
})->with([0, 1])->with([-10, 0, 10])->with([false, true]);

test('duplicate profile or file archive identities still suppress resizing', function (string $duplicate): void {
    $scenario = rrd_resize_index_scenario(0, 10, true);
    if ($duplicate === 'profile') {
        $scenario['db'][4]['result'][1]['steps'] = 1;
    } else {
        $scenario['info']['rra'][0]['pdp_per_row'] = 1;
    }
    $diff = rrd_resize_index_compare($this, $scenario);

    expect($diff)->not->toHaveKey('resize')->and($diff['rra'])->not->toBeEmpty();
})->with(['profile', 'file']);

test('the tuning report prints the matched file archive', function (int $change): void {
    $diff = rrd_resize_index_compare($this, rrd_resize_index_scenario(0, $change, true));
    $output = rrd_characterization_run($this, [
        'options' => rrd_characterization_options(),
        'calls' => [
            ['fn' => 'define', 'args' => ['CACTI_CLI', true]],
            ['fn' => 'rrdtool_tune', 'args' => ['sample.rrd', $diff, true]],
        ],
    ])['results'][1];
    $direction = $change > 0 ? 'GROW' : 'SHRINK';
    expect($output['printed'])->toContain(' resize sample.rrd 1 ' . $direction . ' 10')
        ->and($output['sent'])->toBe([])->and($output['diagnostics'])->toBe([]);
})->with([-10, 10]);

test('actual RRDtool resize advice changes only the intended archive', function (int $change): void {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (new ExecutableFinder())->find('rrdtool');
    if ($binary === null) {
        $this->markTestSkipped('RRDtool is unavailable.');
    }
    $directory = sys_get_temp_dir() . '/rrd-resize-index-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $file = $directory . '/sample.rrd';
    try {
        $create = new Process([$binary, 'create', $file, '--start', '1700000000', '--step', '300', 'DS:value:GAUGE:600:0:100', 'RRA:AVERAGE:0.5:100:50', 'RRA:AVERAGE:0.5:1:10000'], $directory, ['RRDCACHED_ADDRESS' => false]);
        $create->setTimeout(10);
        $create->mustRun();
        $original = hash_file('sha256', $file);
        expect($original)->not->toBeFalse();
        $scenario = rrd_resize_index_scenario(0, $change, true);
        $scenario['options']['path_rrdtool'] = $binary;
        $scenario['db'][0]['result']['data_source_path'] = $file;
        $scenario['calls'] = [['fn' => 'rrdtool_function_info', 'args' => [11]]];
        $scenario['info'] = rrd_characterization_run($this, $scenario)['results'][0]['returned'];
        $diff = rrd_resize_index_compare($this, $scenario);
        $direction = $change > 0 ? 'GROW' : 'SHRINK';
        expect($diff['resize'])->toBe([$file . ' 1 ' . $direction . ' 10']);
        expect(preg_match('/\A(.+) ([0-9]+) (GROW|SHRINK) ([0-9]+)\z/', $diff['resize'][0], $arguments))->toBe(1);
        $resize = new Process([$binary, 'resize', ...array_slice($arguments, 1)], $directory, ['RRDCACHED_ADDRESS' => false]);
        $resize->setTimeout(10);
        $resize->mustRun();
        $scenario['db'][0]['result']['data_source_path'] = $directory . '/resize.rrd';
        $result = rrd_characterization_run($this, $scenario)['results'][0];

        expect($result['diagnostics'])->toBe([])
            ->and($result['returned']['rra'][0]['rows'])->toBe('50')
            ->and($result['returned']['rra'][1]['rows'])->toBe((string) (10000 + $change))
            ->and(hash_file('sha256', $file))->toBe($original);
    } finally {
        foreach ([$file, $directory . '/resize.rrd'] as $owned) {
            if (is_file($owned)) {
                unlink($owned);
            }
        }
        rmdir($directory);
    }
})->with([-10, 10]);
