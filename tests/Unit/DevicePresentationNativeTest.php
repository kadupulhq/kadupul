<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\DevicePresentationNative;

require_once __DIR__ . '/../Helpers/NativeDevicePresentation.php';
require_once __DIR__ . '/../Helpers/NativeChildCoverageEvidence.php';

test('device presentation retains persisted selections and reachable actions', function (array $case, array $expected): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/device-presentation-native-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $input = json_encode($case, JSON_THROW_ON_ERROR);
    $environment = getenv();
    $environment['DEVICE_PRESENTATION_COVERAGE'] = $coverage === null ? '0' : '1';
    try {
        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr',
                '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/device-presentation-native.php', $root, $directory, $input],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/stderr', 'w']],
            $pipes,
            $root,
            $environment
        );
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        $errors = file_get_contents($directory . '/stderr');
        expect($exit)->toBe(0, $errors)->and($errors)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        expect($result['diagnostics'])->toBe([])->and($result['device_queries'])->not->toBeEmpty();
        if (isset($expected['errors'])) {
            expect(array_keys($result['session']['sess_error_fields'] ?? []))->toBe($expected['errors']);
        }
        $document = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        try {
            expect($document->loadHTML('<?xml encoding="UTF-8">' . ($result['html'] === '' || isset($expected['locations']) ? '<html><body></body></html>' : $result['html'])))->toBeTrue();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $xpath = new \DOMXPath($document);
        if (isset($expected['locations'])) {
            expect(json_decode($result['html'], true, 512, JSON_THROW_ON_ERROR))->toBe($expected['locations']);
        } elseif (isset($expected['errors'])) {
            expect($result['html'])->toBe('');
        } elseif (isset($expected['confirm'])) {
            expect($xpath->evaluate('string(//input[@name="action"]/@value)'))->toBe('actions');
            expect($xpath->evaluate('string(//input[@name="drp_action"]/@value)'))->toBe((string) $expected['confirm']);
            $selected = unserialize($xpath->evaluate('string(//input[@name="selected_items"]/@value)'), ['allowed_classes' => false]);
            expect($selected)->toBe(['7', '8']);
            expect($xpath->query('//input[@type="submit" and @value="Continue"]')->length)->toBe(1);
            expect($document->textContent)->toContain('Device & alpha')->toContain('Beta');
        } elseif (isset($expected['ids'])) {
            expect($result['row_cache'])->toHaveCount(1);
            $cache = $result['row_cache'][0];
            expect((int) $cache['user_id'])->toBe(1)->and($cache['class'])->toBe('device');
            expect((int) $cache['total_rows'])->toBe($expected['total'] ?? count($expected['ids']));
            expect($result['count_hashes'])->toBe([$cache['hash']]);
            expect(strtotime($cache['time']))->not->toBeFalse();
            $actual = [];
            foreach ($xpath->query('//input[@type="checkbox" and starts-with(@name,"chk_")]') as $checkbox) {
                $actual[] = (int) substr($checkbox->getAttribute('name'), 4);
            }
            expect($actual)->toBe($expected['ids']);
            expect($xpath->query('//form[@id="chk" and @action="host.php" and @method="post"]')->length)->toBe(1);
        } else {
            expect($xpath->query('//form[@id="host_form" and @action="host.php" and @method="post"]')->length)->toBe(1);
            expect($xpath->evaluate('string(//input[@name="id"]/@value)'))->toBe((string) $expected['id']);
            expect($xpath->evaluate('string(//input[@name="action"]/@value)'))->toBe('save');
            if ($expected['id'] > 0) {
                expect($xpath->evaluate('string(//input[@name="description"]/@value)'))->toBe('Device & alpha');
                expect($xpath->query('//a[contains(@href,"graphs_new.php") and contains(@href,"host_id=7")]')->length)->toBe(1);
                expect($xpath->query('//button[@data-post-action="true" and contains(@data-url,"action=reindex") and contains(@data-url,"host_id=7")]')->length)->toBe(1);
            }
        }
        foreach ($expected['text'] ?? [] as $text) expect($document->textContent)->toContain($text);
        foreach ($expected['attributes'] ?? [] as $selector => $count) expect($xpath->query($selector)->length)->toBe($count);
        if (!isset($expected['ids'])) expect($result['row_cache'])->toBe([]);
        expect(count($result['device_queries']))->toBeLessThanOrEqual(12);
        if ($coverage !== null) {
            $sources = \NativeDevicePresentation::sources();
            $markers = \NativeDevicePresentation::markers();
            $report = $directory . '/device.coverage';
            $hits = isset($expected['errors']) || isset($expected['locations']) ? ['host.php', 'lib/functions.php'] : ['host.php', 'lib/html_form.php'];
            $child = \NativeChildCoverageEvidence::load($report, $root, 'tests/Fixtures/device-presentation-native.php', $input, $sources, $markers, $hits);
            if (!empty($expected['controls'])) {
                expect(\NativeChildCoverageEvidence::verifyRejections($report, $root, 'tests/Fixtures/device-presentation-native.php', $input, $sources, $markers, ['host.php', 'lib/html_form.php'], 'cli/refresh_csrf.php'))->toBe(count($sources) + count($markers) + 10);
            }
            $coverage->merge($child);
        }
    } finally {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($directory);
    }
})->with(function (): array {
    $host = ['id' => 7, 'description' => 'Device & alpha', 'hostname' => 'example.test', 'snmp_version' => 0, 'availability_method' => 0];
    $rows = ['host' => [$host, array_replace($host, ['id' => 8, 'description' => 'Beta', 'disabled' => 'on', 'site_id' => 0, 'host_template_id' => 1, 'status' => 1]),
        array_replace($host, ['id' => 9, 'description' => 'Hidden deleted', 'deleted' => 'on'])],
        'sites' => [['id' => 1, 'name' => 'Main & site']], 'poller' => [['id' => 1, 'name' => 'Main']],
        'host_template' => [['id' => 1, 'name' => 'Template & alpha']]];
    $cases = ['empty list' => [['request' => []], ['ids' => [], 'text' => ['No Devices Found']]],
        'new editor' => [['request' => ['action' => 'edit']], ['id' => 0]],
        'existing editor' => [['request' => ['action' => 'edit', 'id' => 7], 'rows' => $rows], ['id' => 7]],
        'all undeleted devices' => [['request' => [], 'rows' => $rows], ['ids' => [8, 7], 'controls' => true]],
        'description search' => [['request' => ['filter' => 'alpha'], 'rows' => $rows], ['ids' => [7]]],
        'enabled devices' => [['request' => ['host_status' => -3], 'rows' => $rows], ['ids' => [7]]],
        'disabled devices' => [['request' => ['host_status' => -2], 'rows' => $rows], ['ids' => [8]]],
        'site omission sentinel' => [['request' => ['site_id' => 0], 'rows' => $rows], ['ids' => [8]]],
        'template identity' => [['request' => ['host_template_id' => 1], 'rows' => $rows], ['ids' => [8]]],
        'second page' => [['request' => ['rows' => 1, 'page' => 2], 'rows' => $rows], ['ids' => [7], 'total' => 2]]];
    $associated = $rows + ['graph_templates' => [['id' => 4, 'name' => 'Traffic & alpha']],
        'host_graph' => [['host_id' => 7, 'graph_template_id' => 4]], 'graph_local' => [['id' => 11, 'host_id' => 7, 'graph_template_id' => 4]],
        'snmp_query' => [['id' => 3, 'name' => 'Interfaces & alpha', 'data_input_id' => 1]],
        'host_snmp_query' => [['host_id' => 7, 'snmp_query_id' => 3, 'reindex_method' => 0]]];
    $cases['persisted graph and query associations'] = [['request' => ['action' => 'edit', 'id' => 7], 'rows' => $associated], ['id' => 7,
        'text' => ['Traffic & alpha', 'Is Being Graphed', 'Interfaces & alpha', '[0 Items, 0 Rows]'],
        'attributes' => ['//a[contains(@href,"graphs.php?action=graph_edit") and contains(@href,"id=11")]' => 1,
            '//span[@id="reload3" and @data-id="3"]' => 1, '//span[@id="remove3" and @data-id="3"]' => 1]]];
    foreach ([1, 2, 3, 4, 5, 6, 7] as $action) {
        $cases['bulk confirmation ' . $action] = [['request' => ['action' => 'actions', 'drp_action' => (string) $action],
            'post' => ['chk_7' => 'on', 'chk_8' => 'on'], 'rows' => $rows], ['confirm' => $action]];
    }
    $save = array_replace($host, ['action' => 'save', 'save_component_host' => '1', 'description' => '', 'host_template_id' => '0',
        'snmp_port' => '161', 'snmp_timeout' => '500', 'poller_id' => '1', 'site_id' => '1', 'max_oids' => '10', 'bulk_walk_size' => '-1',
        'ping_method' => '0', 'ping_port' => '0', 'ping_timeout' => '500', 'ping_retries' => '2', 'device_threads' => '1']);
    $cases['invalid save preserves existing device'] = [['request' => $save, 'post' => [], 'rows' => $rows], ['errors' => ['description']]];
    $locations = $rows;
    $locations['host'][0]['location'] = 'Rack A';
    $locations['host'][1]['location'] = 'Rack B';
    $cases['AJAX location choices'] = [['request' => ['action' => 'ajax_locations', 'term' => 'Rack'], 'session' => ['cur_device_id' => 7], 'rows' => $locations],
        ['locations' => [['label' => 'Rack A', 'value' => 'Rack A', 'id' => 'Rack A'], ['label' => 'Rack B', 'value' => 'Rack B', 'id' => 'Rack B']]]];
    $cases['AJAX locations scoped to persisted site'] = [['request' => ['action' => 'ajax_locations', 'term' => 'Rack'], 'session' => ['cur_device_id' => 7],
        'settings' => ['site_location_filter' => 'on'], 'rows' => $locations], ['locations' => [['label' => 'Rack A', 'value' => 'Rack A', 'id' => 'Rack A']]]];
    $cases['AJAX missing location retains entered choice'] = [['request' => ['action' => 'ajax_locations', 'term' => 'New rack'], 'session' => ['cur_device_id' => 7], 'rows' => $locations],
        ['locations' => [['label' => 'New rack', 'value' => 'New rack', 'id' => 'New rack'], ['label' => 'None', 'value' => '', 'id' => 'None']]]];
    $cases['persisted site selection'] = [['request' => ['site_id' => 1], 'rows' => $rows], ['ids' => [7], 'attributes' => ['//select[@id="site_id"]/option[@value="1" and @selected]' => 1]]];
    $cases['persisted collector selection'] = [['request' => ['poller_id' => 1], 'rows' => $rows], ['ids' => [8, 7], 'attributes' => ['//select[@id="poller_id"]/option[@value="1" and @selected]' => 1]]];
    $cases['persisted location selection'] = [['request' => ['location' => 'Rack A'], 'rows' => $locations], ['ids' => [7], 'attributes' => ['//select[@id="location"]/option[@value="Rack A" and @selected]' => 1]]];
    return $cases;
});
