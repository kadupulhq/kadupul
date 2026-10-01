<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class GraphViewListNativeTest extends TestCase
{
    /** @dataProvider lists */
    public function testNativeListFlowKeepsRequestSessionAndRenderedValuesConsistent(array $scenario, string $expected): void
    {
        $result = $this->runController($scenario);
        self::assertSame($expected, $result['request']['graph_list']);
        self::assertSame($expected, $result['session']['sess_gl_graph_list']);
        self::assertStringNotContainsString('Warning:', $result['html']);
        self::assertStringNotContainsString('Fatal error:', $result['html']);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($result['html'], LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $inputs = $xpath->query('//input[@id="graph_list"]');
        self::assertCount(1, $inputs);
        self::assertSame($expected, $inputs->item(0)->getAttribute('value'));
        self::assertCount(0, $xpath->query('//x'));
        self::assertStringNotContainsString('<script>alert', $result['html']);
        self::assertStringContainsString("id='graph_list' value='" . $expected . "'", $result['html']);
        self::assertMatchesRegularExpression('/var graphList = ("[^"\r\n]*");/', $result['html']);
        preg_match('/var graphList = ("[^"\r\n]*");/', $result['html'], $match);
        self::assertSame($expected, json_decode($match[1], true, 512, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('encodeURIComponent(graphList)', $result['html']);
        if (!empty($scenario['request']['site_id'])) {
            self::assertSame($scenario['request']['location'] === 'Absent' ? '-1' : 'Lab', $result['request']['location']);
        }
    }

    public function lists(): array
    {
        return array(
            'ordered unique addition' => array(array('request' => array('page' => 1, 'graph_list' => '3,1,3', 'graph_add' => '2')), '3,1,2'),
            'empty list' => array(array('request' => array('page' => 1, 'graph_list' => '')), ''),
            'leading zero' => array(array('request' => array('page' => 1, 'graph_list' => '05,5')), '5'),
            'remove last' => array(array('request' => array('page' => 1, 'graph_list' => '5', 'graph_remove' => '5'), 'session' => array('sess_gl_graph_list' => '5')), ''),
            'paging preserves session' => array(array('request' => array('page' => 2), 'session' => array('sess_gl_graph_list' => '1,2')), '1,2'),
            'mixed stale session' => array(array('request' => array('page' => 1), 'session' => array('sess_gl_graph_list' => '1,<script>,2')), '1,2'),
            'noninteger numeric values' => array(array('request' => array('page' => 1, 'graph_list' => '1e3,-4,1.5')), ''),
            'hostile session attribute' => array(array('request' => array('page' => 1), 'session' => array('sess_gl_graph_list' => "1'><script>alert(1)</script>")), ''),
            'new view resets selection' => array(array('request' => array(), 'session' => array('sess_gl_graph_list' => '1,2')), ''),
            'matching site and location' => array(array('request' => array('page' => 1, 'graph_list' => '1,2', 'site_id' => 2, 'location' => 'Lab', 'rows' => 10)), '1,2'),
            'mismatched site and location' => array(array('request' => array('page' => 1, 'graph_list' => '1,2', 'site_id' => 2, 'location' => 'Absent', 'rows' => 20)), '1,2'),
        );
    }

    public function testNativeAjaxSearchReturnsTheParentTreePath(): void
    {
        $result = $this->runController(array('request' => array('action' => 'ajax_search', 'str' => 'Child')));
        self::assertSame(array('tree_anchor-4', 'tbranch-1'), json_decode($result['html'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testNativeNodeLookupUsesTheStoredBranchTree(): void
    {
        $result = $this->runController(array('request' => array('action' => 'get_node', 'tree_id' => 0, 'id' => 'tbranch-2')));
        self::assertSame(array('tree_id' => 4, 'parent' => 2), json_decode($result['html'], true, 512, JSON_THROW_ON_ERROR));
    }

    private function runController(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/graph-view-native-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $command = array(PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/graph-view-list-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            self::assertSame('', $stderr);
            $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return $result;
        } finally {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
}
