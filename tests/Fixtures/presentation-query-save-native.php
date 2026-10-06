<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
[, $root, $directory, $case] = $argv;
require_once $root . '/tests/Helpers/PresentationQuerySaveEvidence.php';
$cases = PresentationQuerySaveEvidence::cases();
if (!in_array($case, $cases, true) || !is_dir($directory)) {
    throw new RuntimeException('Unknown native query save scenario');
}
$mutationColor = false;
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
    $validateWorkers = static function () use ($root): void {
        $registered = array_flip(PresentationQuerySaveEvidence::sources());
        foreach (get_included_files() as $file) {
            if (str_starts_with($file, $root . '/')) {
                $relative = substr($file, strlen($root) + 1);
                if (!str_starts_with($relative, 'include/vendor/') && !str_starts_with($relative, 'tests/vendor/') && !isset($registered[$relative])) {
                    throw new RuntimeException('Unregistered query save worker: ' . $relative);
                }
            }
        }
    };
    $validateWorkers();
    $db = PresentationMutationEvidence::database($root, $directory);
    PresentationMutationEvidence::createCanonicalTables($db, $root, PresentationQuerySaveEvidence::tables());
    $insert = static function (string $table, array $row) use ($db): void {
        $columns = array_keys($row);
        $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute(array_values($row));
    };
    $insert('data_input', array('id' => 2, 'name' => 'Actual SNMP query input', 'type_id' => 3));
    $insert('snmp_query', array('id' => 10, 'hash' => str_repeat('a', 32), 'name' => 'Query <one>', 'description' => 'Stored description',
        'xml_path' => $case === 'query-edit-missing-xml' ? $directory . '/missing.xml' : $root . '/resource/snmp_queries/interface.xml', 'data_input_id' => 2));
    $insert('graph_templates', array('id' => 9, 'name' => 'Template <one>'));
    foreach (array(100, 101) as $id) {
        $insert('snmp_query_graph', array('id' => $id, 'hash' => str_repeat('b', 32), 'snmp_query_id' => 10, 'name' => 'Association ' . $id, 'graph_template_id' => 9));
    }
    $insert('graph_local', array('id' => 200, 'host_id' => 4, 'graph_template_id' => 9, 'snmp_query_graph_id' => 100));
    $insert('data_template', array('id' => 7, 'name' => 'Data Template <one>'));
    $insert('data_template_rrd', array('id' => 70, 'data_template_id' => 7, 'local_data_id' => 0, 'data_source_name' => 'ifInOctets'));
    $insert('graph_templates_item', array('id' => 80, 'graph_template_id' => 9, 'local_graph_id' => 0, 'task_item_id' => 70));
    $insert('snmp_query_graph_rrd', array('snmp_query_graph_id' => 100, 'data_template_id' => 7, 'data_template_rrd_id' => 70, 'snmp_field_name' => 'ifInOctets'));
    foreach (array(1, 2) as $sequence) {
        $insert('snmp_query_graph_sv', array('id' => $sequence, 'hash' => str_repeat('c', 32), 'snmp_query_graph_id' => 100, 'field_name' => 'title', 'sequence' => $sequence, 'text' => 'Stored graph value ' . $sequence));
        $insert('snmp_query_graph_rrd_sv', array('id' => $sequence, 'hash' => str_repeat('d', 32), 'snmp_query_graph_id' => 100, 'data_template_id' => 7, 'field_name' => 'name', 'sequence' => $sequence, 'text' => 'Stored RRD value ' . $sequence));
    }
    $insert('color_templates', array('color_template_id' => 7, 'name' => 'Palette <one>'));
    $insert('colors', array('id' => 101, 'hex' => 'ABCDEF'));
    $insert('color_template_items', array('color_template_item_id' => 1, 'color_template_id' => 7, 'color_id' => 101, 'sequence' => 3));
    $before = PresentationQuerySaveEvidence::snapshot($db);
    $key = $GLOBALS['database_hostname'] . ':' . $GLOBALS['database_port'] . ':' . $GLOBALS['database_default'];
    $prior = $GLOBALS['database_sessions'][$key];
    $GLOBALS['database_sessions'][$key] = $db;
    $db->prepared = array();
    try {
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) throw new RuntimeException('Cannot restore owned query-save session');
        unset($_SESSION['sess_field_values'], $_SESSION['sess_error_fields'], $_SESSION['sess_messages']);
        $request = array('id' => '100', 'snmp_query_id' => '10', 'graph_template_id' => '9', 'header' => 'false');
        if (str_starts_with($case, 'graph-suggestion')) {
            $request += array('save_component_svg' => '1', 'svg_field' => str_ends_with($case, 'empty-field') ? '' : 'title', 'svg_text' => str_ends_with($case, 'empty-text') ? '' : 'Native suggested graph');
        } elseif (str_starts_with($case, 'data-suggestion')) {
            $request += array('save_component_svds' => '1', 'svds_id' => '7', 'svds_field' => str_ends_with($case, 'empty-field') ? '' : 'name', 'svds_text' => str_ends_with($case, 'empty-text') ? '' : 'Native suggested data');
        } elseif ($case === 'association-empty-name') {
            $request += array('save_component_snmp_query_item' => '1', 'name' => '');
        } else {
            $request['id'] = '10';
            $request += array('save_component_snmp_query' => '1', 'name' => $case === 'query-empty-name' ? '' : 'Query <one>', 'description' => 'Stored description',
                'data_input_id' => '2', 'xml_path' => $case === 'query-empty-xml' ? '' : $root . '/resource/snmp_queries/interface.xml');
        }
        $_REQUEST = $request;
        $_POST = $request;
        $_GET = array();
        $GLOBALS['request'] = &$_REQUEST;
        $GLOBALS['_CACTI_REQUEST'] = array();
        form_save();
        $validateWorkers();
        $after = PresentationQuerySaveEvidence::snapshot($db);
        $expected = $before;
        $valid = str_ends_with($case, '-save');
        if ($valid) {
            $table = str_starts_with($case, 'graph-') ? 'snmp_query_graph_sv' : 'snmp_query_graph_rrd_sv';
            $newRows = array_values(array_filter($after[$table], static fn(array $row): bool => $row['id'] === 3));
            if (count($newRows) !== 1 || preg_match('/^[a-f0-9]{32}$/D', $newRows[0]['hash']) !== 1) throw new RuntimeException('Actual save did not allocate one canonical suggestion/hash');
            $row = array('id' => 3, 'hash' => $newRows[0]['hash'], 'snmp_query_graph_id' => 100, 'sequence' => 3,
                'field_name' => $table === 'snmp_query_graph_sv' ? 'title' : 'name', 'text' => $table === 'snmp_query_graph_sv' ? 'Native suggested graph' : 'Native suggested data');
            if ($table === 'snmp_query_graph_rrd_sv') $row['data_template_id'] = 7;
            // Canonical row column order belongs to the shipped schema, not insertion array order.
            $canonical = array();
            foreach (array_keys($newRows[0]) as $name) $canonical[$name] = $row[$name];
            if ($newRows[0] !== $canonical) throw new RuntimeException('Actual suggestion save lost field/template/graph identity or sequence');
            $expected[$table][] = $canonical;
            usort($expected[$table], static fn(array $a, array $b): int => json_encode($a) <=> json_encode($b));
            if (!empty($_SESSION['sess_messages']) || !empty($_SESSION['sess_error_fields'])) throw new RuntimeException('Successful suggestion retained an obsolete error');
        } else {
            $field = str_ends_with($case, 'empty-text') ? 39 : (str_ends_with($case, 'empty-field') ? 38 : null);
            if ($field === null ? empty($_SESSION['sess_error_fields']) : !isset($_SESSION['sess_messages'][$field])) throw new RuntimeException('Invalid query save did not reach its normal validation outcome');
        }
        if ($expected !== $after) throw new RuntimeException('Query save changed unexpected persisted rows');
        if (($GLOBALS['diagnostics'] ?? array()) !== array()) throw new RuntimeException('Actual query save emitted unexpected diagnostics: ' . implode('; ', $GLOBALS['diagnostics']));
        $queries = $db->prepared;
        foreach ($queries as $sql) {
            if (!str_starts_with($sql, 'SELECT') && !($valid && str_starts_with($sql, 'INSERT INTO ' . $table))) throw new RuntimeException('Query validation/save performed an unexpected write');
        }
        $outcome = json_encode(array('case' => $case, 'before' => $before, 'after' => $after, 'queries' => $queries), JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/outcome.json', $outcome) !== strlen($outcome)) throw new RuntimeException('Cannot retain query save outcome');
        $GLOBALS['presentationMutationMarkers'] = PresentationQuerySaveEvidence::markers($case);
    } finally {
        $GLOBALS['database_sessions'][$key] = $prior;
    }
};

if (getenv('PRESENTATION_QUERY_SAVE_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (array($mutationPage, 'lib/database.php', 'lib/utility.php', 'lib/data_query.php', 'lib/xml.php', 'lib/path_helpers.php') as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/presentation-query-save-native.php', $case, PresentationQuerySaveEvidence::sources());
    $coverage->start('persisted presentation ' . $case);
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
            if (($GLOBALS['presentationMutationMarkers'] ?? array()) !== PresentationQuerySaveEvidence::markers($case)) {
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
            NativeChildCoverageEvidence::write($report, $root, $snapshot, PresentationQuerySaveEvidence::markers($case));
        });
    });
}
$argv = array(__FILE__, $root, $directory);
require $root . '/tests/Fixtures/legacy-form-golden.php';
