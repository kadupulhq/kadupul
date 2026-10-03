<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later


require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

test('log identifiers render persisted names and preserve cached lookup contracts', function (string $input, array $links, string $text, bool $cached) {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/clog-links-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $scenario = json_encode(array('input' => $input), JSON_THROW_ON_ERROR);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/clog-links-native.php', $scenario, $directory, $coverage === null ? '' : 'coverage');
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        expect($result['second'])->toBe($result['first']);
        $document = new DOMDocument();
        $document->loadHTML('<html><body>' . $result['first'] . '</body></html>');
        $actualLinks = array();
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $actualLinks[] = $anchor->getAttribute('href');
        }
        expect($actualLinks)->toBe($links)->and($document->getElementsByTagName('body')->item(0)->textContent)->toBe($text);
        expect($document->getElementsByTagName('tag')->length)->toBe(0);
        expect($result['graphs'])->toBe(array(17 => 'Name <tag> & "quote"'))->and($result['hooks'])->toBe(array('clog_regex_array'));
        if ($cached) {
            expect($result['repeatQueries'])->toBe(0);
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'lib/clog_webapi.php', 'tests/Unit/ClogLinksNativeCoverageTest.php', 'tests/Helpers/PhpSource.php', 'lib/functions.php', 'lib/html.php');
            $markers = array('clog-links-rendered', 'clog-links-repeat-rendered', 'clog-graph-query-observed');
            $hits = array('lib/clog_webapi.php');
            $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/clog-links-native.php', $scenario, $sources, $markers, $hits);
            if ($input === ' Device[7]') {
                expect(NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/clog-links-native.php', $scenario, $sources, $markers, $hits, 'lib/rrd.php'))->toBe(count($sources) + count($markers) + 10);
            }
            $coverage->merge($child);
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(array(
    'device' => array(' Device[7]', array('/kadupul/host.php?action=edit&id=7'), ' Device[Name <tag> & "quote"]', true),
    'system device' => array(' Device[0]', array(), ' Device[System Device]', true),
    'poller' => array(' Poller[7]', array('/kadupul/pollers.php?action=edit&id=7'), ' Poller[Name <tag> & "quote"]', true),
    'data query' => array(' DQ[7]', array('/kadupul/data_queries.php?action=edit&id=7'), ' DQ[Name <tag> & "quote"]', true),
    'graph template' => array(' GT[7]', array('/kadupul/graph_templates.php?action=template_edit&id=7'), ' GT[Name <tag> & "quote"]', true),
    'automation rule' => array(' Rule[7]', array('/kadupul/automation_graph_rules.php?action=edit&id=7'), ' Rule[Name <tag> & "quote"]', true),
    'data input' => array(' DI[7]', array('/kadupul/data_input.php?action=edit&id=7'), ' DI[Name <tag> & "quote"]', false),
    'unknown data input' => array(' DI[99]', array('/kadupul/data_input.php?action=edit&id=99'), ' DI[Unknown]', false),
    'user' => array(' User[7]', array('/kadupul/user_admin.php?action=user_edit&tab=general&id=7'), ' User[Name <tag> & "quote"]', true),
    'graph' => array(' Graph[17]', array('/kadupul/graph_view.php?page=1&style=selective&action=preview&graph_add=17'), ' Graph[Name <tag> & "quote"]', true),
    'data source' => array(' DS[7, 7]', array('/kadupul/data_sources.php?action=ds_edit&id=7', '/kadupul/graph_view.php?page=1&style=selective&action=preview&graph_add=17'), ' DS[Name <tag> & "quote"] Graphs[Name <tag> & "quote"]', true),
    'unrecognized text' => array('No identifiers', array(), 'No identifiers', true)
));
