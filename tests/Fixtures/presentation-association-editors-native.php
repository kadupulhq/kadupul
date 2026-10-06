<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
[, $root, $directory, $case] = $argv;
require_once $root . '/tests/Helpers/PresentationAssociationEditorEvidence.php';
$cases = PresentationAssociationEditorEvidence::cases();
if (!in_array($case, $cases, true) || !is_dir($directory)) {
    throw new RuntimeException('Unknown native association editor scenario');
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
    $validateWorkers = static function () use ($root): void {
        $registered = array_flip(PresentationAssociationEditorEvidence::sources());
        foreach (get_included_files() as $file) {
            if (str_starts_with($file, $root . '/')) {
                $relative = substr($file, strlen($root) + 1);
                if (!str_starts_with($relative, 'include/vendor/') && !str_starts_with($relative, 'tests/vendor/') && !isset($registered[$relative])) {
                    throw new RuntimeException('Unregistered persisted mutation worker: ' . $relative);
                }
            }
        }
    };
    $validateWorkers();
    $db = PresentationMutationEvidence::database($root, $directory);
    PresentationMutationEvidence::createCanonicalTables($db, $root, PresentationAssociationEditorEvidence::tables());
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
    // inject_form_variables mutates global definitions by reference during bootstrap.
    // Reload the actual definition module so this independent render gets original placeholders.
    (static function (string $root): void {
        extract($GLOBALS, EXTR_SKIP);
        global $fields_data_query_edit, $fields_data_query_item_edit, $struct_color_template_item;
        include $root . '/include/global_form.php';
    })($root);
    $before = PresentationAssociationEditorEvidence::snapshot($db);
    $key = $GLOBALS['database_hostname'] . ':' . $GLOBALS['database_port'] . ':' . $GLOBALS['database_default'];
    $prior = $GLOBALS['database_sessions'][$key];
    $GLOBALS['database_sessions'][$key] = $db;
    $db->prepared = array();
    try {
        $_REQUEST = array('id' => $case === 'query-new' || $case === 'query-association-new' ? '0' : ($case === 'color-confirm' ? '7' : (str_starts_with($case, 'query-association') ? '100' : '10')),
            'snmp_query_id' => '10', 'color_template_id' => '7', 'color_template_item_id' => $case === 'color-new' ? '0' : '1', 'color_id' => '1');
        $GLOBALS['request'] = &$_REQUEST;
        $GLOBALS['_CACTI_REQUEST'] = array();
        unset($_SESSION['sess_field_values'], $_SESSION['sess_error_fields']);
        ob_start();
        if ($case === 'color-confirm') {
            aggregate_color_item_remove_confirm();
        } elseif ($mutationColor) {
            aggregate_color_item_edit();
        } elseif (str_starts_with($case, 'query-association')) {
            data_query_item_edit();
        } else {
            data_query_edit();
        }
        $html = ob_get_clean();
        $validateWorkers();
        $dom = new DOMDocument();
        if ($html === '' || !@$dom->loadHTML($html)) {
            throw new RuntimeException('Actual editor returned no parseable markup');
        }
        $xpath = new DOMXPath($dom);
        $value = static function (string $name) use ($xpath): string {
            $node = $xpath->query('//input[@name="' . $name . '"]')->item(0);
            if (!$node) throw new RuntimeException('Missing rendered editor control: ' . $name);
            return $node->getAttribute('value');
        };
        if ($mutationColor) {
            if (!str_contains($dom->textContent, 'Palette <one>')) throw new RuntimeException('Stored palette label missing');
            if ($case === 'color-confirm') {
                if (!str_contains($dom->textContent, 'ABCDEF') || $xpath->query('//input[@id="continue"]')->length !== 1 || !str_contains($html, 'color_id: 1')) {
                    throw new RuntimeException('Color confirmation lost color identity or actual continue control');
                }
            } elseif ($value('color_template_id') !== '7' || $value('color_template_item_id') !== ($case === 'color-new' ? '0' : '1') || $value('sequence') !== ($case === 'color-new' ? '0' : '3')) {
                throw new RuntimeException('Color editor lost stored/create identity or sequence');
            }
        } elseif (str_starts_with($case, 'query-association')) {
            if ($value('id') !== ($case === 'query-association-new' ? '0' : '100') || $value('snmp_query_id') !== '10') throw new RuntimeException('Association form lost query/item identity');
            if ($case === 'query-association-edit') {
                if ($xpath->query('//input[@name="dsdt_7_70_check" and @checked]')->length !== 1 || !str_contains($dom->textContent, 'Stored graph value 2') || !str_contains($dom->textContent, 'Stored RRD value 2')) {
                    throw new RuntimeException('Actual canonical template join or suggested values missing');
                }
            }
        } elseif ($case === 'query-new') {
            if ($value('id') !== '0' || !str_contains($dom->textContent, 'Data Queries [new]')) throw new RuntimeException('Query creation defaults missing');
        } else {
            if ($value('id') !== '10' || $value('name') !== 'Query <one>') throw new RuntimeException('Stored query identity/name missing: ' . json_encode(array($value('id'), $value('name'))));
            if ($case === 'query-edit-xml') {
                if ($xpath->query('//a[contains(@class,"linkEditMain") and contains(@href,"id=100")]')->length !== 1 || $xpath->query('//a[contains(@class,"deleteMarkerDisabled")]')->length !== 1 || $xpath->query('//a[contains(@class,"deleteMarker") and contains(@href,"id=101")]')->length !== 1) {
                    throw new RuntimeException('Query association graph count did not preserve editable/delete-disabled controls');
                }
            } elseif ($xpath->query('//span[contains(@class,"noLinkEditMain")]')->length !== 2 || !str_contains($dom->textContent, 'Could not locate XML file.')) {
                throw new RuntimeException('Missing XML must render association read-only labels');
            }
        }
        if ($xpath->query('//one')->length !== 0 || $before !== PresentationAssociationEditorEvidence::snapshot($db)) {
            throw new RuntimeException('Editor changed persisted rows or treated stored labels as markup');
        }
        if (($GLOBALS['diagnostics'] ?? array()) !== array()) {
            throw new RuntimeException('Actual editor emitted unexpected diagnostics: ' . implode('; ', $GLOBALS['diagnostics']));
        }
        $queries = $db->prepared;
        foreach ($queries as $sql) {
            if (!str_starts_with($sql, 'SELECT')) throw new RuntimeException('Editor performed a write');
        }
        $outcome = json_encode(array('case' => $case, 'before' => $before, 'after' => $before, 'queries' => $queries, 'html' => $html), JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/outcome.json', $outcome) !== strlen($outcome)) throw new RuntimeException('Cannot retain editor outcome');
        $GLOBALS['presentationMutationMarkers'] = PresentationAssociationEditorEvidence::markers($case);
    } finally {
        $GLOBALS['database_sessions'][$key] = $prior;
    }
};

if (getenv('PRESENTATION_ASSOCIATION_EDITOR_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (array($mutationPage, 'lib/database.php', 'lib/utility.php', 'lib/data_query.php', 'lib/xml.php', 'lib/path_helpers.php') as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/presentation-association-editors-native.php', $case, PresentationAssociationEditorEvidence::sources());
    $coverage->start('persisted presentation ' . $case);
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
            if (($GLOBALS['presentationMutationMarkers'] ?? array()) !== PresentationAssociationEditorEvidence::markers($case)) {
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
            NativeChildCoverageEvidence::write($report, $root, $snapshot, PresentationAssociationEditorEvidence::markers($case));
        });
    });
}
$argv = array(__FILE__, $root, $directory);
require $root . '/tests/Fixtures/legacy-form-golden.php';
