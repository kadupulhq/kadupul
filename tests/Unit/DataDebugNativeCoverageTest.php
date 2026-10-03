<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class DataDebugNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;
    #[\PHPUnit\Framework\Attributes\DataProvider('listCases')]
    public function testFullControllerKeepsScopedPersistedChecks(array $request, array $ids): void
    {
        $state = $this->render($request);
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        $actual = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $id = (int) substr($row->getAttribute('id'), 4);
            $actual[] = $id;
            self::assertSame('data_debug.php?action=view&id=' . $id, $xpath->query('./td[1]/a', $row)->item(0)->getAttribute('href'));
            self::assertCount(0, $xpath->query('.//script', $row));
        }
        self::assertSame($ids, $actual);
        self::assertSame($state['before'], $state['after']);
        self::assertSame('preserved', $state['session']['sentinel']);
        self::assertSame((string) ($request['rows'] ?? -1), $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame('Two & more', $xpath->query('//select[@id="rows"]/option[@value="2"]')->item(0)->textContent);
        self::assertSame('Default & profile', $xpath->query('//select[@id="profile"]/option[@value="1"]')->item(0)->textContent);
        self::assertSame('Template & <script>', $xpath->query('//select[@id="template_id"]/option[@value="10"]')->item(0)->textContent);
        $reads = array_values(array_filter($state['queries'], static fn(array $q): bool => str_contains($q[0], 'FROM data_local AS dl')));
        self::assertCount(2, $reads);
        self::assertStringContainsString('SELECT COUNT(*)', $reads[0][0]);
        self::assertStringContainsString('SELECT dd.*', $reads[1][0]);
        if ($ids === array()) {
            self::assertStringContainsString('No Checks', $document->textContent);
        }
    }

    public static function listCases(): array
    {
        return array(
            'default page' => array(array(), array(101, 102)),
            'next page' => array(array('page' => 2), array(103)),
            'all rows' => array(array('rows' => 10), array(101, 102, 103)),
            'site device template profile' => array(array('site_id' => 1, 'host_id' => 1, 'template_id' => 10, 'profile' => 1), array(101, 102)),
            'inactive checks' => array(array('status' => 2), array(103)),
            'regex title' => array(array('rfilter' => 'Alpha', 'rows' => 1), array(101)),
            'empty regex' => array(array('rfilter' => 'missing'), array()),
            'no-debug empty' => array(array('debug' => 0), array()),
            'only debugging' => array(array('debug' => 1, 'rows' => 10), array(101, 102, 103)),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusCases')]
    public function testRealStatusAndDetailedCheckRendering(string $status, string $heading, string $result): void
    {
        $state = $this->render(array('action' => 'view', 'id' => 101), array('state' => $status));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        self::assertStringContainsString($heading, $document->textContent);
        self::assertStringContainsString('RRDfile Owner', $document->textContent);
        self::assertStringContainsString('native owner', $document->textContent);
        self::assertStringContainsString($result, $document->textContent);
        self::assertCount(14, $xpath->query('//tr[starts-with(@id,"line") and not(contains(@id,"_"))]'));
        self::assertSame($state['before'], $state['after']);
        $queries = array_values(array_filter($state['queries'], static fn(array $q): bool => str_contains($q[0], 'WHERE datasource = ?')));
        self::assertCount(3, $queries);
        foreach ($queries as $query) {
            self::assertSame(array(101), $query[1]);
        }
    }

    public static function statusCases(): array
    {
        return array(
            'waiting' => array('waiting', 'Auto Refreshing till Complete', 'Waiting on analysis and RRDfile update'),
            'analysis' => array('analysis', 'Auto Refreshing till RRDfile Update', 'Waiting on analysis and RRDfile update'),
            'complete' => array('complete', 'Analysis Complete!', 'Rerun Analysis'),
            'failed' => array('failed', 'Analysis Complete!', 'Polling issue'),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mutationCases')]
    public function testDebugMutationsKeepUnselectedPersistedChecks(string $operation): void
    {
        $request = $operation === 'runall' ? array('action' => 'runall', 'host_id' => 1) : array();
        $state = $this->render($request, array('operation' => $operation));
        $before = array_column($state['before']['data_debug'], null, 'datasource');
        $after = array_column($state['after']['data_debug'], null, 'datasource');
        self::assertSame($before[103], $after[103]);
        foreach ($state['before'] as $table => $rows) {
            if ($table !== 'data_debug') {
                self::assertSame($rows, $state['after'][$table]);
            }
        }
        if ($operation === 'delete') {
            self::assertArrayNotHasKey(101, $after);
            self::assertSame($before[102], $after[102]);
        } else {
            foreach ($operation === 'runall' ? array(101, 102) : array(101) as $id) {
                self::assertSame(0, $after[$id]['done']);
                self::assertGreaterThan($before[$id]['started'], $after[$id]['started']);
                $info = unserialize($after[$id]['info'], array('allowed_classes' => false));
                self::assertSame('', $info['last_result']);
                self::assertSame('', $info['rrd_match']);
            }
            if ($operation === 'rerun') {
                self::assertSame($before[102], $after[102]);
            }
        }
        self::assertSame('preserved', $state['session']['sentinel']);
    }

    public static function mutationCases(): array
    {
        return array('existing rerun' => array('rerun'), 'delete' => array('delete'), 'filtered run all' => array('runall'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cleanerCases')]
    public function testCleanerFullListKeepsRowsAndUnusedFileScope(array $request, array $names): void
    {
        $state = $this->render($request, array('view' => 'cleaner'));
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($state['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        $actual = array();
        foreach ($xpath->query('//tr[starts-with(@id,"line")]') as $row) {
            $actual[] = trim($xpath->query('./td[1]', $row)->item(0)->textContent);
        }
        self::assertSame($names, $actual);
        self::assertSame('Two & more', $xpath->query('//select[@id="rows"]/option[@value="2"]')->item(0)->textContent);
        self::assertSame((string) ($request['rows'] ?? -1), $xpath->query('//select[@id="rows"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame($state['before'], $state['after']);
        self::assertSame($state['log_before'], $state['log_after']);
        $reads = array_values(array_filter($state['queries'], static fn(array $q): bool => str_contains($q[0], 'FROM data_source_purge_temp AS rc')));
        self::assertCount(3, $reads);
        foreach ($reads as $read) {
            self::assertStringContainsString('WHERE in_cacti=0', $read[0]);
        }
    }

    public static function cleanerCases(): array
    {
        return array('default rows' => array(array(), array('one.rrd', 'two.rrd')), 'selected rows' => array(array('rows' => 2), array('one.rrd', 'two.rrd')), 'filtered file' => array(array('filter' => 'one'), array('one.rrd')), 'empty result' => array(array('filter' => 'missing'), array()));
    }

    private function render(array $request, array $options = array()): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/manager-view-' . bin2hex(random_bytes(8));
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $scenario = json_encode(array_merge(array('view' => 'debug', 'request' => $request), $options), JSON_THROW_ON_ERROR);
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/utility-view-native.php', $scenario, $directory);
        if ($coverage !== null) {
            $command[] = 'coverage';
        }
        try {
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error . $output);
            self::assertSame('', $error);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                // Independent complete inventory: original nine RRD defaults,
                // real utility filter files and every executable fixture dependency.
                $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Unit/UtilityViewNativeCoverageTest.php', 'tests/Unit/DataDebugNativeCoverageTest.php', 'tests/Fixtures/data-debug-records.php', 'utilities.php', 'data_debug.php', 'rrdcleaner.php', 'lib/html.php', 'lib/html_utility.php', 'lib/functions.php', 'lib/clog_webapi.php', 'src/Platform/Infrastructure/Legacy/UtilityRows.php', 'include/global_constants.php', 'include/global_session.php', 'lib/html_form.php', 'lib/variables.php', 'src/Platform/Infrastructure/Legacy/HostDataSubstitution.php', 'lib/utility.php');
                $markers = array('utility-view-observed:' . ($options['view'] ?? 'debug'));
                $hits = array(($options['view'] ?? 'debug') === 'cleaner' ? 'rrdcleaner.php' : 'data_debug.php', 'lib/html.php', 'lib/functions.php');
                $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/utility-view-native.php', $scenario, $sources, $markers, $hits);
                static $omissionsVerified = array();
                $mode = $options['view'] ?? 'debug';
                if (!isset($omissionsVerified[$mode])) {
                    self::assertSame(41, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/utility-view-native.php', $scenario, $sources, $markers, $hits, 'lib/boost.php'));
                    $omissionsVerified[$mode] = true;
                }
                $coverage->merge($child);
            }
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage*') as $report) {
                unlink($report);
            }
            foreach (array('cacti.log', 'cacti.log-20260930', 'stderr.log') as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            if (is_link($directory . '/lib')) {
                unlink($directory . '/lib');
            }
            if (is_file($directory . '/include/auth.php')) {
                unlink($directory . '/include/auth.php');
                rmdir($directory . '/include');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
