<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\AutomationPresentation;

require_once dirname(__DIR__) . '/../include/global_constants.php';
require_once dirname(__DIR__) . '/Helpers/NativeAutomationPresentation.php';
require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

test('automation controllers render real SQL records and preserve declared mutation outcomes', function (array $case): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/automation-presentation-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $input = json_encode($case, JSON_THROW_ON_ERROR);
    $environment = getenv();
    $environment['AUTOMATION_PRESENTATION_COVERAGE'] = $coverage === null ? '0' : '1';
    try {
        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1',
                '-d', 'date.timezone=UTC', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/automation-presentation-native.php', $root, $directory, $input],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/stderr', 'w']],
            $pipes,
            $root,
            $environment
        );
        expect($process)->toBeResource();
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        $errors = file_get_contents($directory . '/stderr');
        expect($errors)->toBe('')->and($exit)->toBe(0)->and($output)->toBeString();
        $state = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        expect($state['diagnostics'])->toBe([])->and($state['automation_queries'])->not->toBeEmpty();
        if (isset($case['rejectDelete'])) expect(implode('\n', $state['log']))->toContain('owned deletion refused');
        foreach ($state['row_cache'] as $cache) {
            expect((int) $cache['user_id'])->toBe(1)->and($cache['hash'])->toMatch('/^[a-f0-9]{32}$/D')->and((int) $cache['total_rows'])->toBe(0);
        }
        if (isset($case['post'])) expect($state['csrf_checked'])->toBeTrue();
        if (isset($case['maxQueries'])) expect(count($state['automation_queries']))->toBeLessThanOrEqual($case['maxQueries']);
        if (isset($case['heading'])) {
            $document = new \DOMDocument();
            $prior = libxml_use_internal_errors(true);
            try {
                expect($document->loadHTML('<?xml encoding="UTF-8">' . $state['html']))->toBeTrue();
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($prior);
            }
            $xpath = new \DOMXPath($document);
            expect($xpath->query('//form[@action="' . $case['page'] . '"]')->length)->toBeGreaterThan(0);
            expect($document->textContent)->toContain($case['heading']);
            foreach ($case['labels'] ?? [] as $label) {
                expect($document->textContent)->toContain($label);
            }
            foreach ($case['absentLabels'] ?? [] as $label) {
                expect($document->textContent)->not->toContain($label);
            }
            foreach ($case['ids'] ?? [] as $id) {
                expect($xpath->query('//input[@name="chk_' . $id . '"]')->length)->toBe(1);
            }
            if (isset($case['rowCount'])) {
                expect($xpath->query('//tr[starts-with(@id,"line")]')->length)->toBe($case['rowCount']);
            }
        }
        if ($coverage !== null) {
            $sources = \NativeAutomationPresentation::sources();
            $markers = \NativeAutomationPresentation::markers(isset($case['post']));
            $hits = [$case['page']];
            if (isset($case['heading'])) $hits = array_merge($hits, ['lib/html.php', 'lib/html_form.php']);
            if (isset($case['post'])) $hits[] = 'include/csrf.php';
            $report = $directory . '/automation.coverage';
            $child = \NativeChildCoverageEvidence::load($report, $root, 'tests/Fixtures/automation-presentation-native.php', $input, $sources, $markers, $hits);
            if ($case['page'] === 'automation_devices.php') {
                expect(\NativeChildCoverageEvidence::verifyRejections($report, $root, 'tests/Fixtures/automation-presentation-native.php', $input, $sources, $markers, $hits, 'cli/refresh_csrf.php'))
                    ->toBe(count($sources) + count($markers) + 10);
            }
            $coverage->merge($child);
        }
    } finally {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
})->with([
    'graph delete preserves another rule and opposite child type' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'remove', 'id' => '7', 'confirm' => '1'],
        'writes' => ['automation_graph_rules', 'automation_graph_rule_items', 'automation_match_rule_items'], 'maxQueries' => 12,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule'], ['id' => 8, 'name' => 'Adjacent graph rule']],
            'automation_graph_rule_items' => [['id' => 71, 'rule_id' => 7], ['id' => 81, 'rule_id' => 8]],
            'automation_match_rule_items' => [['id' => 72, 'rule_id' => 7, 'rule_type' => \AUTOMATION_RULE_TYPE_GRAPH_MATCH], ['id' => 73, 'rule_id' => 7, 'rule_type' => \AUTOMATION_RULE_TYPE_TREE_MATCH], ['id' => 82, 'rule_id' => 8, 'rule_type' => \AUTOMATION_RULE_TYPE_GRAPH_MATCH]]],
        'deleted' => ['automation_graph_rules' => [['id' => 7]], 'automation_graph_rule_items' => [['id' => 71]], 'automation_match_rule_items' => [['id' => 72]]]]],
    'tree delete preserves another rule and opposite child type' => [['page' => 'automation_tree_rules.php', 'post' => ['action' => 'remove', 'id' => '7', 'confirm' => '1'],
        'writes' => ['automation_tree_rules', 'automation_tree_rule_items', 'automation_match_rule_items'], 'maxQueries' => 12,
        'rows' => ['automation_tree_rules' => [['id' => 7, 'name' => 'Selected tree rule'], ['id' => 8, 'name' => 'Adjacent tree rule']],
            'automation_tree_rule_items' => [['id' => 71, 'rule_id' => 7], ['id' => 81, 'rule_id' => 8]],
            'automation_match_rule_items' => [['id' => 72, 'rule_id' => 7, 'rule_type' => \AUTOMATION_RULE_TYPE_TREE_MATCH], ['id' => 73, 'rule_id' => 7, 'rule_type' => \AUTOMATION_RULE_TYPE_GRAPH_MATCH]]],
        'deleted' => ['automation_tree_rules' => [['id' => 7]], 'automation_tree_rule_items' => [['id' => 71]], 'automation_match_rule_items' => [['id' => 72]]]]],
    'graph query change updates selected row only' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'qedit', 'id' => '7', 'snmp_query_id' => '3', 'name' => 'Updated query rule'],
        'writes' => ['automation_graph_rules'], 'maxQueries' => 8,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule'], ['id' => 8, 'name' => 'Adjacent graph rule']], 'snmp_query' => [['id' => 3, 'name' => 'Interface query']], 'snmp_query_graph' => [['id' => 4, 'snmp_query_id' => 3, 'name' => 'Traffic graph']]],
        'updated' => ['automation_graph_rules' => [['id' => 7, 'values' => ['name' => 'Updated query rule', 'snmp_query_id' => 3, 'graph_type_id' => 4]]]]]],
    'graph enable submission with selected record' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'actions', 'drp_action' => (string) \AUTOMATION_ACTION_GRAPH_ENABLE, 'selected_items' => serialize(['7'])],
        'writes' => ['automation_graph_rules'], 'maxQueries' => 12,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule'], ['id' => 8, 'name' => 'Adjacent graph rule']]],
        'updated' => ['automation_graph_rules' => [['id' => 7, 'values' => ['enabled' => 'on']]]]]],
    'graph disable submission with selected record' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'actions', 'drp_action' => (string) \AUTOMATION_ACTION_GRAPH_DISABLE, 'selected_items' => serialize(['7'])],
        'writes' => ['automation_graph_rules'], 'maxQueries' => 12,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule', 'enabled' => 'on'], ['id' => 8, 'name' => 'Adjacent graph rule', 'enabled' => 'on']]],
        'updated' => ['automation_graph_rules' => [['id' => 7, 'values' => ['enabled' => '']]]]]],
    'template reorder retains ids and adjacent data' => [['page' => 'automation_templates.php', 'post' => ['action' => 'ajax_dnd', 'id' => '7', 'template_ids' => ['line8', 'line7']],
        'writes' => ['automation_templates'], 'maxQueries' => 8,
        'rows' => ['automation_templates' => [['id' => 7, 'sequence' => 1], ['id' => 8, 'sequence' => 2], ['id' => 9, 'sequence' => 3]]],
        'updated' => ['automation_templates' => [['id' => 7, 'values' => ['sequence' => 2]], ['id' => 8, 'values' => ['sequence' => 1]]]]]],
    'SNMP reorder retains other option set' => [['page' => 'automation_snmp.php', 'post' => ['action' => 'ajax_dnd', 'id' => '7', 'snmp_item' => ['line2', 'line1']],
        'writes' => ['automation_snmp_items'], 'maxQueries' => 8,
        'rows' => ['automation_snmp' => [['id' => 7, 'name' => 'Selected option set'], ['id' => 8, 'name' => 'Adjacent option set']],
            'automation_snmp_items' => [['id' => 1, 'snmp_id' => 7, 'sequence' => 1, 'snmp_community' => ''], ['id' => 2, 'snmp_id' => 7, 'sequence' => 2, 'snmp_community' => ''], ['id' => 3, 'snmp_id' => 8, 'sequence' => 1, 'snmp_community' => '']]],
        'updated' => ['automation_snmp_items' => [['id' => 1, 'values' => ['sequence' => 2]], ['id' => 2, 'values' => ['sequence' => 1]]]]]],
    'backend delete refusal preserves selected and adjacent template' => [['page' => 'automation_templates.php', 'post' => ['action' => 'remove', 'id' => '7'],
        'writes' => ['automation_templates'], 'rejectDelete' => 'automation_templates', 'maxQueries' => 20,
        'rows' => ['automation_templates' => [['id' => 7, 'sequence' => 1], ['id' => 8, 'sequence' => 2]]]]],
    'already enabled graph submission is idempotent' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'actions', 'drp_action' => (string) \AUTOMATION_ACTION_GRAPH_ENABLE, 'selected_items' => serialize(['7'])],
        'writes' => ['automation_graph_rules'], 'maxQueries' => 12,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Already enabled graph rule', 'enabled' => 'on'], ['id' => 8, 'name' => 'Adjacent graph rule']]]]],
    'graph delete confirmation' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'actions', 'drp_action' => (string) \AUTOMATION_ACTION_GRAPH_DELETE, 'chk_7' => 'on'], 'heading' => 'Selected graph rule', 'maxQueries' => 15,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule']]]]],
    'graph duplicate confirmation' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'actions', 'drp_action' => (string) \AUTOMATION_ACTION_GRAPH_DUPLICATE, 'chk_7' => 'on'], 'heading' => 'Selected graph rule', 'maxQueries' => 15,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule']]]]],
    'graph enable confirmation' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'actions', 'drp_action' => (string) \AUTOMATION_ACTION_GRAPH_ENABLE, 'chk_7' => 'on'], 'heading' => 'Selected graph rule', 'maxQueries' => 15,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule']]]]],
    'graph disable confirmation' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'actions', 'drp_action' => (string) \AUTOMATION_ACTION_GRAPH_DISABLE, 'chk_7' => 'on'], 'heading' => 'Selected graph rule', 'maxQueries' => 15,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule']]]]],
    'tree delete confirmation' => [['page' => 'automation_tree_rules.php', 'post' => ['action' => 'actions', 'drp_action' => (string) \AUTOMATION_ACTION_TREE_DELETE, 'chk_7' => 'on'], 'heading' => 'Selected tree rule', 'maxQueries' => 15,
        'rows' => ['automation_tree_rules' => [['id' => 7, 'name' => 'Selected tree rule']]]]],
    'tree duplicate confirmation' => [['page' => 'automation_tree_rules.php', 'post' => ['action' => 'actions', 'drp_action' => (string) \AUTOMATION_ACTION_TREE_DUPLICATE, 'chk_7' => 'on'], 'heading' => 'Selected tree rule', 'maxQueries' => 15,
        'rows' => ['automation_tree_rules' => [['id' => 7, 'name' => 'Selected tree rule']]]]],
    'graph type selection preserves query id' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'qedit', 'id' => '7', 'graph_type_id' => '4', 'name' => 'Updated graph type'],
        'writes' => ['automation_graph_rules'], 'maxQueries' => 8,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule', 'snmp_query_id' => 3], ['id' => 8, 'name' => 'Adjacent graph rule']], 'snmp_query' => [['id' => 3, 'name' => 'Interface query']], 'snmp_query_graph' => [['id' => 4, 'snmp_query_id' => 3, 'name' => 'Traffic graph']]],
        'updated' => ['automation_graph_rules' => [['id' => 7, 'values' => ['name' => 'Updated graph type', 'graph_type_id' => 4]]]]]],
    'template removal preserves adjacent order' => [['page' => 'automation_templates.php', 'post' => ['action' => 'remove', 'id' => '7'], 'writes' => ['automation_templates'], 'maxQueries' => 8,
        'rows' => ['automation_templates' => [['id' => 7, 'sequence' => 1], ['id' => 8, 'sequence' => 2]]], 'deleted' => ['automation_templates' => [['id' => 7]]]]],
    'graph item save reads real supported field and updates owned row' => [['page' => 'automation_graph_rules.php', 'post' => ['action' => 'save', 'id' => '7', 'item_id' => '77', 'sequence' => '1', 'operation' => '0', 'field' => 'ifName', 'operator' => '0', 'pattern' => 'eth0', 'save_component_automation_graph_rule_item' => '1'],
        'writes' => ['automation_graph_rule_items'], 'maxQueries' => 12,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Selected graph rule']], 'automation_graph_rule_items' => [['id' => 77, 'rule_id' => 7, 'sequence' => 1]], 'host_snmp_cache' => [['host_id' => 7, 'snmp_query_id' => 3, 'snmp_index' => '1', 'field_name' => 'ifName', 'field_value' => 'eth0', 'oid' => '.1.3.6.1.2.1.31.1.1.1.1.1']]],
        'updated' => ['automation_graph_rule_items' => [['id' => 77, 'values' => ['operation' => 0, 'field' => 'ifName', 'operator' => 0, 'pattern' => 'eth0']]]]]],
    'discovered device filter preserves supported network and status selection' => [['page' => 'automation_devices.php', 'request' => ['filter' => 'Edge', 'network' => '3', 'status' => 'Up', 'snmp' => 'Up'], 'heading' => 'Device Name', 'labels' => ['Edge gateway', 'Discovery network'], 'ids' => [7], 'rowCount' => 1,
        'rows' => ['automation_networks' => [['id' => 3, 'name' => 'Discovery network']], 'automation_devices' => [['id' => 7, 'network_id' => 3, 'hostname' => 'Edge gateway', 'ip' => '10.0.0.7', 'sysName' => 'edge-seven', 'sysLocation' => 'Building seven', 'sysContact' => 'Operations', 'sysDescr' => 'Network appliance', 'os' => 'Network OS', 'up' => 1, 'snmp' => 1, 'sysUptime' => 123456, 'time' => 1700000000]]]]],
    'SNMP delete confirmation preserves records' => [['page' => 'automation_snmp.php', 'post' => ['action' => 'actions', 'drp_action' => '1', 'chk_7' => 'on'], 'heading' => 'Selected option set', 'maxQueries' => 15,
        'rows' => ['automation_snmp' => [['id' => 7, 'name' => 'Selected option set']]]]],
    'SNMP duplicate confirmation preserves records' => [['page' => 'automation_snmp.php', 'post' => ['action' => 'actions', 'drp_action' => '2', 'chk_7' => 'on'], 'heading' => 'Selected option set', 'maxQueries' => 15,
        'rows' => ['automation_snmp' => [['id' => 7, 'name' => 'Selected option set']]]]],
    'empty discovered devices' => [['page' => 'automation_devices.php', 'heading' => 'No Devices Found']],
    'empty graph rules' => [['page' => 'automation_graph_rules.php', 'heading' => 'No Graph Rules Found']],
    'empty SNMP option sets' => [['page' => 'automation_snmp.php', 'heading' => 'No SNMP Option Sets Found']],
    'empty templates' => [['page' => 'automation_templates.php', 'heading' => 'No Automation Device Templates Found']],
    'empty tree rules' => [['page' => 'automation_tree_rules.php', 'heading' => 'No Tree Rules Found']],
    'persisted discovered device' => [['page' => 'automation_devices.php', 'heading' => 'Device Name', 'labels' => ['Edge gateway', 'edge-seven', 'Building seven'], 'ids' => [7], 'rowCount' => 1,
        'rows' => ['automation_devices' => [['id' => 7, 'hostname' => 'Edge gateway', 'ip' => '10.0.0.7', 'sysName' => 'edge-seven', 'sysLocation' => 'Building seven', 'sysContact' => 'Operations', 'sysDescr' => 'Network appliance', 'os' => 'Network OS', 'up' => 1, 'snmp' => 1, 'sysUptime' => 123456, 'time' => 1700000000]]]]],
    'persisted graph rule joins' => [['page' => 'automation_graph_rules.php', 'heading' => 'Rule Name', 'labels' => ['Edge graphs', 'Interface query', 'Traffic graph'], 'ids' => [7], 'rowCount' => 1,
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Edge graphs', 'snmp_query_id' => 3, 'graph_type_id' => 4, 'enabled' => 'on']],
            'snmp_query' => [['id' => 3, 'name' => 'Interface query']], 'snmp_query_graph' => [['id' => 4, 'snmp_query_id' => 3, 'name' => 'Traffic graph']]]]],
    'persisted SNMP counts' => [['page' => 'automation_snmp.php', 'heading' => 'SNMP Option Set', 'labels' => ['Edge SNMP'], 'ids' => [7], 'rowCount' => 1,
        'rows' => ['automation_snmp' => [['id' => 7, 'name' => 'Edge SNMP']],
            'automation_snmp_items' => [['id' => 1, 'snmp_id' => 7, 'snmp_version' => 1, 'snmp_community' => ''], ['id' => 2, 'snmp_id' => 7, 'snmp_version' => 2, 'snmp_community' => ''], ['id' => 3, 'snmp_id' => 7, 'snmp_version' => 3, 'snmp_community' => '']]]]],
    'persisted duplicate template names and order' => [['page' => 'automation_templates.php', 'heading' => 'Template Name', 'labels' => ['Enterprise routing', 'switch family'], 'ids' => [7, 8], 'rowCount' => 2,
        'rows' => ['host_template' => [['id' => 3, 'name' => 'Enterprise routing']],
            'automation_templates' => [['id' => 7, 'host_template' => 3, 'sequence' => 1, 'sysDescr' => 'switch family'], ['id' => 8, 'host_template' => 3, 'sequence' => 2, 'sysDescr' => 'switch family']]]]],
    'graph rule editor with persisted children' => [['page' => 'automation_graph_rules.php', 'request' => ['action' => 'edit', 'id' => 7], 'heading' => 'Graph Creation Criteria', 'labels' => ['Edge graphs'],
        'rows' => ['automation_graph_rules' => [['id' => 7, 'name' => 'Edge graphs', 'snmp_query_id' => 0, 'enabled' => 'on']]]]],
    'SNMP editor with persisted entries' => [['page' => 'automation_snmp.php', 'request' => ['action' => 'edit', 'id' => 7], 'heading' => 'SNMP Options',
        'rows' => ['automation_snmp' => [['id' => 7, 'name' => 'Edge SNMP']], 'automation_snmp_items' => [['id' => 1, 'snmp_id' => 7, 'snmp_version' => 2, 'snmp_community' => '']]]]],
    'automation template editor' => [['page' => 'automation_templates.php', 'request' => ['action' => 'edit', 'id' => 7], 'heading' => 'Device Template',
        'rows' => ['host_template' => [['id' => 3, 'name' => 'Enterprise routing']], 'automation_templates' => [['id' => 7, 'host_template' => 3]]]]],
    'tree rule editor with persisted children' => [['page' => 'automation_tree_rules.php', 'request' => ['action' => 'edit', 'id' => 7], 'heading' => 'Device Selection Criteria',
        'rows' => ['automation_tree_rules' => [['id' => 7, 'name' => 'Edge trees', 'leaf_type' => 3]]]]],
    'persisted tree and subtree joins' => [['page' => 'automation_tree_rules.php', 'heading' => 'Rule Name', 'labels' => ['Edge trees', 'Network tree', 'Routing branch'], 'ids' => [7], 'rowCount' => 1,
        'rows' => ['graph_tree' => [['id' => 2, 'name' => 'Network tree']], 'graph_tree_items' => [['id' => 9, 'graph_tree_id' => 2, 'title' => 'Routing branch']],
            'automation_tree_rules' => [['id' => 7, 'name' => 'Edge trees', 'tree_id' => 2, 'tree_item_id' => 9, 'leaf_type' => 3, 'host_grouping_type' => 1, 'enabled' => 'on']]]]],
]);
