<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

test('RRD comparison preserves saved heartbeats and recommends only file changes', function (int $stored, int $file, int $profile): void {
    $items = [
        ['id' => 1, 'data_source_name' => 'in', 'data_source_type_id' => 1, 'rrd_heartbeat' => $stored, 'rrd_minimum' => '0', 'rrd_maximum' => '100'],
        ['id' => 2, 'data_source_name' => 'out', 'data_source_type_id' => 1, 'rrd_heartbeat' => $profile, 'rrd_minimum' => '0', 'rrd_maximum' => '100'],
    ];
    $info = [
        'filename' => 'sample.rrd', 'step' => 300,
        'ds' => [
            'in' => ['type' => 'GAUGE', 'minimal_heartbeat' => $file, 'min' => '0', 'max' => '100'],
            'out' => ['type' => 'GAUGE', 'minimal_heartbeat' => $profile, 'min' => '0', 'max' => '100'],
        ],
        'rra' => [['cf' => 'AVERAGE', 'pdp_per_row' => 1, 'rows' => 100, 'xff' => '0.5']],
    ];
    $scenario = [
        'options' => rrd_characterization_options(),
        // The native harness refuses every database write and unexpected read.
        'db' => [
            ['sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => ['name' => 'Heartbeat comparison', 'data_source_path' => '<path_rra>/sample.rrd']],
            ['sql' => 'local_data_template_data_id, rrd_step, data_source_profile_id', 'result' => ['local_data_template_data_id' => 0, 'rrd_step' => 300, 'data_source_profile_id' => 1]],
            ['sql' => 'SELECT data_template_id', 'result' => 0],
            ['sql' => 'SELECT DISTINCT dtr.id, dtr.data_source_name', 'result' => $items],
            ['sql' => 'dspc.consolidation_function_id AS cf', 'result' => [['cf' => 1, 'xff' => '0.5', 'heartbeat' => $profile, 'steps' => 1, 'rows' => 100]]],
            ['sql' => 'SELECT host_id, snmp_query_id, snmp_index', 'result' => ['host_id' => 3, 'snmp_query_id' => 1, 'snmp_index' => '2']],
            ['sql' => 'field_name="ifHighSpeed"', 'result' => ''],
            ['sql' => 'field_name="ifSpeed"', 'result' => ''],
            ['sql' => 'SELECT heartbeat', 'result' => $profile],
        ],
        'calls' => [
            ['fn' => 'rrdtool_cacti_compare', 'args' => [11, $info]],
            ['fn' => 'rrdtool_cacti_compare', 'args' => [11, $info]],
        ],
    ];
    $results = rrd_characterization_run($this, $scenario)['results'];
    foreach ($results as $result) {
        expect($result['diagnostics'])->toBe([])->and($result['sent'])->toBe([]);
        if ($stored === $file) {
            expect($result['returned'])->toBe([]);
        } else {
            expect($result['returned'])->toBe([
                'ds' => ['in' => ['minimal_heartbeat' => "Heartbeat for Data Source 'in' should be '$stored'"]],
                'tune' => ["sample.rrd --heartbeat in:$stored"],
            ]);
        }
        expect($result['args'][1]['ds']['in']['minimal_heartbeat'])->toBe($file);
    }
})->with([
    'custom saved heartbeat, matching file' => [900, 900, 600],
    'custom saved heartbeat, file uses profile' => [900, 600, 600],
    'custom saved heartbeat, file differs from both' => [900, 300, 600],
    'saved profile heartbeat, different file' => [600, 300, 600],
    'all heartbeats match' => [600, 600, 600],
    'smaller custom heartbeat, matching file' => [120, 120, 600],
]);
