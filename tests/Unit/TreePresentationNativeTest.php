<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\TreePresentationNative;

require_once __DIR__ . '/../Helpers/NativeTreePresentation.php';
require_once __DIR__ . '/../Helpers/NativeChildCoverageEvidence.php';

test('tree presentation retains persisted ordering selections and edit controls', function (array $case, array $expected): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/tree-presentation-native-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $input = json_encode($case, JSON_THROW_ON_ERROR);
    $environment = getenv();
    $environment['TREE_PRESENTATION_COVERAGE'] = $coverage === null ? '0' : '1';
    try {
        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/tree-presentation-native.php', $root, $directory, $input],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/stderr', 'w']],
            $pipes,
            $root,
            $environment
        );
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $errors = file_get_contents($directory . '/stderr');
        expect($status)->toBe(0, $errors)->and($errors)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        expect($result['diagnostics'])->toBe([]);
        $document = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        try {
            expect($document->loadHTML('<?xml encoding="UTF-8">' . ($result['html'] === '' ? '<html><body></body></html>' : $result['html'])))->toBeTrue();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $xpath = new \DOMXPath($document);
        if (isset($expected['response'])) {
            expect($result['html'])->toBe($expected['response']);
        } elseif (isset($expected['confirm'])) {
            expect($xpath->evaluate('string(//input[@name="action"]/@value)'))->toBe('actions');
            expect($xpath->evaluate('string(//input[@name="drp_action"]/@value)'))->toBe((string) $expected['confirm']);
            expect(unserialize($xpath->evaluate('string(//input[@name="selected_items"]/@value)'), ['allowed_classes' => false]))->toBe(['7', '8']);
            expect($xpath->query('//input[@type="submit" and @value="Continue"]')->length)->toBe(1);
            expect($document->textContent)->toContain('Tree & alpha')->toContain('Beta');
        } elseif (isset($expected['ids'])) {
            $actual = [];
            foreach ($xpath->query('//input[@type="checkbox" and starts-with(@name,"chk_")]') as $checkbox) $actual[] = (int) substr($checkbox->getAttribute('name'), 4);
            expect($actual)->toBe($expected['ids']);
            expect($xpath->query('//form[@id="chk" and @action="tree.php" and @method="post"]')->length)->toBe(1);
            expect($result['row_cache'])->toHaveCount(1);
            $cache = $result['row_cache'][0];
            expect((int) $cache['user_id'])->toBe(1)->and($cache['class'])->toBe('tree');
            expect((int) $cache['total_rows'])->toBe($expected['total'] ?? count($expected['ids']));
            expect($result['count_hashes'])->toBe([$cache['hash']]);
        } else {
            expect($xpath->query('//form[@id="tree_edit" and @action="tree.php" and @method="post"]')->length)->toBe(1);
            expect($xpath->evaluate('string(//input[@name="id"]/@value)'))->toBe((string) $expected['id']);
            if ($expected['id'] > 0) expect($xpath->evaluate('string(//input[@name="name"]/@value)'))->toBe('Tree & alpha');
        }
        foreach ($expected['attributes'] ?? [] as $selector => $count) expect($xpath->query($selector)->length)->toBe($count);
        foreach ($expected['text'] ?? [] as $text) expect($document->textContent)->toContain($text);
        expect(count($result['tree_queries']))->toBeLessThanOrEqual(15);
        if (isset($expected['id']) && $expected['id'] > 0) {
            expect($result['row_cache'])->toHaveCount(1);
            $cache = $result['row_cache'][0];
            expect((int) $cache['user_id'])->toBe(1)->and($cache['class'])->toBe('device');
            expect((int) $cache['total_rows'])->toBe($expected['device_total'] ?? 0);
            expect($result['count_hashes'])->toBe([$cache['hash']]);
        } elseif (!isset($expected['ids'])) expect($result['row_cache'])->toBe([]);
        if ($coverage !== null) {
            $sources = \NativeTreePresentation::sources();
            $markers = \NativeTreePresentation::markers();
            $report = $directory . '/tree.coverage';
            // Lookup/write endpoints do not render a form. Their real controller
            // execution remains mandatory; rendering scenarios also require the
            // physical form helper rather than an included-but-unused file.
            $hitSources = isset($expected['response']) ? ['tree.php'] : ['tree.php', 'lib/html_form.php'];
            $child = \NativeChildCoverageEvidence::load($report, $root, 'tests/Fixtures/tree-presentation-native.php', $input, $sources, $markers, $hitSources);
            if (!empty($expected['controls'])) expect(\NativeChildCoverageEvidence::verifyRejections($report, $root, 'tests/Fixtures/tree-presentation-native.php', $input, $sources, $markers, $hitSources, 'cli/refresh_csrf.php'))->toBe(count($sources) + count($markers) + 10);
            $coverage->merge($child);
        }
    } finally {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($directory);
    }
})->with(function (): array {
    $tree = ['id' => 7, 'name' => 'Tree & alpha', 'sequence' => 1, 'sort_type' => 'TREE_ORDERING_NONE', 'enabled' => 'on'];
    $rows = ['graph_tree' => [$tree, array_replace($tree, ['id' => 8, 'name' => 'Beta', 'sequence' => 2, 'enabled' => '']),
        array_replace($tree, ['id' => 9, 'name' => 'Gamma', 'sequence' => 3])]];
    $cases = ['empty tree list' => [['request' => []], ['ids' => [], 'text' => ['No Trees Found']]],
        'new tree editor' => [['request' => ['action' => 'edit']], ['id' => 0]],
        'existing unlocked editor' => [['request' => ['action' => 'edit', 'id' => 7], 'rows' => $rows], ['id' => 7, 'attributes' => ['//input[@id="lock"]' => 1]]],
        'three persisted ordered trees' => [['request' => [], 'rows' => $rows], ['ids' => [7, 8, 9], 'controls' => true,
            'attributes' => ['//a[contains(concat(" ",normalize-space(@class)," ")," cactiPostAction ") and @href="#" and contains(@data-url,"action=tree_down") and contains(@data-url,"id=7")]' => 1, '//a[contains(concat(" ",normalize-space(@class)," ")," cactiPostAction ") and @href="#" and contains(@data-url,"action=tree_up") and contains(@data-url,"id=9")]' => 1]]],
        'tree name search' => [['request' => ['filter' => 'alpha'], 'rows' => $rows], ['ids' => [7]]],
        'second tree page' => [['request' => ['rows' => 1, 'page' => 2], 'rows' => $rows], ['ids' => [8], 'total' => 3]],
        'reverse tree order' => [['request' => ['sort_column' => 'name', 'sort_direction' => 'DESC'], 'rows' => $rows], ['ids' => [7, 9, 8]]]];
    foreach ([1, 2, 3, 4] as $action) $cases['tree bulk confirmation ' . $action] = [['request' => ['action' => 'actions', 'drp_action' => (string) $action], 'post' => ['chk_7' => 'on', 'chk_8' => 'on'], 'rows' => $rows], ['confirm' => $action]];
    $locked = $rows;
    $locked['graph_tree'][0]['locked'] = 1;
    $locked['graph_tree'][0]['modified_by'] = 1;
    $locked['graph_tree'][0]['locked_date'] = '2026-01-01 12:00:00';
    $cases['owned locked tree editor'] = [['request' => ['action' => 'edit', 'id' => 7], 'rows' => $locked], ['id' => 7, 'attributes' => ['//input[@id="unlock"]' => 1, '//input[@id="addbranch"]' => 1]]];
    foreach (['tree_down' => 7, 'tree_up' => 8] as $action => $id) {
        $cases[$action . ' preserves adjacent tree metadata'] = [['request' => ['action' => $action, 'id' => $id], 'post' => [], 'mode' => 'sequence', 'rows' => $rows,
            'changes' => ['graph_tree' => [7 => ['sequence' => 2], 8 => ['sequence' => 1]]]], ['response' => '']];
    }
    $branches = $rows + ['graph_tree_items' => [['id' => 21, 'graph_tree_id' => 7, 'title' => 'Branch & alpha', 'host_grouping_type' => 'HOST_GROUPING_GRAPH_TEMPLATE'],
        ['id' => 22, 'graph_tree_id' => 8, 'title' => 'Adjacent branch', 'host_grouping_type' => 'HOST_GROUPING_GRAPH_TEMPLATE']]];
    $cases['actual host grouping lookup'] = [['request' => ['action' => 'get_host_sort', 'nodeid' => 'tbranch:21'], 'rows' => $branches], ['response' => 'hsgt']];
    $cases['missing host grouping lookup'] = [['request' => ['action' => 'get_host_sort', 'nodeid' => 'tbranch:999'], 'rows' => $branches], ['response' => '']];
    $cases['persisted host grouping change'] = [['request' => ['action' => 'set_host_sort', 'nodeid' => 'tbranch:21', 'type' => 'hsdq'], 'post' => [], 'mode' => 'host-sort', 'rows' => $branches,
        'changes' => ['graph_tree_items' => [21 => ['host_grouping_type' => 'HOST_GROUPING_DATA_QUERY_INDEX']]]], ['response' => '']];
    foreach (['inherit' => 'TREE_ORDERING_INHERIT', 'manual' => 'TREE_ORDERING_NONE', 'alpha' => 'TREE_ORDERING_ALPHABETIC',
        'natural' => 'TREE_ORDERING_NATURAL', 'numeric' => 'TREE_ORDERING_NUMERIC'] as $type => $value) {
        $cases['persisted branch sort ' . $type] = [['request' => ['action' => 'set_branch_sort', 'nodeid' => 'tbranch:21', 'type' => $type], 'post' => [], 'mode' => 'branch-sort', 'rows' => $branches,
            'changes' => ['graph_tree_items' => [21 => ['sort_children_type' => $value]]]], ['response' => '']];
        $lookup = $branches;
        $lookup['graph_tree_items'][0]['sort_children_type'] = $value;
        $cases['actual branch sort lookup ' . $type] = [['request' => ['action' => 'get_branch_sort', 'nodeid' => 'tbranch:21'], 'rows' => $lookup], ['response' => $type]];
    }
    return $cases;
});
