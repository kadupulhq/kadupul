<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\PresentationSinkContextNative;

require_once __DIR__ . '/../Helpers/PresentationSinkContextEvidence.php';
require_once __DIR__ . '/../Helpers/NativeChildCoverageEvidence.php';

test('actual presentation consumers preserve encoded links and reject malformed identifiers', function (array $case): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/presentation-sink-context-native-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $input = json_encode($case, JSON_THROW_ON_ERROR);
    $environment = getenv();
    $environment['PRESENTATION_SINK_CONTEXT_COVERAGE'] = $coverage === null ? '0' : '1';
    try {
        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/presentation-sink-context-native.php', $root, $directory, $input],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/stdout', 'w'], 2 => ['file', $directory . '/stderr', 'w']],
            $pipes,
            $root,
            $environment
        );
        expect(is_resource($process))->toBeTrue();
        $status = proc_close($process);
        $errors = file_get_contents($directory . '/stderr');
        expect($status)->toBe(0, $errors)->and($errors)->toBe('');
        $result = json_decode(file_get_contents($directory . '/stdout'), true, 512, JSON_THROW_ON_ERROR);
        expect($result['diagnostics'])->toBe([]);
        $dom = new \DOMDocument();
        $prior = libxml_use_internal_errors(true);
        try {
            expect($dom->loadHTML('<?xml encoding="UTF-8">' . $result['html']))->toBeTrue();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $xpath = new \DOMXPath($dom);
        expect($xpath->query('//label//label | //script[@data-injected]')->length)->toBe(0);
        if (isset($case['filter']) && !($case['invalid'] ?? false)) {
            expect($result['filter_value'])->toBe($case['filter']['expected']);
            expect($result['filter_present'])->toBe($case['filter']['expected_present']);
        }
        if ($case['invalid'] ?? false) {
            expect($result['html'])->toContain('Validation error for variable');
            expect($result['module_queries'])->toBe([]);
        } elseif (in_array($case['page'], ['automation_snmp.php','color_templates.php'], true)) {
            $prefix = $case['page'] === 'automation_snmp.php' ? 'automation_snmp.php' : 'color_templates_items.php';
            expect(preg_match('/loadPageUsingPostChecked\(("(?:[^"\\\\]|\\\\.)*"), \$\.tableDnD\.serialize\(\)/', $result['html'], $matches))->toBe(1);
            $url = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            $key = $case['page'] === 'automation_snmp.php' ? 'id' : 'color_template_id';
            expect($url)->toBe($prefix . '?action=ajax_dnd&id=' . ($case['request'][$key] ?? 0));
        } elseif ($case['page'] === 'data_queries.php') {
            foreach (['item_movedown_gsv','item_moveup_gsv','item_remove_gsv','item_movedown_dssv','item_moveup_dssv','item_remove_dssv'] as $action) {
                $links = $xpath->query('//a[contains(@data-url,"action=' . $action . '&")]');
                expect($links->length)->toBeGreaterThan(0);
                foreach ($links as $link) {
                    expect($link->getAttribute('data-url'))->toContain('snmp_query_graph_id=7')->toContain('snmp_query_id=5');
                    if (!str_contains($action, 'remove')) expect($link->getAttribute('data-url'))->toContain("field_name=Field ' \" & ` <label>");
                    expect($link->attributes->length)->toBe(4);
                    foreach ($link->attributes as $attribute) expect($attribute->name)->toBeIn(['class','title','href','data-url']);
                }
            }
        } elseif ($case['page'] === 'data_templates.php') {
            expect($xpath->query('//a[contains(@href,"data_templates.php?action=template_edit&id=4&view_rrd=")]')->length)->toBe(2);
            expect($xpath->query('//a[contains(@data-url,"data_templates.php?action=rrd_remove&id=")]')->length)->toBe(2);
        } elseif ($case['page'] === 'data_sources.php') {
            expect($xpath->query('//a[@data-url="data_sources.php?action=ds_disable&id=7"]')->length)->toBe(1);
        }
        if ($coverage !== null) {
            $report = $directory . '/context.coverage';
            $sources = \PresentationSinkContextEvidence::sources();
            $markers = \PresentationSinkContextEvidence::markers();
            $hits = ($case['invalid'] ?? false) ? ['lib/html_utility.php','lib/html_validate.php'] : [$case['page'],'lib/html.php'];
            $child = \NativeChildCoverageEvidence::load($report, $root, 'tests/Fixtures/presentation-sink-context-native.php', $input, $sources, $markers, $hits);
            if (($case['page'] === 'data_queries.php') && !($case['invalid'] ?? false)) expect(\NativeChildCoverageEvidence::verifyRejections($report, $root, 'tests/Fixtures/presentation-sink-context-native.php', $input, $sources, $markers, $hits, 'cli/refresh_csrf.php'))->toBe(count($sources) + count($markers) + 10);
            $coverage->merge($child);
        }
    } finally {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
})->with([
    'persisted graph and datasource suggestions' => [['page' => 'data_queries.php','request' => ['action' => 'item_edit','id' => '7','snmp_query_id' => '5']]],
    'persisted SNMP option-set URL' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit','id' => '7']]],
    'missing SNMP identity retains zero URL' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit']]],
    'empty SNMP identity retains empty URL' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit','id' => '']]],
    'zero SNMP identity retains zero URL' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit','id' => '0']]],
    'persisted color item URL' => [['page' => 'color_templates.php','request' => ['action' => 'template_edit','color_template_id' => '7']]],
    'persisted datasource activation link' => [['page' => 'data_sources.php','request' => ['action' => 'ds_edit','id' => '7','host_id' => '0']]],
    'persisted template RRD tabs and removal links' => [['page' => 'data_templates.php','request' => ['action' => 'template_edit','id' => '4']]],
    'empty array SNMP identifier rejects before reads' => [['page' => 'automation_snmp.php','invalid' => true,'request' => ['action' => 'edit','id' => []]]],
    'nested array color identifier rejects before reads' => [['page' => 'color_templates.php','invalid' => true,'request' => ['action' => 'template_edit','color_template_id' => [['7']]]]],
    'array query identifier rejects before reads' => [['page' => 'data_queries.php','invalid' => true,'request' => ['action' => 'item_edit','id' => ['7'],'snmp_query_id' => '5']]],
    'array datasource identifier rejects before policy reads' => [['page' => 'data_sources.php','invalid' => true,'request' => ['action' => 'ds_edit','id' => ['7'],'host_id' => '0']]],
    'array template identifier rejects before reads' => [['page' => 'data_templates.php','invalid' => true,'request' => ['action' => 'template_edit','id' => []]]],
    'absent integer preserves absence' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => false,'value' => null,'mode' => 'integer','options' => [],'expected' => null,'expected_present' => false]]],
    'configured absent integer default' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => false,'value' => null,'mode' => 'integer','options' => ['default' => 17],'expected' => 17,'expected_present' => true]]],
    'null integer uses configured default' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => null,'mode' => 'integer','options' => ['default' => 17],'expected' => 17,'expected_present' => true]]],
    'empty integer preserves empty sentinel' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => '','mode' => 'integer','options' => [],'expected' => '','expected_present' => true]]],
    'zero integer preserves zero string' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => '0','mode' => 'integer','options' => [],'expected' => '0','expected_present' => true]]],
    'scalar integer retains normalization' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => '17','mode' => 'integer','options' => [],'expected' => 17,'expected_present' => true]]],
    'negative integer retains grammar' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => '-2','mode' => 'integer','options' => [],'expected' => -2,'expected_present' => true]]],
    'whitespace integer retains original grammar' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => ' 17 ','mode' => 'integer','options' => [],'expected' => 17,'expected_present' => true]]],
    'custom numeric array filter preserves values' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => [1,'2'],'mode' => 'numeric-array','options' => [],'expected' => [1,'2'],'expected_present' => true]]],
    'empty custom numeric array retains empty' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => [],'mode' => 'numeric-array','options' => [],'expected' => [],'expected_present' => true]]],
    'explicit required array integer mode' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => ['1','2'],'mode' => 'integer','options' => ['flags' => 'FILTER_REQUIRE_ARRAY'],'expected' => [1,2],'expected_present' => true]]],
    'explicit forced array integer mode' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => '7','mode' => 'integer','options' => ['flags' => 'FILTER_FORCE_ARRAY'],'expected' => [7],'expected_present' => true]]],
    'null configured default preserves absence' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => false,'value' => null,'mode' => 'integer','options' => ['default' => null],'expected' => null,'expected_present' => false]]],
    'undefined integer retains empty sentinel' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => 'undefined','mode' => 'integer','options' => [],'expected' => '','expected_present' => true]]],
    'fractional SNMP identifier retains rejection' => [['page' => 'automation_snmp.php','invalid' => true,'request' => ['action' => 'edit','id' => '1.5']]],
    'exponent color identifier retains rejection' => [['page' => 'color_templates.php','invalid' => true,'request' => ['action' => 'template_edit','color_template_id' => '1e2']]],
    'numeric string required-array flag preserves values' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => ['1','2'],'mode' => 'integer','options' => ['flags' => 'FILTER_REQUIRE_ARRAY'],'string_flags' => true,'expected' => [1,2],'expected_present' => true]]],
    'numeric string forced-array flag preserves scalar' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => '7','mode' => 'integer','options' => ['flags' => 'FILTER_FORCE_ARRAY'],'string_flags' => true,'expected' => [7],'expected_present' => true]]],
    'numeric string integer filter retains scalar' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => '17','mode' => 'integer-string','options' => [],'expected' => 17,'expected_present' => true]]],
    'numeric string integer filter rejects empty array' => [['page' => 'automation_snmp.php','invalid' => true,'request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => [],'mode' => 'integer-string','options' => []]]],
    'numeric string integer filter and array flag preserve values' => [['page' => 'automation_snmp.php','request' => ['action' => 'edit'],'filter' => ['present' => true,'value' => ['1','2'],'mode' => 'integer-string','options' => ['flags' => 'FILTER_REQUIRE_ARRAY'],'string_flags' => true,'expected' => [1,2],'expected_present' => true]]],
]);
