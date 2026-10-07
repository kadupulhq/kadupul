<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\ReportConsumersNative;

require_once __DIR__ . '/../Helpers/NativeReportConsumers.php';
require_once __DIR__ . '/../Helpers/NativeChildCoverageEvidence.php';

test('actual report controller retains item identities and bounds persisted bulk outcomes', function (array $case): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/report-consumers-native-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $input = json_encode($case, JSON_THROW_ON_ERROR);
    $environment = getenv();
    $environment['REPORT_CONSUMERS_COVERAGE'] = $coverage === null ? '0' : '1';
    try {
        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/report-consumers-native.php', $root, $directory, $input],
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
        expect(array_values(array_filter($result['report_state']['reports_items'], static fn(array $row): bool => $row['report_id'] === 8)))->toHaveCount(1);
        if ($case['mode'] === 'edit') {
            $dom = new \DOMDocument();
            $prior = libxml_use_internal_errors(true);
            try {
                expect($dom->loadHTML('<?xml encoding="UTF-8">' . $result['html']))->toBeTrue();
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($prior);
            }
            $xpath = new \DOMXPath($dom);
            expect($xpath->evaluate('string(//input[@name="id"]/@value)'))->toBe('1');
            expect($xpath->evaluate('string(//input[@name="report_id"]/@value)'))->toBe('7');
            expect($xpath->evaluate('string(//input[@name="save_component_report_item"]/@value)'))->toBe('1');
            expect($result['html'])->toContain('Router')->toContain('Graph template')->toContain('Graph');
            expect($xpath->query('//one|//note')->length)->toBe(0);
        } elseif (in_array($case['mode'], ['enable','disable','own'], true)) {
            $report = array_values(array_filter($result['report_state']['reports'], static fn(array $row): bool => $row['id'] === 7))[0];
            if ($case['mode'] === 'own') expect($report['user_id'])->toBe(1);
            else expect($report['enabled'])->toBe($case['mode'] === 'enable' ? 'on' : '');
        } elseif ($case['mode'] === 'missing-email') {
            expect(json_encode($result['report_messages']))->toContain('Please set destination e-mail addresses');
        } elseif ($case['mode'] === 'invalid-new-item') {
            expect($result['report_errors'])->not->toBe([]);
            expect($result['report_state']['reports_items'])->toHaveCount(2);
        }
        if ($coverage !== null) {
            $report = $directory . '/report.coverage';
            $sources = \NativeReportConsumers::sources();
            $markers = \NativeReportConsumers::markers();
            $hits = ['lib/html_reports.php', 'lib/database.php'];
            $child = \NativeChildCoverageEvidence::load($report, $root, 'tests/Fixtures/report-consumers-native.php', $input, $sources, $markers, $hits);
            if ($case['mode'] === 'edit') expect(\NativeChildCoverageEvidence::verifyRejections($report, $root, 'tests/Fixtures/report-consumers-native.php', $input, $sources, $markers, $hits, 'cli/refresh_csrf.php'))->toBe(count($sources) + count($markers) + 10);
            $coverage->merge($child);
        }
    } finally {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
})->with([
    'persisted graph item edit' => [['mode' => 'edit','request' => ['action' => 'item_edit','id' => '7','item_id' => '1']]],
    'enable only selected report' => [['mode' => 'enable','request' => ['action' => 'actions'],'post' => ['drp_action' => 'REPORTS_ENABLE','selected_items' => 'a:1:{i:0;s:1:"7";}']]],
    'disable only selected report' => [['mode' => 'disable','request' => ['action' => 'actions'],'post' => ['drp_action' => 'REPORTS_DISABLE','selected_items' => 'a:1:{i:0;s:1:"7";}']]],
    'reports admin takes selected ownership' => [['mode' => 'own','request' => ['action' => 'actions'],'post' => ['drp_action' => 'REPORTS_OWN','selected_items' => 'a:1:{i:0;s:1:"7";}']]],
    'missing email refuses mail handoff' => [['mode' => 'missing-email','request' => ['action' => 'send','id' => '7'],'post' => []]],
    'untouched item rejects before insert' => [['mode' => 'invalid-new-item','request' => ['action' => 'save','id' => '0','report_id' => '7'],'post' => ['save_component_report_item' => '1','item_type' => '','graph_name_regexp' => '']]],
]);
