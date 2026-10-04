<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../Helpers/PestCodeCoverageCompatibility.php';

final class HtmlReportRenderNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;

    private static bool $coverageEvidenceChecked = false;

    #[\PHPUnit\Framework\Attributes\DataProvider('filterCases')]
    public function testFilterOptionsPreserveSelectionAndLiteralLabels(string $theme, bool $restricted): void
    {
        $state = $this->runRender(['operation' => 'filters', 'theme' => $theme, 'noany' => $restricted, 'nonone' => $restricted]);
        $xpath = $this->parse($state['html']);
        self::assertSame('Site <west> & "A"', $xpath->evaluate('string(//select[@id="site_id"]/option[@selected])'));
        self::assertSame('4', $xpath->evaluate('string(//select[@id="site_id"]/option[@selected]/@value)'));
        self::assertSame($restricted ? 1 : 3, $xpath->query('//select[@id="site_id"]/option')->length);
        self::assertSame('Rack <west> & "A"', $xpath->evaluate('string(//select[@id="location"]/option[@selected])'));
        self::assertSame(3, $xpath->query('//select[@id="location"]/option')->length);
        self::assertSame(0, $xpath->query('//script|//west|//one')->length);
        if ($theme === 'classic') {
            self::assertSame('Router <one> & "two"', $xpath->evaluate('string(//select[@id="host_id"]/option[@selected])'));
            self::assertSame('100', $xpath->evaluate('string(//select[@id="host_id"]/option[@selected]/@value)'));
        } else {
            self::assertSame('Router <one> & "two"', $xpath->evaluate('string(//input[@id="host"]/@value)'));
            self::assertSame('100', $xpath->evaluate('string(//input[@id="host_id"]/@value)'));
            self::assertSame('refresh()', $xpath->evaluate('string(//input[@id="call_back"]/@value)'));
        }
    }

    public static function filterCases(): array
    {
        return ['classic sentinels' => ['classic', false], 'classic restricted' => ['classic', true], 'autocomplete' => ['modern', false]];
    }

    public function testCheckboxHeaderUsesCurrentRouteAndEscapesItsLabelAndTip(): void
    {
        $state = $this->runRender(['operation' => 'header']);
        $xpath = $this->parse($state['html']);
        self::assertSame('Name <literal>', $xpath->evaluate('string(//th[@title])'));
        self::assertSame('Tip "quoted"', $xpath->evaluate('string(//th/@title)'));
        self::assertSame('reports_admin.php', $xpath->evaluate('string(//form/@action)'));
        self::assertSame('post', $xpath->evaluate('string(//form/@method)'));
        self::assertSame('chk', $xpath->evaluate('string(//input[@id="selectall"]/@data-prefix)'));
        self::assertSame(0, $xpath->query('//literal')->length);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reportCases')]
    public function testReportReadsOnlyItsOwnItemsInOrderAndRendersLiteralText(bool $email): void
    {
        $state = $this->runRender(['operation' => 'report', 'email' => $email]);
        $xpath = $this->parse($state['html']);
        self::assertSame('Weekly <network> & "status"', trim($xpath->evaluate('string(//td[@class="title"])')));
        $texts = $xpath->query('//td[@class="text"]');
        self::assertCount(2, $texts);
        self::assertSame('First <strong>literal</strong>', trim($texts[0]->textContent));
        self::assertSame('Second <script>alert(1)</script> & "text"', trim($texts[1]->textContent));
        self::assertStringContainsString('text-align:right', $texts[0]->getAttribute('style'));
        self::assertSame(0, $xpath->query('//script|//strong|//network')->length);
        self::assertStringNotContainsString('Foreign text', $state['html']);
        self::assertSame('true', $state['custom']);
        if ($email) {
            self::assertStringStartsWith('<body>', $state['html']);
            self::assertStringEndsWith('</body>', $state['html']);
            self::assertFalse($xpath->query('//table[@class="report_table"]')[0]->hasAttribute('style'));
        } else {
            self::assertStringNotContainsString('<body>', $state['html']);
            self::assertStringContainsString('background-color:#F9F9F9', $xpath->query('//table[@class="report_table"]')[0]->getAttribute('style'));
        }
    }

    public static function reportCases(): array
    {
        return ['preview' => [false], 'email' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('iconCases')]
    public function testGraphDrilldownUsesActualGraphAssociationAndPassesTreeContextToHook(array $realms): void
    {
        $state = $this->runRender(['operation' => 'icons', 'realms' => $realms]);
        $xpath = $this->parse($state['html']);
        self::assertSame(1, $xpath->query('//a[@id="graph_200_util"]')->length);
        self::assertSame(1, $xpath->query('//a[@id="graph_200_csv"]')->length);
        self::assertSame(count($realms) ? 1 : 0, $xpath->query('//a[@id="graph_200_de"]')->length);
        self::assertSame(count($realms) ? 1 : 0, $xpath->query('//a[@title="Edit Graph Template"]')->length);
        if ($realms) {
            self::assertSame('/kadupul/host.php?action=edit&id=100', $xpath->evaluate('string(//a[@id="graph_200_de"]/@href)'));
            self::assertSame('/kadupul/graph_templates.php?action=template_edit&id=6', $xpath->evaluate('string(//a[@title="Edit Graph Template"]/@href)'));
        }
        self::assertSame(['graph_buttons', ['hook' => 'graph_buttons', 'local_graph_id' => 200, 'rra' => 0, 'view_type' => 'tree', 'tree_id' => 3, 'branch_id' => 9]], $state['hook']);
    }

    public static function iconCases(): array
    {
        return ['no editing realms' => [[]], 'editing realms' => [[3, 10]]];
    }

    private function parse(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<html><body><table>' . $html . '</table></body></html>'));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    private function runRender(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/html-report-render-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/html-report-render-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory];
        if ($coverage !== null) {
            $command[] = 'coverage';
        }
        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr . $stdout);
            self::assertSame('', $stderr);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
                $childCoverage = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/html-report-render-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/html.php', 'lib/reports.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array('native-render-operation-returned', 'buffered-html-observed'), array($scenario['operation'] === 'report' ? 'lib/reports.php' : 'lib/html.php'));
                if (!self::$coverageEvidenceChecked) {
                    self::assertSame(26, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/html-report-render-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), array('lib/html.php', 'lib/reports.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'), array('native-render-operation-returned', 'buffered-html-observed'), array($scenario['operation'] === 'report' ? 'lib/reports.php' : 'lib/html.php'), 'lib/rrd.php'));
                    self::$coverageEvidenceChecked = true;
                }
                $coverage->merge($childCoverage);
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage') as $report) {
                if (is_file($report . '.json')) {
                    unlink($report . '.json');
                }
                unlink($report);
            }
            rmdir($directory);
        }
    }
}
