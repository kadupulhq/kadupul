<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs the shipped lib/rrd.php in the characterization child, so its coverage
 * counts for the changed lines: $config['rrd_additional_paths'] as read by
 * rrd_additional_roots() and honored by rrd_check_path(), and the upgrade
 * report rrd_data_sources_outside_allowed_paths().
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

const RRD_OVERRIDE_SCAN_SQL = "SELECT local_data_id, data_source_path FROM data_template_data WHERE local_data_id > ? AND data_source_path != '' ORDER BY local_data_id LIMIT 1000";

function rrd_override_run($test, array $calls, array $db = []): array
{
    $scenario = [
        'coverage_contract' => 'rrd-observed-window',
        'options' => ['boost_rrd_update_enable' => ''] + rrd_characterization_options(),
        'directories' => ['extra/5', 'other'],
        'links' => ['extra/away' => '<dir>/other'],
        'files' => ['7.rrd'],
        'db' => $db,
        'calls' => $calls,
    ];
    $results = rrd_characterization_run($test, $scenario)['results'];
    foreach ($results as $result) {
        expect($result['diagnostics'])->toBe([]);
    }

    return array_column($results, 'returned');
}

test('a directory listed in rrd_additional_paths is accepted by the shipped rrd_check_path()', function (): void {
    $listed = ['rrd_additional_paths' => ['<fixture_dir>/extra', 'relative', 7]];
    $returned = rrd_override_run($this, [
        ['fn' => 'rrd_additional_roots', 'args' => [], 'config' => $listed],
        ['fn' => 'rrd_check_path', 'args' => ['<fixture_dir>/extra/5/12.rrd']],
        ['fn' => 'rrd_check_path', 'args' => ['<fixture_dir>/extra/9/new.rrd']],
        ['fn' => 'rrd_check_path', 'args' => ['<fixture_dir>/extra/away/12.rrd']],
        ['fn' => 'rrd_check_path', 'args' => ['<fixture_dir>/other/12.rrd']],
        ['fn' => 'rrd_check_path', 'args' => ['<fixture_dir>/extra/5/12.rrd', '<fixture_rra>']],
        ['fn' => 'rrd_check_path', 'args' => ['<fixture_rra>/7.rrd']],
        ['fn' => 'rrd_additional_roots', 'args' => [], 'config' => ['rrd_additional_paths' => '<fixture_dir>/extra']],
        ['fn' => 'rrd_check_path', 'args' => ['<fixture_dir>/extra/5/12.rrd']],
    ]);

    expect($returned[0])->toBe(['<DIR>/extra'])
        ->and(array_slice($returned, 1, 6))->toBe([true, true, false, false, false, true])
        ->and($returned[7])->toBe([])
        ->and($returned[8])->toBeFalse();
});

test('the upgrade report lists the shipped refusals and tells a failed read from none', function (): void {
    $rows = [
        ['local_data_id' => '3', 'data_source_path' => '<path_rra>/7.rrd'],
        ['local_data_id' => '4', 'data_source_path' => '<fixture_dir>/other/4.rrd'],
        ['local_data_id' => '5', 'data_source_path' => 'localhost_5.rrd'],
        ['local_data_id' => '8', 'data_source_path' => '<fixture_dir>/extra/away/8.rrd'],
    ];
    $returned = rrd_override_run($this, [
        ['fn' => 'rrd_data_sources_outside_allowed_paths', 'args' => [1]],
        ['fn' => 'rrd_data_sources_outside_allowed_paths', 'args' => [25], 'config' => ['rrd_additional_paths' => ['<fixture_dir>/other']]],
    ], [['sql' => RRD_OVERRIDE_SCAN_SQL, 'params' => [0], 'result' => $rows]]);

    expect($returned[0])->toBe(['total' => 2, 'items' => [['local_data_id' => 4, 'path' => '<DIR>/other/4.rrd']]])
        ->and($returned[1])->toBe(['total' => 0, 'items' => []]);

    $failed = rrd_override_run($this, [
        ['fn' => 'rrd_data_sources_outside_allowed_paths', 'args' => [25]],
    ], [['sql' => RRD_OVERRIDE_SCAN_SQL, 'params' => [0], 'result' => [], 'error' => 'DB Row Failed!, Error 2006']]);

    expect($failed[0])->toBeFalse();
});
