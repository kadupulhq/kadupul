<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
[, $root, $directory, $case] = $argv;
require_once $root . '/tests/Helpers/PresentationReportEvidence.php';
$cases = array('display-css', 'display-inline', 'display-empty', 'resequence', 'resequence-empty', 'validate-host-valid', 'validate-host-invalid', 'validate-site-valid', 'validate-site-invalid', 'validate-host-template-valid', 'validate-host-template-invalid', 'validate-graph-template-valid', 'validate-graph-template-invalid');
if (!in_array($case, $cases, true) || !is_dir($directory)) {
    throw new RuntimeException('Unknown native report scenario');
}
$scenarios = require $root . '/tests/Fixtures/legacy-form-golden-scenarios.php';
$scenario = $scenarios['pages']['reports_admin-edit'];
$scenarioBytes = json_encode($scenario, JSON_THROW_ON_ERROR);
if (file_put_contents($directory . '/scenario.json', $scenarioBytes) !== strlen($scenarioBytes)) {
    throw new RuntimeException('Cannot retain report bootstrap scenario');
}
define('PRESENTATION_PAGE_NATIVE', true);
$GLOBALS['nativePresentationObserver'] = static function (array $rendered) use ($root, $directory, $case): void {
    if (LegacyFormGoldenFiles::$transformedIncludes !== 0 || $rendered['diagnostics'] !== array()) {
        throw new RuntimeException('Report bootstrap changed the original module');
    }
    $registered = array_flip(PresentationReportEvidence::sources());
    foreach (get_included_files() as $file) {
        if (str_starts_with($file, $root . '/')) {
            $relative = substr($file, strlen($root) + 1);
            if (!str_starts_with($relative, 'include/vendor/') && !str_starts_with($relative, 'tests/vendor/') && !isset($registered[$relative])) {
                throw new RuntimeException('Unregistered report worker: ' . $relative);
            }
        }
    }
    $db = PresentationMutationEvidence::database($root, $directory);
    PresentationMutationEvidence::createCanonicalTables($db, $root, PresentationReportEvidence::tables());
    $insert = static function (string $table, array $row) use ($db): void {
        $columns = array_keys($row);
        $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute(array_values($row));
    };
    foreach (array(7,8) as $id) {
        $insert('reports', array('id' => $id,'user_id' => 1,'name' => 'Report ' . $id,'cformat' => $case === 'display-css' ? 'on' : '','from_name' => 'Sender','from_email' => 'sender@example.invalid','email' => 'viewer@example.invalid','bcc' => ''));
    }
    $insert('host', array('id' => 100,'description' => 'Router <one>','hostname' => 'router.invalid','site_id' => 2,'host_template_id' => 9));
    $insert('graph_local', array('id' => 200,'host_id' => 100,'graph_template_id' => 6));
    $insert('graph_templates_graph', array('id' => 200,'local_graph_id' => 200,'title' => 'Graph <one>'));
    $insert('graph_templates', array('id' => 6,'name' => 'Template <A>'));
    $insert('graph_tree', array('id' => 1,'name' => 'Tree <one>'));
    $insert('graph_tree_items', array('id' => 3,'graph_tree_id' => 1,'host_id' => 100));
    $insert('graph_tree_items', array('id' => 4,'graph_tree_id' => 1,'title' => 'Branch <edge>'));
    $insert('user_auth_realm', array('realm_id' => 21,'user_id' => 1));
    $items = array(
        array('item_type' => 1,'local_graph_id' => 200),
        array('item_type' => 5,'host_id' => 100,'graph_template_id' => 6,'graph_name_regexp' => 'name.*'),
        array('item_type' => 5,'host_id' => 100,'graph_template_id' => -1),
        array('item_type' => 2,'item_text' => 'Notes <one>'),
        array('item_type' => 3,'tree_id' => 1,'branch_id' => 3),
        array('item_type' => 3,'tree_id' => 1,'branch_id' => 4,'tree_cascade' => 'on','graph_name_regexp' => 'tree.*'),
        array('item_type' => 3,'tree_id' => 1,'branch_id' => 4,'tree_cascade' => ''),
        array('item_type' => 3,'tree_id' => 1,'branch_id' => 0)
    );
    if (!str_ends_with($case, 'empty')) {
        foreach ($items as $i => $item) {
            $insert('reports_items', array_merge(array('id' => $i + 1,'report_id' => 7,'sequence' => ($i + 1) * 3,'item_text' => '','timespan' => 1,'align' => 1,'font_size' => 12), $item));
        }
    }
    $insert('reports_items', array('id' => 99,'report_id' => 8,'sequence' => 17,'item_text' => 'Adjacent untouched'));
    $before = PresentationReportEvidence::snapshot($db);
    $expected = $before;
    $key = $GLOBALS['database_hostname'] . ':' . $GLOBALS['database_port'] . ':' . $GLOBALS['database_default'];
    $prior = $GLOBALS['database_sessions'][$key];
    $GLOBALS['database_sessions'][$key] = $db;
    try {
        $html = '';
        if (str_starts_with($case, 'validate-')) {
            $field = str_starts_with($case, 'validate-host-template-') ? 'host_template_id'
                : (str_starts_with($case, 'validate-graph-template-') ? 'graph_template_id'
                : (str_starts_with($case, 'validate-site-') ? 'site_id' : 'host_id'));
            $invalid = str_ends_with($case, 'invalid');
            $request = array('tree_id' => 0,'branch_id' => 0,'site_id' => 2,'host_id' => 100,'host_template_id' => 9,'graph_template_id' => 6,'local_graph_id' => 200);
            // Host-change evidence keeps the separate pre-existing graph-template/id lookup out of this case.
            if ($field === 'host_id') {
                $request['graph_template_id'] = 0;
            }
            if ($invalid) {
                $request[$field] = 999;
            }
            $_REQUEST = $request;
            $GLOBALS['request'] = &$_REQUEST;
            $GLOBALS['_CACTI_REQUEST'] = array();
            foreach ($request as $name => $value) {
                $_SESSION['sess_report_item_' . $name] = $value;
            }
            $_SESSION['sess_report_item_' . $field] = -1;
            $reset = json_decode(reports_item_validate(), true, 512, JSON_THROW_ON_ERROR);
            $expectedReset = $invalid ? match ($field) {
                'host_id' => array('local_graph_id' => true,'host_template_id' => true,'site_id' => true),
                'site_id' => array('host_id' => true,'local_graph_id' => true),
                'host_template_id' => array('local_graph_id' => true,'host_id' => true),
                default => array('local_graph_id' => true),
            } : array();
            if ($reset !== $expectedReset) {
                throw new RuntimeException('Report filter relationship reset mismatch: ' . json_encode($reset));
            }
            foreach ($request as $name => $value) {
                if ((int) $_SESSION['sess_report_item_' . $name] !== $value) {
                    throw new RuntimeException('Report filter session handoff lost ' . $name);
                }
            }
        } elseif (str_starts_with($case, 'resequence')) {
            reports_item_resequence(7);
            foreach ($expected['reports_items'] as &$row) {
                if ($row['report_id'] === 7) {
                    $row['sequence'] = (int) ($row['sequence'] / 3);
                }
            } unset($row);
        } else {
            ob_start();
            display_reports_items(7);
            $html = ob_get_clean();
            $dom = new DOMDocument();
            if (!@$dom->loadHTML('<table>' . $html . '</table>')) {
                throw new RuntimeException('Actual report markup could not be parsed');
            }
            $xpath = new DOMXPath($dom);
            if ($case === 'display-empty') {
                if (!str_contains($dom->textContent, 'No Report Items') || $xpath->query('//a')->length !== 0) {
                    throw new RuntimeException('Empty report must render the explicit empty state');
                }
            } else {
                $details = array('Graph: Graph <one>','Device: Router <one>, Graph Template: Template <A>, Using RegEx: "name.*"','Device: Router <one>, Graph Template: All Templates','Notes <one>','Tree: Tree <one>, Device: Router <one>','Tree: Tree <one>, Branch: Branch <edge> (All Branches), Using RegEx: "tree.*"','Tree: Tree <one>, Branch: Branch <edge> (Current Branch)','Tree: Tree <one>');
                foreach ($details as $i => $text) {
                    $row = $xpath->query('//tr[@id="line' . ($i + 1) . '"]')->item(0);
                    if (!$row || !str_contains($row->textContent, $text)) {
                        throw new RuntimeException('Stored report details missing: ' . $text);
                    }
                    $cells = $xpath->query('./td', $row);
                    if (trim($cells->item(1)->textContent) !== (string) (($i + 1) * 3)) {
                        throw new RuntimeException('Stored report sequence changed during display');
                    }
                    $link = $xpath->query('./td/a[contains(@class,"linkEditMain")]', $row)->item(0);
                    if (!$link || !str_ends_with($link->getAttribute('href'), '?action=item_edit&id=7&item_id=' . ($i + 1))) {
                        throw new RuntimeException('Report edit link lost item identity');
                    }
                    $up = $xpath->query('.//a[contains(@class,"fa-caret-up")]', $row)->length;
                    $down = $xpath->query('.//a[contains(@class,"fa-caret-down")]', $row)->length;
                    if ($up !== ($i === 0 ? 0 : 1) || $down !== ($i === 7 ? 0 : 1) || $xpath->query('.//a[contains(@class,"deleteMarker")]', $row)->length !== 1) {
                        throw new RuntimeException('Report move/delete controls lost ordering');
                    }
                    if ($case === 'display-css' && !str_contains($row->textContent, 'Using CSS')) {
                        throw new RuntimeException('CSS report formatting was not selected');
                    }
                }
                if ($xpath->query('//one|//edge')->length !== 0) {
                    throw new RuntimeException('Stored report labels became markup');
                }
            }
        }
        $after = PresentationReportEvidence::snapshot($db);
        if ($after !== $expected) {
            throw new RuntimeException('Report operation changed unexpected persisted rows');
        }
        $outcomeBytes = json_encode(array('case' => $case, 'before' => $before, 'after' => $after, 'html' => $html), JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/outcome.json', $outcomeBytes) !== strlen($outcomeBytes)) {
            throw new RuntimeException('Cannot retain actual persisted report outcome');
        }
        $GLOBALS['presentationReportMarkers'] = PresentationReportEvidence::markers($case);
    } finally {
        $GLOBALS['database_sessions'][$key] = $prior;
    }
};

if (getenv('PRESENTATION_REPORT_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (array('lib/html_reports.php', 'lib/database.php', 'lib/functions.php') as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/presentation-reports-native.php', $case, PresentationReportEvidence::sources());
    $coverage->start('persisted presentation ' . $case);
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
            if (($GLOBALS['presentationReportMarkers'] ?? array()) !== PresentationReportEvidence::markers($case)) {
                throw new RuntimeException('Persisted mutation assertions incomplete');
            }
            $testLoader->unregister();
            $testLoader->register(true);
            $coverage->stop();
            $report = $directory . '/report.coverage';
            $bytes = serialize($coverage);
            if (file_put_contents($report, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Cannot retain persisted mutation coverage');
            }
            NativeChildCoverageEvidence::write($report, $root, $snapshot, PresentationReportEvidence::markers($case));
        });
    });
}
$argv = array(__FILE__, $root, $directory);
require $root . '/tests/Fixtures/legacy-form-golden.php';
