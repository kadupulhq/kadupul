<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';
require_once dirname(__DIR__, 4) . '/include/global_constants.php';

function aggregate_total_legend_rows(array $scenario): array
{
    $root = dirname(__DIR__, 4);
    $stderr = tempnam(sys_get_temp_dir(), 'aggregate-legend-stderr-');
    if ($stderr === false) throw new RuntimeException('Cannot create fixture stderr');
    $process = null;
    try {
        $process = proc_open([PHP_BINARY, $root . '/tests/Fixtures/aggregate-total-legend.php', $root, json_encode($scenario, JSON_THROW_ON_ERROR)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start aggregate fixture');
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $process = null;
        $errors = file_get_contents($stderr);
        expect($status)->toBe(0, $errors . $output)
            ->and($errors)->toBe('');

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
        }
        unlink($stderr);
    }
}

test('aggregate totals update matching legend rows and preserve other graphs and sequences', function (int $totalType, bool $scaled): void {
    $items = [];
    $types = [GRAPH_ITEM_TYPE_LINE1, GRAPH_ITEM_TYPE_GPRINT, GRAPH_ITEM_TYPE_LEGEND, GRAPH_ITEM_TYPE_GPRINT_LAST, GRAPH_ITEM_TYPE_GPRINT_MAX, GRAPH_ITEM_TYPE_GPRINT_MIN, GRAPH_ITEM_TYPE_GPRINT_AVERAGE, GRAPH_ITEM_TYPE_LEGEND_CAMM];
    foreach ($types as $index => $type) {
        $items[] = ['id' => $index + 1, 'local_graph_id' => 7, 'sequence' => $index + 10, 'cdef_id' => $scaled ? 1 : 0, 'graph_type_id' => $type, 'vdef_id' => $type === GRAPH_ITEM_TYPE_GPRINT ? 1 : 0];
    }
    $items[] = array_replace($items[0], ['id' => 20, 'sequence' => 9]);
    $items[] = array_replace($items[0], ['id' => 21, 'local_graph_id' => 8]);
    $result = aggregate_total_legend_rows(['items' => $items, 'first_total_sequence' => 10, 'total_type' => $totalType, 'scaled' => $scaled]);
    expect(array_column(array_slice($result['items'], 0, 8), 'cdef_id'))->toBe(array_fill(0, 8, 2))
        ->and(array_column(array_slice($result['items'], 8), 'cdef_id'))->toBe(array_fill(0, 2, $scaled ? 1 : 0))
        ->and(array_column($result['items'], 'vdef_id'))->toBe(array_column($items, 'vdef_id'));
})->with(['similar' => [AGGREGATE_TOTAL_TYPE_SIMILAR], 'all' => [AGGREGATE_TOTAL_TYPE_ALL]])->with([false, true]);

test('an empty or unrelated total selection leaves graph items unchanged', function (bool $unrelated): void {
    $items = $unrelated ? [['id' => 1, 'local_graph_id' => 8, 'sequence' => 10, 'cdef_id' => 0, 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT, 'vdef_id' => 1]] : [];
    $result = aggregate_total_legend_rows(['items' => $items, 'first_total_sequence' => 10, 'total_type' => AGGREGATE_TOTAL_TYPE_SIMILAR, 'scaled' => false]);
    expect($result['items'])->toBe($items);
})->with([false, true]);

test('native RRDtool renders aggregate legends from the combined samples', function (int $totalType, bool $percentile): void {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (new Symfony\Component\Process\ExecutableFinder())->find('rrdtool');
    if (!$binary || !is_executable($binary)) $this->markTestSkipped('Native RRDtool is required.');
    $rows = aggregate_total_legend_rows(['items' => [
        ['id' => 1, 'local_graph_id' => 7, 'sequence' => 3, 'cdef_id' => 0, 'graph_type_id' => GRAPH_ITEM_TYPE_LINE1, 'vdef_id' => 0],
        ['id' => 2, 'local_graph_id' => 7, 'sequence' => 4, 'cdef_id' => 0, 'graph_type_id' => $percentile ? GRAPH_ITEM_TYPE_GPRINT : GRAPH_ITEM_TYPE_GPRINT_AVERAGE, 'vdef_id' => $percentile ? 1 : 0],
        ['id' => 3, 'local_graph_id' => 7, 'sequence' => 5, 'cdef_id' => 0, 'graph_type_id' => GRAPH_ITEM_TYPE_GPRINT_MAX, 'vdef_id' => 0],
    ], 'first_total_sequence' => 3, 'total_type' => $totalType, 'scaled' => false]);
    $in = rrd_characterization_ds('traffic_in');
    $second = array_replace($in, ['data_template_rrd_id' => '103', 'local_data_id' => '12']);
    $items = [
        rrd_characterization_item(11, 'LINE1', $in + ['hex' => '3366CC', 'text_format' => 'First']),
        rrd_characterization_item(12, 'LINE1', $second + ['hex' => '339966', 'text_format' => 'Second']),
        rrd_characterization_item(13, 'LINE1', $in + ['hex' => 'FF0000', 'text_format' => 'Total', 'cdef_id' => (string) $rows['items'][0]['cdef_id']]),
        rrd_characterization_item(14, $percentile ? 'GPRINT' : 'GPRINT_AVERAGE', $in + ['text_format' => 'Total percentile', 'gprint_text' => '%8.2lf', 'cdef_id' => (string) $rows['items'][1]['cdef_id'], 'vdef_id' => (string) $rows['items'][1]['vdef_id']]),
    ];
    // The existing MAX-to-AVERAGE fallback remains valid for an AVERAGE-only RRD.
    $items[] = rrd_characterization_item(15, 'GPRINT_MAX', $in + ['text_format' => 'Maximum total', 'gprint_text' => '%8.2lf', 'consolidation_function_id' => '3', 'cdef_id' => (string) $rows['items'][2]['cdef_id']]);
    $database = rrd_characterization_graph_db(rrd_characterization_graph(['title_cache' => 'Aggregate', 'vertical_label' => 'value']), $items);
    // The renderer resolves the persisted total CDEF, rather than a second implementation of aggregation.
    $database = array_values(array_filter($database, static fn(array $entry): bool => !str_contains($entry['sql'], 'cdef_items')));
    $database = array_merge($database, rrd_characterization_rpn('cdef', 2, [21 => ['6', $rows['cdefs'][2]]]));
    $start = 1699999800;
    $paths = ['<fixture_rra>/router_traffic_11.rrd', '<fixture_rra>/router_errors_12.rrd'];
    $calls = [];
    foreach ($paths as $source => $path) {
        $calls[] = ['fn' => 'rrdtool_execute', 'args' => [['create', $path, '--start', $start - 600, '--step', 300, 'DS:traffic_in:GAUGE:600:U:U', 'RRA:AVERAGE:0.5:1:40'], false, 1]];
        $updates = [];
        for ($sample = -1; $sample <= 21; $sample++) $updates[] = ($start + $sample * 300) . ':' . ($source === 0 ? $sample : 21 - $sample);
        $calls[] = ['fn' => 'rrdtool_execute', 'args' => [array_merge(['update', $path], $updates), false, 1]];
    }
    $calls[] = ['fn' => 'rrdtool_function_graph', 'args' => [7, 0, ['graph_start' => $start, 'graph_end' => $start + 6000, 'graphv' => true, 'output_filename' => '/dev/null', 'output_flag' => RRDTOOL_OUTPUT_STDOUT], false, [], 0]];
    $result = rrd_characterization_run($this, ['options' => ['path_rrdtool' => $binary, 'boost_rrd_update_enable' => '', 'font_method' => '0'] + rrd_characterization_options(), 'db' => $database, 'calls' => $calls])['results'];
    foreach ($result as $entry) expect($entry['diagnostics'])->toBe([]);
    expect($result[4]['returned'])->toMatch('/Total percentile\s+21\.00"/')
        ->and($result[4]['returned'])->toMatch('/Maximum total\s+21\.00"/');
})->with(['similar' => [AGGREGATE_TOTAL_TYPE_SIMILAR], 'all' => [AGGREGATE_TOTAL_TYPE_ALL]])->with([false, true]);
