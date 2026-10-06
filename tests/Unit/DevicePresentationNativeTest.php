<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\DevicePresentationNative;

require_once __DIR__ . '/../Helpers/DeviceRouteNativeHarness.php';
require_once __DIR__ . '/../Helpers/DeviceRouteCoverageRegistration.php';
require_once __DIR__ . '/../Helpers/NativeChildCoverageEvidence.php';

test('migrated device presentation retains current routes and persisted selections', function (array $case, array $expected): void {
    $state = \DeviceRouteNativeHarness::run(['kind' => 'presentation'] + $case, $this->getTestResultObject()->getCodeCoverage());
    expect($state['stderr'])->toBe('')->and($state['before'])->toBe($state['after']);
    expect($state['legacy']['status'])->toBe($expected['legacy'] ?? 302);
    expect(count($state['statements']))->toBeLessThanOrEqual(30);
    if (($expected['legacy'] ?? 302) === 409) {
        expect($state['legacy']['html'])->toContain('legacy form has expired')->and($state['legacy']['location'])->toBeNull()->and($state['responses'])->toBe([]);
        foreach ($state['statements'] as $sql) expect($sql)->not->toMatch('/\b(?:FROM|JOIN)\s+(?:host|graph_local|data_local)\b/i');
    }
    if (isset($expected['query'])) {
        parse_str($expected['query'], $query);
        parse_str(parse_url($state['legacy']['location'], PHP_URL_QUERY) ?? '', $actual);
        foreach ($query as $key => $value) expect($actual[$key] ?? null)->toBe($value);
    }
    if (isset($expected['locations'])) {
        expect(json_decode($state['legacy']['html'], true, 512, JSON_THROW_ON_ERROR))->toBe($expected['locations']);
    }
    if (isset($expected['ids'])) {
        $json = json_decode($state['responses'][0]['html'], true, 512, JSON_THROW_ON_ERROR);
        expect($state['responses'][0]['status'])->toBe(200)->and(array_column($json['devices'], 'id'))->toBe($expected['ids']);
        expect($json['pageSize'])->toBe(25)->and($json['hasNext'])->toBe($expected['hasNext'] ?? false);
        foreach ($state['rendered_lists'] as $list) {
            expect($list['status'])->toBe(200);
            $html = $list['html'];
            expect($html)->toContain('/inventory/devices')->toContain('Devices');
            foreach ($json['devices'] as $device) expect($html)->toContain(htmlspecialchars($device['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

    }
    if (isset($expected['form'])) {
        $html = $state['responses'][0]['html'];
        expect($state['responses'][0]['status'])->toBe(200);
        $document = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        try {
            expect($document->loadHTML('<?xml encoding="UTF-8">' . $html))->toBeTrue();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $xpath = new \DOMXPath($document);
        expect($xpath->query('//form[@name="' . $expected['form'] . '" and @method="post"]')->length)->toBe(1);
        expect($xpath->query('//input[@name="' . $expected['form'] . '[_token]"]')->length)->toBe(1);
        foreach ($expected['text'] ?? [] as $text) expect($document->textContent)->toContain($text);
    }
})->with(function (): array {
    $host = ['id' => 7, 'description' => 'Device & alpha', 'hostname' => 'example.test', 'site_id' => 1, 'location' => 'Rack A', 'snmp_version' => 0, 'availability_method' => 0];
    $rows = ['host' => [$host, array_replace($host, ['id' => 8, 'description' => 'Beta', 'disabled' => 'on', 'site_id' => 0, 'host_template_id' => 1, 'status' => 1, 'location' => 'Rack B']), array_replace($host, ['id' => 9, 'description' => 'Hidden deleted', 'deleted' => 'on'])], 'sites' => [['id' => 1, 'name' => 'Main & site']], 'poller' => [['id' => 1, 'name' => 'Main']], 'host_template' => [['id' => 1, 'name' => 'Template & alpha']]];
    $list = static fn(array $fields, string $path, array $ids, array $seed = []): array => [['fields' => $fields, 'rows' => $seed ?: $rows, 'json' => true], ['ids' => $ids, 'query' => parse_url($path, PHP_URL_QUERY) ?? '']];
    $cases = [
        'empty list' => [['fields' => [], 'json' => true], ['ids' => []]],
        'new editor' => [['fields' => ['action' => 'edit'], 'rows' => $rows], ['form' => 'device_create']],
        'existing editor' => [['fields' => ['action' => 'edit', 'id' => '7'], 'rows' => $rows], ['form' => 'device_edit', 'text' => ['Main & site']]],
        'all undeleted devices' => $list([], '/inventory/devices.json', [8, 7]),
        'description search' => $list(['filter' => 'alpha'], '/inventory/devices.json?q=alpha', [7]),
        'enabled devices' => $list(['host_status' => '-3'], '/inventory/devices.json?state=enabled', [7]),
        'disabled devices' => $list(['host_status' => '-2'], '/inventory/devices.json?status=disabled', [8]),
        'site omission sentinel' => $list(['site_id' => '0'], '/inventory/devices.json?site=0', [8]),
        'template identity' => $list(['host_template_id' => '1'], '/inventory/devices.json?template=1', [8]),
    ];
    $page = $rows;
    $page['host'] = [];
    for ($i = 0; $i < 28; $i++) $page['host'][] = array_replace($host, ['id' => 100 + $i, 'description' => sprintf('Page %02d', $i)]);
    $cases['second page uses current bounded size and rejects old rows one'] = [['fields' => ['rows' => '1', 'page' => '2'], 'rows' => $page, 'paths' => ['/inventory/devices.json?page=2&size=25']], ['legacy' => 400, 'ids' => [125, 126, 127]]];
    $associated = $rows + ['graph_templates' => [['id' => 4, 'name' => 'Traffic & alpha']], 'host_graph' => [['host_id' => 7, 'graph_template_id' => 4]], 'snmp_query' => [['id' => 3, 'name' => 'Interfaces & alpha', 'data_input_id' => 1]], 'host_snmp_query' => [['host_id' => 7, 'snmp_query_id' => 3, 'reindex_method' => 0]]];
    $cases['persisted associations use dedicated current forms'] = [['fields' => ['action' => 'query_add', 'host_id' => '7'], 'rows' => $associated], ['form' => 'device_association', 'text' => ['Interfaces & alpha']]];
    foreach (range(1, 7) as $mode) $cases['legacy bulk confirmation ' . $mode . ' expires'] = [['fields' => ['action' => 'actions', 'drp_action' => (string) $mode, 'chk_7' => 'on', 'chk_8' => 'on'], 'method' => 'POST', 'rows' => $rows], ['legacy' => 409]];
    $cases['invalid legacy save preserves existing device'] = [['fields' => ['action' => 'save', 'id' => '7', 'description' => ''], 'method' => 'POST', 'rows' => $rows], ['legacy' => 409]];
    $cases['AJAX location choices use label value contract'] = [['fields' => ['action' => 'ajax_locations', 'term' => 'Rack'], 'rows' => $rows], ['legacy' => 200, 'locations' => [['label' => 'Rack A', 'value' => 'Rack A'], ['label' => 'Rack B', 'value' => 'Rack B']]]];
    $scoped = $rows + ['user_auth_perms' => [['user_id' => 42, 'item_id' => 8, 'type' => 3]]];
    $cases['AJAX locations follow current actor visibility rather than remembered site'] = [['fields' => ['action' => 'ajax_locations', 'term' => 'Rack'], 'rows' => $scoped], ['legacy' => 200, 'locations' => [['label' => 'Rack A', 'value' => 'Rack A']]]];
    $cases['AJAX missing location returns no fabricated choices'] = [['fields' => ['action' => 'ajax_locations', 'term' => 'New rack'], 'rows' => $rows], ['legacy' => 200, 'locations' => []]];
    $cases['persisted site selection'] = $list(['site_id' => '1'], '/inventory/devices.json?site=1', [7]);
    $cases['persisted collector selection'] = $list(['poller_id' => '1'], '/inventory/devices.json?collector=1', [8, 7]);
    $cases['persisted location selection'] = $list(['location' => 'Rack A'], '/inventory/devices.json?location_mode=exact&location=Rack%20A', [7]);
    return $cases;
});
