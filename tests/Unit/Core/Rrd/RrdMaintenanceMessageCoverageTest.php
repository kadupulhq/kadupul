<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs the shipped lib/rrd.php in the characterization child, so its coverage
 * counts for the maintenance messages: the row-count difference that
 * rrdtool_cacti_compare() reports before a resize, and the refusal of
 * rrd_xml_transform() when the RRDfile is not writable.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

test('the compare report names the stored and wanted row counts', function (): void {
    $profile = [['cf' => 1, 'xff' => '0.5', 'heartbeat' => 600, 'steps' => 1, 'rows' => 10010]];
    $result = rrd_characterization_run($this, [
        'coverage_contract' => 'rrd-observed-window',
        'options' => rrd_characterization_options(),
        'db' => [
            ['sql' => 'SELECT name, data_source_path FROM data_template_data', 'result' => ['name' => 'Rows', 'data_source_path' => '<path_rra>/sample.rrd']],
            ['sql' => 'local_data_template_data_id, rrd_step, data_source_profile_id', 'result' => ['local_data_template_data_id' => 0, 'rrd_step' => 300, 'data_source_profile_id' => 1]],
            ['sql' => 'SELECT data_template_id', 'result' => 0],
            ['sql' => 'SELECT DISTINCT dtr.id, dtr.data_source_name', 'result' => [['id' => 1, 'data_source_name' => 'value', 'data_source_type_id' => 1, 'rrd_heartbeat' => 600, 'rrd_minimum' => '0', 'rrd_maximum' => '100']]],
            ['sql' => 'dspc.consolidation_function_id AS cf', 'result' => $profile],
            ['sql' => 'SELECT host_id, snmp_query_id, snmp_index', 'result' => ['host_id' => 3, 'snmp_query_id' => 1, 'snmp_index' => '2']],
            ['sql' => 'field_name="ifHighSpeed"', 'result' => ''],
            ['sql' => 'field_name="ifSpeed"', 'result' => ''],
            ['sql' => 'SELECT heartbeat', 'result' => 600],
        ],
        'calls' => [['fn' => 'rrdtool_cacti_compare', 'args' => [11, [
            'filename' => 'sample.rrd',
            'step' => 300,
            'ds' => ['value' => ['type' => 'GAUGE', 'minimal_heartbeat' => 600, 'min' => '0', 'max' => '100']],
            'rra' => [['cf' => 'AVERAGE', 'pdp_per_row' => 1, 'rows' => 10000, 'xff' => '0.5']],
        ]]]],
    ])['results'][0];

    expect($result['diagnostics'])->toBe([])
        ->and($result['returned']['rra'][0]['rows'])->toBe("The number of rows for Kadupul RRA id '0' is incorrect.  The number of rows is '10000' but should be '10010'")
        ->and($result['returned']['resize'])->toBe(['sample.rrd 0 GROW 10']);
});

test('a transformation refuses a read-only RRDfile', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root can write a read-only file');
    }
    $result = rrd_characterization_run($this, [
        'coverage_contract' => 'rrd-observed-window',
        'line_mode' => true,
        'options' => rrd_characterization_options(),
        'files' => ['7.rrd'],
        'replies' => ['dump' => "<rrd><version>0003</version><step>300</step></rrd>\n"],
        'calls' => [
            ['fn' => 'chmod', 'args' => ['<fixture_rra>/7.rrd', 0400]],
            ['fn' => 'rrd_rra_delete', 'args' => [['<fixture_rra>/7.rrd'], [], false]],
        ],
    ])['results'][1];

    expect($result['diagnostics'])->toBe([])
        ->and($result['returned']['err_msg'] ?? null)->toMatch('~\AERROR: RRDfile \S*/7\.rrd not writable\z~');
});
