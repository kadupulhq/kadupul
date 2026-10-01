<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class AggregateGraphConfirmationXssTest extends TestCase
{
    /** @dataProvider confirmations */
    public function testConfirmationKeepsTheIdInsideOneAttribute(array $scenario, string $expected): void
    {
        $result = $this->runController($scenario);
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
        $inputs = $xpath->query('//input[@name="local_graph_id"]');
        self::assertCount(1, $inputs);
        self::assertSame($expected, $inputs->item(0)->getAttribute('value'));
        self::assertSame(3, $inputs->item(0)->attributes->length);
        self::assertCount(0, $xpath->query('//x | //input[@onfocus]'));
        self::assertCount(1, $xpath->query('//script'));
        self::assertStringNotContainsString('<script>alert(1)', $result['html']);
        self::assertCount(1, $xpath->query('//form[@action="aggregate_graphs.php"]'));
        if (($scenario['drp_action'] ?? '') === 'tr_6') {
            self::assertSame('6', $xpath->query('//input[@name="tree_id"]')->item(0)->getAttribute('value'));
            self::assertSame('- Branch', $xpath->query('//select[@name="tree_item_id"]/option[@value="8"]')->item(0)->textContent);
        }
    }

    public function confirmations(): array
    {
        $cases = array(
            'hostile ID' => array(array('local_graph_id' => "42' onfocus='alert(1)'><x>injected</x>"), "42' onfocus='alert(1)'><x>injected</x>"),
            'script ID' => array(array('local_graph_id' => "'><script>alert(1)</script>"), "'><script>alert(1)</script>"),
            'array ID' => array(array('local_graph_id' => array('42')), '0'),
            'normal ID' => array(array('local_graph_id' => 42), '42'),
            'missing ID' => array(array(), '0'),
        );
        foreach (array('2', '3', '4', '5', '10', '11', 'tr_6') as $action) {
            $cases['action ' . $action] = array(array('drp_action' => $action, 'local_graph_id' => 42), '42');
        }
        return $cases;
    }

    /** @dataProvider pages */
    public function testNativeAggregatePagesKeepTheirGraphAndTemplateContext(array $scenario, string $expected): void
    {
        $result = $this->runController($scenario);
        self::assertStringNotContainsString('Warning:', $result['html']);
        self::assertStringNotContainsString('Fatal error:', $result['html']);
        self::assertStringContainsString($expected, $result['html']);
        self::assertStringNotContainsString('<x>', $result['html']);
        if (($scenario['action'] ?? '') === '') {
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                self::assertTrue($document->loadHTML($result['html'], LIBXML_NONET));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new DOMXPath($document);
            self::assertCount(1, $xpath->query('//select[@id="aggregate_graph_rows"]'));
            self::assertCount(1, $xpath->query('//label[@for="aggregate_graph_rows"]'));
            self::assertCount(1, $xpath->query('//input[@id="aggregate_graph_go"]'));
            self::assertCount(1, $xpath->query('//input[@id="aggregate_graph_clear"]'));
            self::assertCount(0, $xpath->query('//*[@id="rows" or @id="go" or @id="clear"]'));
            self::assertSame('Default', $xpath->query('//select[@id="aggregate_graph_rows"]/option[@value="-1"]')->item(0)->textContent);
            self::assertSame((string) $scenario['rows'], $xpath->query('//select[@id="aggregate_graph_rows"]/option[@selected]')->item(0)->getAttribute('value'));
            self::assertCount(1, $xpath->query('//select[@id="template_id"]'));
            self::assertSame('Any', $xpath->query('//select[@id="template_id"]/option[@value="-1"]')->item(0)->textContent);
            self::assertSame('None', $xpath->query('//select[@id="template_id"]/option[@value="0"]')->item(0)->textContent);
            self::assertSame('Aggregate <x>template</x>', $xpath->query('//select[@id="template_id"]/option[@value="2"]')->item(0)->textContent);
            self::assertSame((string) ($scenario['template_id'] ?? -1), $xpath->query('//select[@id="template_id"]/option[@selected]')->item(0)->getAttribute('value'));
        }
    }

    public function pages(): array
    {
        return array(
            'list' => array(array('action' => '', 'rows' => 10), 'Graph title'),
            'default rows' => array(array('action' => '', 'rows' => -1), 'Graph title'),
            'selected template' => array(array('action' => '', 'rows' => 10, 'template_id' => 2), 'Graph title'),
            'no template' => array(array('action' => '', 'rows' => 10, 'template_id' => 0), 'No Aggregate Graphs Found'),
            'items' => array(array('action' => 'edit', 'tab' => 'items', 'id' => 42, 'rows' => 10), 'Matching Graphs'),
            'preview' => array(array('action' => 'edit', 'tab' => 'preview', 'id' => 42), 'graph_image.php?action=edit'),
        );
    }

    public function testSaveHandsOffValidatedParametersAndUpdatesOnlyTheSelectedAggregateTitle(): void
    {
        $result = $this->runController(array('action' => 'save', 'save_component_graph' => '1', 'local_graph_id' => '42', 'graph_template_id' => '3', 'aggregate_template_id' => '2', 'title_format' => 'Updated aggregate', 'template_propogation' => 'on'));
        self::assertSame('', $result['html']);
        self::assertSame('Updated aggregate', $result['title']);
        self::assertSame('Unchanged aggregate', $result['other_title']);
        self::assertSame(array('42', '3', 'Updated aggregate', '2', array()), $result['save']);
    }

    private function runController(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/aggregate-native-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/aggregate-confirmation-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
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
