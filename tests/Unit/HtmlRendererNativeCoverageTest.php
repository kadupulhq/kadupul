<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

final class HtmlRendererNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;
    #[\PHPUnit\Framework\Attributes\DataProvider('filterCases')]
    public function testActualFiltersKeepOrderedOptionsSelectionAndEntities(array $case, string $selector, array $values, string $selected): void
    {
        $state = $this->render($case);
        $xpath = $this->document($state['html']);
        $options = $xpath->query('//select[@id="' . $selector . '"]/option');
        self::assertSame($values, array_map(static fn($option) => $option->getAttribute('value'), iterator_to_array($options)));
        self::assertSame($selected, $xpath->query('//select[@id="' . $selector . '"]/option[@selected]')->item(0)?->getAttribute('value') ?? '');
        if ($selector === 'host_id' && in_array('1', $values, true)) {
            self::assertSame('Alpha & Beta', $xpath->query('//option[@value="1"]')->item(0)->textContent);
        }
        if ($selector === 'site_id' && in_array('1', $values, true)) {
            self::assertSame('Site & One', $xpath->query('//option[@value="1"]')->item(0)->textContent);
        }
        self::assertCount(0, $xpath->query('//script'));
        self::assertNotEmpty($state['queries']);
    }

    public static function filterCases(): array
    {
        return array(
            array(array('kind' => 'host', 'selected' => 1), 'host_id', array('-1', '0', '1', '3', '2'), '1'),
            array(array('kind' => 'host', 'request' => array('host_id' => 2)), 'host_id', array('-1', '0', '1', '3', '2'), '2'),
            array(array('kind' => 'host', 'selected' => 0, 'noany' => true), 'host_id', array('0', '1', '3', '2'), '0'),
            array(array('kind' => 'host', 'nonone' => true, 'where' => 'WHERE id=99'), 'host_id', array('-1'), '-1'),
            array(array('kind' => 'site', 'selected' => 1), 'site_id', array('-1', '0', '2', '1'), '1'),
            array(array('kind' => 'site', 'request' => array('site_id' => 2)), 'site_id', array('-1', '0', '2', '1'), '2'),
            array(array('kind' => 'site', 'selected' => 0, 'noany' => true), 'site_id', array('0', '2', '1'), '0'),
            array(array('kind' => 'site', 'nonone' => true, 'where' => 'WHERE id=99'), 'site_id', array('-1'), '-1'),
            array(array('kind' => 'location', 'selected' => 'Rack & West'), 'location', array('-1', '0', 'East', 'Rack & West'), 'Rack & West'),
            array(array('kind' => 'location', 'selected' => 0, 'noany' => true), 'location', array('0', 'East', 'Rack & West'), '0'),
            array(array('kind' => 'location', 'selected' => '-1', 'nonone' => true, 'where' => 'WHERE id=99'), 'location', array('-1'), '-1'),
        );
    }

    public function testActualAutocompleteKeepsDatabaseLabelIdentifierAndCallback(): void
    {
        $state = $this->render(array('kind' => 'host', 'theme' => 'modern', 'selected' => 1));
        $xpath = $this->document($state['html']);
        self::assertSame('Alpha & Beta', $xpath->query('//input[@id="host"]')->item(0)->getAttribute('value'));
        self::assertSame('1', $xpath->query('//input[@id="host_id"]')->item(0)->getAttribute('value'));
        self::assertSame('reloadDevices()', $xpath->query('//input[@id="call_back"]')->item(0)->getAttribute('value'));
        self::assertSame(array(1), $state['queries'][0][1]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('graphCases')]
    public function testActualGraphAreasKeepOrderingDisabledStateDimensionsAndNonce(string $kind, bool $empty, int $columns): void
    {
        $state = $this->render(array('kind' => $kind, 'empty' => $empty, 'columns' => $columns));
        $xpath = $this->document($state['html']);
        self::assertCount(1, $xpath->query('//script[@nonce]'));
        if ($empty) {
            self::assertCount(0, $xpath->query('//div[contains(@class,"graphWrapper")]'));
            self::assertSame('No Graphs', $xpath->query('//em')->item(0)->textContent);
        } else {
            $wrappers = $xpath->query('//div[@class="graphWrapper"]');
            self::assertSame(array('wrapper_11', 'wrapper_12'), array_map(static fn($node) => $node->getAttribute('id'), iterator_to_array($wrappers)));
            self::assertSame($kind === 'graph' ? '300' : '120', $wrappers->item(0)->getAttribute('graph_width'));
            self::assertSame($kind === 'graph' ? '80' : '40', $wrappers->item(0)->getAttribute('graph_height'));
            self::assertSame(array('false', 'true'), array_map(static fn($node) => $node->getAttribute('data-disabled'), iterator_to_array($xpath->query('//td[@class="graphWrapperOuter"]'))));
            self::assertSame('Alpha & Beta', $xpath->query('//span[@class="center"]')->item(0)->textContent);
            self::assertCount(2, $state['queries']);
            self::assertSame(array(11), $state['queries'][0][1]);
            self::assertSame(array(12), $state['queries'][1][1]);
        }
    }

    public static function graphCases(): array
    {
        return array(array('graph', false, 2), array('graph', false, 3), array('graph', true, 0), array('thumbnail', false, 2), array('thumbnail', false, 3), array('thumbnail', true, 0));
    }

    public function testActualDrilldownKeepsLinksAndPluginTreeContext(): void
    {
        $state = $this->render(array('kind' => 'drilldown', 'realms' => array(3, 10, 25, 1043)));
        $xpath = $this->document($state['html']);
        self::assertSame('/native/host.php?action=edit&id=1', $xpath->query('//a[@id="graph_11_de"]')->item(0)->getAttribute('href'));
        self::assertSame('/native/graph_templates.php?action=template_edit&id=21', $xpath->query('//a[@title="Edit Graph Template"]')->item(0)->getAttribute('href'));
        self::assertCount(1, $xpath->query('//a[@id="graph_11_realtime"]'));
        self::assertCount(1, $xpath->query('//span[@id="graph_11_sk"]'));
        self::assertSame(array(array('native_graph_buttons', array('hook' => 'native_graph_buttons', 'local_graph_id' => 11, 'rra' => 0, 'view_type' => 'tree', 'tree_id' => 7, 'branch_id' => 8))), $state['hooks']);
        self::assertSame(array(11), $state['queries'][0][1]);
        self::assertSame(array(11), $state['queries'][1][1]);
    }

    public function testActualBoxKeepsRequestIdentifierAndCallbackClass(): void
    {
        $xpath = $this->document($this->render(array('kind' => 'box', 'request' => array('action' => 'edit')))['html']);
        self::assertCount(1, $xpath->query('//div[@id="native_edit1"]'));
        self::assertSame('native.php?action=add', $xpath->query('//a[@id="add-item"]')->item(0)->getAttribute('href'));
        self::assertStringContainsString('linkOverDark', $xpath->query('//a[@id="add-item"]')->item(0)->getAttribute('class'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('headerCases')]
    public function testActualHeadersDefaultToCurrentPageAndKeepControls(string $kind): void
    {
        $xpath = $this->document($this->render(array('kind' => $kind))['html']);
        self::assertSame('native.php', $xpath->query('//form[@id="chk"]')->item(0)->getAttribute('action'));
        self::assertSame('post', $xpath->query('//form[@id="chk"]')->item(0)->getAttribute('method'));
        self::assertSame('chk', $xpath->query('//input[@id="selectall"]')->item(0)->getAttribute('data-prefix'));
        if ($kind === 'sort') {
            self::assertSame('DESC', $xpath->query('//div[@sort-column="name"]')->item(0)->getAttribute('sort-direction'));
            self::assertSame('native.php', $xpath->query('//div[@sort-column="name"]')->item(0)->getAttribute('sort-page'));
        } else {
            self::assertSame('Name & Value', $xpath->query('//th')->item(0)->textContent);
        }
    }

    public static function headerCases(): array
    {
        return array(array('sort'), array('checkbox'));
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));
        return new DOMXPath($document);
    }

    private function render(array $case): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/native-html-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = method_exists($this, 'getTestResultObject') ? $this->getTestResultObject()->getCodeCoverage() : null;
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/html-renderer-native.php', json_encode($case, JSON_THROW_ON_ERROR));
        if ($coverage !== null) {
            $command[] = $directory;
        }
        try {
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame('', $errors, 'Native rendering must not emit warnings');
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $sources = array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Unit/HtmlRendererNativeCoverageTest.php', 'include/global_constants.php', 'lib/functions.php', 'lib/html_utility.php', 'lib/headers_secure.php', 'lib/html.php');
                $markers = array('html-rendered:' . $case['kind']);
                $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/html-renderer-native.php', json_encode($case, JSON_THROW_ON_ERROR), $sources, $markers, array('lib/html.php'));
                if ($case === array('kind' => 'host', 'selected' => 1)) {
                    self::assertSame(30, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/html-renderer-native.php', json_encode($case, JSON_THROW_ON_ERROR), $sources, $markers, array('lib/html.php'), 'lib/boost.php'));
                }
                $coverage->merge($child);
            }
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
