<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
[, $root, $directory, $case] = $argv;
require_once $root . '/tests/Helpers/PresentationGraphCreationEvidence.php';
$cases = array('templates-device','templates-filter','templates-no-matches','templates-none','templates-missing-device','query-populated','query-filter','query-no-matches','query-empty','query-disabled','query-all','query-multiple');
if (!in_array($case, $cases, true) || !is_dir($directory)) {
    throw new RuntimeException('Unknown native graph creation scenario');
}
$scenarios = require $root . '/tests/Fixtures/legacy-form-golden-scenarios.php';
$scenario = $scenarios['pages']['data_queries-edit'];
$scenario['page'] = 'graphs_new.php';
$scenario['request'] = array('action' => 'ajax_save_filter','rows' => '10','graph_type' => '-1','header' => 'false');
$bytes = json_encode($scenario, JSON_THROW_ON_ERROR);
if (file_put_contents($directory . '/scenario.json', $bytes) !== strlen($bytes)) {
    throw new RuntimeException('Cannot retain graph bootstrap');
}
define('PRESENTATION_PAGE_NATIVE', true);
$GLOBALS['nativePresentationObserver'] = static function (array $rendered) use ($root, $directory, $case): void {
    if (LegacyFormGoldenFiles::$transformedIncludes !== 0 || $rendered['diagnostics'] !== array()) {
        throw new RuntimeException('Graph bootstrap did not load original module cleanly');
    }
    $validateWorkers = static function () use ($root): void {
        $registered = array_flip(PresentationGraphCreationEvidence::sources());
        foreach (get_included_files() as $file) {
            if (str_starts_with($file, $root . '/')) {
                $relative = substr($file, strlen($root) + 1);
                if (!str_starts_with($relative, 'include/vendor/') && !str_starts_with($relative, 'tests/vendor/') && !isset($registered[$relative])) {
                    throw new RuntimeException('Unregistered graph creation worker: ' . $relative);
                }
            }
        }
    };
    $validateWorkers();
    $db = PresentationMutationEvidence::database($root, $directory);
    PresentationMutationEvidence::createCanonicalTables($db, $root, array_values(array_diff(PresentationGraphCreationEvidence::tables(), PresentationMutationEvidence::tables())));
    $insert = static function (string $table, array $row) use ($db): void {
        $columns = array_keys($row);
        $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute(array_values($row));
    };
    $insert('host', array('id' => 100,'description' => 'Router <one>','hostname' => 'router.invalid','host_template_id' => 3));
    $insert('host', array('id' => 101,'description' => 'Adjacent router','hostname' => 'adjacent.invalid','host_template_id' => 3));
    $insert('host_template', array('id' => 3,'name' => 'Device template <A>'));
    foreach (array(6 => 'Traffic <one>',7 => 'Available <two>',8 => 'Multiple <three>',9 => 'Errors <four>') as $id => $name) {
        $insert('graph_templates', array('id' => $id,'name' => $name,'multiple' => $id === 8 ? 'on' : ''));
    }
    foreach (array(6,9) as $id) {
        $insert('host_graph', array('host_id' => 100,'graph_template_id' => $id));
    }
    $insert('graph_local', array('id' => 200,'host_id' => 100,'graph_template_id' => 6,'snmp_query_id' => 0));
    $insert('user_auth', array('id' => 1,'username' => 'admin','password' => 'unused','full_name' => 'Fixture actor','email_address' => '','enabled' => 'on'));
    $insert('user_auth_realm', array('user_id' => 1,'realm_id' => 8));
    $insert('settings', array('name' => 'autocomplete_enabled','value' => 'on'));
    $queryCase = str_starts_with($case, 'query-');
    if ($queryCase) {
        $insert('snmp_query', array('id' => 10,'hash' => 'interface-query','name' => 'Interface <query>','xml_path' => '<path_cacti>/resource/snmp_queries/interface.xml'));
        $insert('host_snmp_query', array('host_id' => 100,'snmp_query_id' => 10));
        if ($case !== 'query-disabled') {
            $insert('snmp_query_graph', array('id' => 30,'hash' => 'interface-graph','snmp_query_id' => 10,'name' => 'Interface traffic','graph_template_id' => 6));
            $insert('graph_local', array('id' => 300,'host_id' => 100,'graph_template_id' => 6,'snmp_query_id' => 10,'snmp_query_graph_id' => 30,'snmp_index' => '1'));
            if ($case === 'query-multiple') {
                $insert('snmp_query_graph', array('id' => 40,'hash' => 'alternate-graph','snmp_query_id' => 10,'name' => 'Alternate interface','graph_template_id' => 9));
            }
        }
        if ($case !== 'query-empty') {
            foreach (array(1 => 'uplink <one>',2 => 'downlink <two>') as $index => $name) {
                foreach (array('ifIndex' => (string) $index,'ifDescr' => $name) as $field => $value) {
                    $insert('host_snmp_cache', array('host_id' => 100,'snmp_query_id' => 10,'field_name' => $field,'field_value' => $value,'snmp_index' => (string) $index,'oid' => '.1.3.6.' . $index));
                }
            }
        }
    }
    $before = PresentationGraphCreationEvidence::snapshot($db);
    $key = $GLOBALS['database_hostname'] . ':' . $GLOBALS['database_port'] . ':' . $GLOBALS['database_default'];
    $prior = $GLOBALS['database_sessions'][$key];
    $GLOBALS['database_sessions'][$key] = $db;
    try {
        unset($_SESSION['sess_config_array']);
        $_REQUEST = array('action' => '','header' => 'false','rows' => '10','graph_type' => $queryCase ? ($case === 'query-all' ? '-2' : '10') : '-1','host_id' => $case === 'templates-none' ? '0' : ($case === 'templates-missing-device' ? '999' : '100'),'filter' => $case === 'templates-filter' ? 'Traffic' : ($case === 'templates-no-matches' || $case === 'query-no-matches' ? 'No matching template' : ($case === 'query-filter' ? 'uplink' : '')),'returnto' => 'host.php');
        $GLOBALS['request'] = &$_REQUEST;
        $GLOBALS['_CACTI_REQUEST'] = array();
        $db->prepared = array();
        ob_start();
        graphs();
        $html = ob_get_clean();
        $dom = new DOMDocument();
        if ($html === '' || !@$dom->loadHTML($html)) {
            throw new RuntimeException('Actual graph creation markup is unreadable');
        }$xp = new DOMXPath($dom);
        if ($queryCase) {
            $indices = in_array($case, array('query-empty','query-no-matches'), true) ? array() : ($case === 'query-filter' ? array(1) : array(1,2));
            $rows = $xp->query('//tr[starts-with(@id,"dqline") or starts-with(@id,"nodqline")]');
            if ($rows->length !== count($indices)) {
                throw new RuntimeException('Persisted query filtering/count mismatch');
            }
            foreach ($indices as $offset => $index) {
                $row = $rows->item($offset);
                $name = 'sg_10_' . md5((string) $index);
                $control = $xp->query('.//input[@name="' . $name . '"]', $row)->item(0);
                if (!$control || $control->hasAttribute('disabled') !== ($case === 'query-disabled') || !str_contains($row->textContent, $index === 1 ? 'uplink <one>' : 'downlink <two>')) {
                    throw new RuntimeException('Stored query index handoff/disabled state lost');
                }
            }
            if ($case === 'query-empty' && !str_contains($dom->textContent, 'This Data Query returned 0 rows')) {
                throw new RuntimeException('Query empty result message missing');
            }
            if ($case === 'query-no-matches' && !str_contains($dom->textContent, 'Search Returned no Rows.')) {
                throw new RuntimeException('Query no-match message missing');
            }
            if (!str_contains($dom->textContent, 'Interface <query>')) {
                throw new RuntimeException('Stored query heading missing');
            }
            if (!in_array($case, array('query-disabled','query-multiple'), true) && $xp->query('//input[@name="sgg_10" and @value="30"]')->length !== 1) {
                throw new RuntimeException('Single query graph selected identity lost');
            }
            if ($case === 'query-multiple' && ($xp->query('//select[@name="sgg_10"]/option[@value="30"]')->length !== 1 || $xp->query('//select[@name="sgg_10"]/option[@value="40"]')->length !== 1)) {
                throw new RuntimeException('Multiple query graph options lost distinct identities');
            }
            if ($case !== 'query-disabled' && !str_contains($html, "created_graphs[30] = new Array('c4ca4238a0b923820dcc509a6f75849b')")) {
                throw new RuntimeException('Existing graph index dependency handoff missing');
            }
        } else {
            $rows = $xp->query('//tr[starts-with(@id,"gt_line")]');
            $expectedRows = in_array($case, array('templates-none','templates-missing-device','templates-no-matches'), true) ? array() : ($case === 'templates-filter' ? array(6) : array(9,6));
            $actual = array();
            foreach ($rows as $row) {
                $id = (int) substr($row->getAttribute('id'), 7);
                $actual[] = $id;
                if ($xp->query('.//input[@type="checkbox" and @name="cg_' . $id . '"]', $row)->length !== 1) {
                    throw new RuntimeException('Graph template selection identity lost');
                }
            }
            if ($actual !== $expectedRows) {
                throw new RuntimeException('Stored graph template filter/order mismatch: ' . json_encode($actual));
            }
            $options = $xp->query('//select[@name="cg_g"]/option');
            $available = array();
            foreach ($options as $option) {
                if ((int) $option->getAttribute('value') > 0) {
                    $available[] = (int) $option->getAttribute('value');
                }
            }
            $expectedAvailable = in_array($case, array('templates-none','templates-missing-device'), true) ? array(7,9,8,6) : array(7,8);
            if ($available !== $expectedAvailable) {
                throw new RuntimeException('Graph creation available template omission/multiple semantics mismatch: ' . json_encode($available));
            }
            if (!in_array($case, array('templates-none','templates-missing-device'), true) && (!str_contains($dom->textContent, 'Router <one>') || !str_contains($dom->textContent, 'Device template <A>') || !str_contains($html, "var gt_created_graphs = new Array('6')"))) {
                throw new RuntimeException('Stored device/template or created-graph dependency missing');
            }
        }
        if ($xp->query('//input[@name="save_component_graph" and @value="1"]')->length !== 1 || $xp->query('//form[@action="graphs_new.php"]')->length < 1) {
            throw new RuntimeException('Actual graph creation form handoff missing');
        }
        if ($xp->query('//one|//two|//three|//four')->length !== 0) {
            throw new RuntimeException('Stored graph labels became markup');
        }
        $validateWorkers();
        foreach ($db->prepared as $query) {
            if (preg_match('/^SELECT\b/i', $query) !== 1) {
                throw new RuntimeException('Read-only graph form attempted a database mutation');
            }
        }
        $after = PresentationGraphCreationEvidence::snapshot($db);
        if ($after !== $before) {
            throw new RuntimeException('Graph rendering changed stored metadata');
        }
        $bytes = json_encode(array('case' => $case,'before' => $before,'after' => $after,'html' => $html), JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/outcome.json', $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot retain graph render outcome');
        }
        $GLOBALS['presentationGraphCreationMarkers'] = PresentationGraphCreationEvidence::markers($case);
    } finally {
        $GLOBALS['database_sessions'][$key] = $prior;
    }
};

if (getenv('PRESENTATION_GRAPH_CREATION_COVERAGE') === '1') {
    $testLoader = require $root . '/tests/vendor/autoload.php';
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $filter = new SebastianBergmann\CodeCoverage\Filter();
    foreach (array('graphs_new.php', 'lib/database.php', 'lib/html.php') as $source) {
        $filter->includeFile($root . '/' . $source);
    }
    $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage((new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter), $filter);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/presentation-graph-creation-native.php', $case, PresentationGraphCreationEvidence::sources());
    $coverage->start('persisted presentation ' . $case);
    register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
        register_shutdown_function(static function () use ($coverage, $snapshot, $root, $directory, $case, $testLoader): void {
            if (($GLOBALS['presentationGraphCreationMarkers'] ?? array()) !== PresentationGraphCreationEvidence::markers($case)) {
                throw new RuntimeException('Persisted mutation assertions incomplete');
            }
            $testLoader->unregister();
            $testLoader->register(true);
            $coverage->stop();
            $report = $directory . '/graph-creation.coverage';
            $bytes = serialize($coverage);
            if (file_put_contents($report, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Cannot retain persisted mutation coverage');
            }
            NativeChildCoverageEvidence::write($report, $root, $snapshot, PresentationGraphCreationEvidence::markers($case));
        });
    });
}
$argv = array(__FILE__, $root, $directory);
require $root . '/tests/Fixtures/legacy-form-golden.php';
