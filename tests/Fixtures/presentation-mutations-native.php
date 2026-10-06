<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
[, $root, $directory, $case] = $argv;
require_once $root . '/tests/Helpers/PresentationMutationEvidence.php';
$cases = array('query-remove', 'query-remove-missing', 'query-item-remove', 'query-item-remove-missing',
    'color-up', 'color-down', 'color-up-first', 'color-down-last', 'color-missing', 'color-zero', 'color-gapped-down');
if (!in_array($case, $cases, true) || !is_dir($directory)) {
    throw new RuntimeException('Unknown persisted presentation scenario');
}
$mutationColor = str_starts_with($case, 'color-');
$mutationPage = $mutationColor ? 'color_templates_items.php' : 'data_queries.php';
$scenarios = require $root . '/tests/Fixtures/legacy-form-golden-scenarios.php';
$scenario = $scenarios['pages']['data_queries-edit'];
$scenario['page'] = $mutationPage;
$scenario['request']['action'] = $mutationColor ? 'native_fixture_load' : 'edit';
$json = json_encode($scenario, JSON_THROW_ON_ERROR);
if (file_put_contents($directory . '/scenario.json', $json) !== strlen($json)) {
    throw new RuntimeException('Cannot preserve mutation bootstrap');
}
define('PRESENTATION_PAGE_NATIVE', true);
$GLOBALS['nativePresentationObserver'] = static function (array $rendered) use ($root, $directory, $case, $mutationPage, $mutationColor): void {
    if (LegacyFormGoldenFiles::$transformedIncludes !== 0 || $rendered['diagnostics'] !== array()) {
        throw new RuntimeException('Mutation bootstrap did not execute the original module cleanly');
    }
    $registered = array_flip(PresentationMutationEvidence::sources());
    foreach (get_included_files() as $file) {
        if (str_starts_with($file, $root . '/')) {
            $relative = substr($file, strlen($root) + 1);
            if (!str_starts_with($relative, 'include/vendor/') && !str_starts_with($relative, 'tests/vendor/') && !isset($registered[$relative])) {
                throw new RuntimeException('Unregistered persisted mutation worker: ' . $relative);
            }
        }
    }
    $db = PresentationMutationEvidence::database($root, $directory);
    $insert = static function (string $table, array $row) use ($db): void {
        $columns = array_keys($row);
        $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute(array_values($row));
    };
    foreach (array(10, 20) as $id) {
        $insert('snmp_query', array('id' => $id, 'hash' => 'query-' . $id, 'name' => 'Query ' . $id, 'description' => 'Preserved metadata'));
        foreach (array($id * 10, $id * 10 + 1) as $graph) {
            $insert('snmp_query_graph', array('id' => $graph, 'hash' => 'graph-' . $graph, 'snmp_query_id' => $id, 'name' => 'Graph ' . $graph, 'graph_template_id' => 9));
            $insert('snmp_query_graph_rrd', array('snmp_query_graph_id' => $graph, 'data_template_id' => 7, 'data_template_rrd_id' => $graph, 'snmp_field_name' => 'reading'));
            $insert('snmp_query_graph_rrd_sv', array('id' => $graph, 'hash' => 'rrd-' . $graph, 'snmp_query_graph_id' => $graph, 'data_template_id' => 7, 'field_name' => 'reading', 'text' => 'Stored RRD value'));
            $insert('snmp_query_graph_sv', array('id' => $graph, 'hash' => 'sv-' . $graph, 'snmp_query_graph_id' => $graph, 'field_name' => 'name', 'text' => 'Stored graph value'));
        }
        $insert('host_template_snmp_query', array('host_template_id' => 3, 'snmp_query_id' => $id));
        $insert('host_snmp_query', array('host_id' => 4, 'snmp_query_id' => $id, 'title_format' => 'Retained title'));
        $insert('host_snmp_cache', array('host_id' => 4, 'snmp_query_id' => $id, 'field_name' => 'reading', 'field_value' => 'Retained sample', 'snmp_index' => '1', 'oid' => '.1.3.6'));
    }
    foreach (array(1 => 1, 2 => $case === 'color-gapped-down' ? 5 : 2, 3 => $case === 'color-gapped-down' ? 9 : 3, 4 => 2) as $id => $sequence) {
        $insert('color_template_items', array('color_template_item_id' => $id, 'color_template_id' => $id === 4 ? 8 : 7, 'color_id' => 100 + $id, 'sequence' => $sequence));
    }
    $insert('settings', array('name' => 'poller_replicate_snmp_query_crc', 'value' => 'old-crc'));
    $insert('settings', array('name' => 'unrelated_setting', 'value' => 'Retain exactly'));
    $before = PresentationMutationEvidence::snapshot($db);
    $expected = $before;
    $db->prepared = array();
    $key = $GLOBALS['database_hostname'] . ':' . $GLOBALS['database_port'] . ':' . $GLOBALS['database_default'];
    $prior = $GLOBALS['database_sessions'][$key];
    $GLOBALS['database_sessions'][$key] = $db;
    try {
        if ($mutationColor) {
            $id = match ($case) {
                'color-up' => 2, 'color-down-last' => 3, 'color-missing' => 999, 'color-zero' => 0, default => 1,
            };
            $_REQUEST = array('color_template_item_id' => (string) $id, 'color_template_id' => '7');
            $GLOBALS['request'] = &$_REQUEST;
            $up = str_starts_with($case, 'color-up');
            $up ? aggregate_color_item_moveup() : aggregate_color_item_movedown();
            $swap = in_array($case, array('color-up', 'color-down', 'color-gapped-down'), true);
            if ($swap) {
                foreach ($expected['color_template_items'] as &$row) {
                    if ($row['color_template_item_id'] === 1) {
                        $row['sequence'] = $case === 'color-gapped-down' ? 5 : 2;
                    } elseif ($row['color_template_item_id'] === 2) {
                        $row['sequence'] = 1;
                    }
                }
                unset($row);
            }
            $mutationQueries = array_values(array_filter($db->prepared, static fn(string $sql): bool => str_contains($sql, 'color_template_items')));
            $budget = $swap ? 4 : (in_array($case, array('color-missing', 'color-zero'), true) ? 1 : 2);
        } else {
            $missing = str_ends_with($case, '-missing');
            if (str_starts_with($case, 'query-item-')) {
                $id = $missing ? 999 : 100;
                $_REQUEST = array('id' => (string) $id);
                $GLOBALS['request'] = &$_REQUEST;
                data_query_item_remove();
                foreach (array('snmp_query_graph' => 'id', 'snmp_query_graph_rrd' => 'snmp_query_graph_id', 'snmp_query_graph_rrd_sv' => 'snmp_query_graph_id', 'snmp_query_graph_sv' => 'snmp_query_graph_id') as $table => $column) {
                    $expected[$table] = array_values(array_filter($expected[$table], static fn(array $row): bool => $row[$column] !== $id));
                }
                $budget = 4;
            } else {
                $id = $missing ? 999 : 10;
                data_query_remove($id);
                foreach (array('snmp_query' => 'id', 'snmp_query_graph' => 'snmp_query_id', 'host_template_snmp_query' => 'snmp_query_id', 'host_snmp_query' => 'snmp_query_id', 'host_snmp_cache' => 'snmp_query_id') as $table => $column) {
                    $expected[$table] = array_values(array_filter($expected[$table], static fn(array $row): bool => $row[$column] !== $id));
                }
                if (!$missing) {
                    $expected['snmp_query_graph_rrd'] = array_values(array_filter($expected['snmp_query_graph_rrd'], static fn(array $row): bool => !in_array($row['snmp_query_graph_id'], array(100, 101), true)));
                }
                $budget = $missing ? 7 : 9;
            }
            $mutationQueries = $db->prepared;
        }
        $after = PresentationMutationEvidence::snapshot($db);
        if (!$mutationColor && !str_starts_with($case, 'query-item-')) {
            foreach ($after['settings'] as $row) {
                if ($row['name'] === 'poller_replicate_snmp_query_crc') {
                    if (preg_match('/^[a-f0-9]{40}$/D', $row['value']) !== 1) {
                        throw new RuntimeException('Actual query removal did not publish its replication CRC');
                    }
                    foreach ($expected['settings'] as &$setting) {
                        if ($setting['name'] === $row['name']) {
                            $setting['value'] = $row['value'];
                        }
                    }
                    unset($setting);
                }
            }
        }
        if ($after !== $expected || count($mutationQueries) !== $budget) {
            file_put_contents($directory . '/mismatch.json', json_encode(array('expected' => $expected,
                'actual' => $after, 'queries' => $mutationQueries, 'budget' => $budget), JSON_THROW_ON_ERROR));
            throw new RuntimeException('Persisted presentation outcome/query budget mismatch: ' . $case);
        }
        $outcome = json_encode(array('case' => $case, 'before' => $before, 'after' => $after, 'queries' => $mutationQueries), JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/outcome.json', $outcome) !== strlen($outcome)) {
            throw new RuntimeException('Cannot retain persisted native outcome');
        }
        $GLOBALS['presentationMutationMarkers'] = PresentationMutationEvidence::markers($case);
    } finally {
        $GLOBALS['database_sessions'][$key] = $prior;
    }
};

if (getenv('PRESENTATION_MUTATION_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (array($mutationPage, 'lib/database.php', 'lib/utility.php') as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/presentation-mutations-native.php', $case, PresentationMutationEvidence::sources());
    $coverage->start('persisted presentation ' . $case);
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
            if (($GLOBALS['presentationMutationMarkers'] ?? array()) !== PresentationMutationEvidence::markers($case)) {
                throw new RuntimeException('Persisted mutation assertions incomplete');
            }
            $testLoader->unregister();
            $testLoader->register(true);
            $coverage->stop();
            $report = $directory . '/mutation.coverage';
            $bytes = serialize($coverage);
            if (file_put_contents($report, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Cannot retain persisted mutation coverage');
            }
            NativeChildCoverageEvidence::write($report, $root, $snapshot, PresentationMutationEvidence::markers($case));
        });
    });
}
$argv = array(__FILE__, $root, $directory);
require $root . '/tests/Fixtures/legacy-form-golden.php';
