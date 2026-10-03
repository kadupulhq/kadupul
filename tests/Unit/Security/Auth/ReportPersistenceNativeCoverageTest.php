<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

final class ReportPersistenceNativeCoverageTest extends TestCase
{
    use \PestCodeCoverageCompatibility;
    public function testDeviceExpansionUsesRealTemplatePermissionsAndNaturalGraphOrdering(): void
    {
        $state = $this->runReport(array('operation' => 'expand-device', 'regexp' => '^Traffic', 'format' => false));
        self::assertSame(array(201, 200), $this->renderedGraphs($state['result']));
        self::assertStringContainsString('Router &lt;one&gt;', $state['result']);
        self::assertStringContainsString('font-size: 10pt', $state['result']);
        self::assertSame(array(70, 71, 80), array_column($state['items'], 'id'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyExpansionCases')]
    public function testEmptyAndDeniedExpansionsCannotRenderGraphs(array $scenario): void
    {
        $state = $this->runReport($scenario);
        self::assertSame(array(), $this->renderedGraphs($state['result'] ?? ''));
        self::assertSame(array(70, 71, 80), array_column($state['items'], 'id'));
    }

    public static function emptyExpansionCases(): array
    {
        return array(
            'missing device' => array(array('operation' => 'expand-device', 'device' => 999)),
            'denied template' => array(array('operation' => 'expand-device', 'template' => 7)),
            'all templates denied' => array(array('operation' => 'expand-device', 'template' => -1, 'deny_all' => true)),
            'empty tree identity' => array(array('operation' => 'expand-tree', 'tree' => 0)),
            'empty branch' => array(array('operation' => 'expand-tree', 'branch' => 6)),
            'denied host leaf' => array(array('operation' => 'expand-tree', 'branch' => 7)),
            'denied nested graph' => array(array('operation' => 'expand-tree', 'branch' => 4, 'nested' => true, 'deny_all' => true)),
            'nonquery host grouping with no matching graphs' => array(array('operation' => 'expand-tree', 'branch' => 2, 'cascade' => 'on', 'grouping' => 2, 'regexp' => '^Absent')),
        );
    }

    public function testNestedBranchExpansionUsesItsActualChildGraphAndEscapedTitles(): void
    {
        $state = $this->runReport(array('operation' => 'expand-branch', 'branch' => 4));
        self::assertSame(array(210), $this->renderedGraphs($state['result']));
        self::assertStringContainsString('Nested &lt;branch&gt;', $state['result']);
        self::assertStringNotContainsString('Traffic', $state['result']);
    }

    public function testAllDeviceTemplatesRetainAllowedGraphsAndExcludeDeniedTemplate(): void
    {
        $state = $this->runReport(array('operation' => 'expand-device', 'template' => -1));
        self::assertSame(array(210, 201, 200), $this->renderedGraphs($state['result']));
    }

    public function testTreeLeavesPreserveConfiguredHostAndGraphPositions(): void
    {
        $state = $this->runReport(array('operation' => 'expand-tree', 'regexp' => '^Traffic', 'format' => false));
        // Host leaf expands its two permitted graphs; the distinct explicit
        // graph leaf then includes graph200 again at its configured position.
        self::assertSame(array(200, 201, 200), $this->renderedGraphs($state['result']));
        self::assertStringContainsString('Root &lt;branch&gt;', $state['result']);
        self::assertStringNotContainsString('Denied host', $state['result']);
    }

    public function testHostTemplateCascadeUsesNaturalOrderAndRootGraphsUseEscapedTreeName(): void
    {
        $host = $this->runReport(array('operation' => 'expand-tree', 'branch' => 2, 'cascade' => 'on'));
        self::assertSame(array(210, 201, 200), $this->renderedGraphs($host['result']));
        $root = $this->runReport(array('operation' => 'expand-tree', 'branch' => 0));
        self::assertSame(array(200), $this->renderedGraphs($root['result']));
        self::assertStringContainsString('Tree: Network &lt;tree&gt;', $root['result']);
    }

    private function renderedGraphs(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<html><body><table>' . $html . '</table></body></html>'));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $ids = array();
        foreach ($document->getElementsByTagName('img') as $image) {
            parse_str((string) parse_url($image->getAttribute('src'), PHP_URL_QUERY), $query);
            $ids[] = (int) $query['local_graph_id'];
            self::assertSame('classic', $query['graph_theme']);
            self::assertGreaterThan($query['graph_start'], $query['graph_end']);
        }
        return $ids;
    }

    public function testRejectedGraphInsertCannotClaimTheLiveActionSucceeded(): void
    {
        $state = $this->runReport(array('operation' => 'add-graph', 'reject_write' => true));
        self::assertSame(array(70, 71, 80), array_column($state['items'], 'id'));
        self::assertFalse($state['result']);
    }

    public function testMissingGraphReturnsNormalFailureWithoutReadingMissingFields(): void
    {
        $state = $this->runReport(array('operation' => 'add-graph', 'graph' => 999));
        self::assertFalse($state['result']);
        self::assertSame(array(70, 71, 80), array_column($state['items'], 'id'));
        self::assertContains('reports_graph_not_found', $state['messages']);
    }

    public function testLegacyPrepareListsOnlyTheActualCurrentUsersReports(): void
    {
        $state = $this->runReport(array('operation' => 'legacy-prepare'));
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<table>' . $state['rendered'] . '</table>'));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $options = (new DOMXPath($document))->query('//select[@name="reports_id"]/option');
        self::assertSame(1, $options->length);
        self::assertSame('7', $options[0]->getAttribute('value'));
        self::assertSame('Weekly network', $options[0]->textContent);
        $other = $this->runReport(array('operation' => 'legacy-prepare', 'user' => 55));
        self::assertStringNotContainsString('Weekly network', $other['rendered']);
        self::assertStringNotContainsString('Other owner', $other['rendered']);
    }

    public function testLegacyGraphExecutionPersistsSelectedOrderAndSkipsDuplicate(): void
    {
        $state = $this->runReport(array('operation' => 'legacy-execute', 'graphs' => array(201, 200, 200)));
        self::assertSame(array(201, 200), array_column(array_slice($state['items'], 3), 'local_graph_id'));
        self::assertSame(array(3, 4), array_column(array_slice($state['items'], 3), 'sequence'));
        self::assertSame(array(0, 100), array_column(array_slice($state['items'], 3), 'host_id'));
        self::assertSame(array(0, 3), array_column(array_slice($state['items'], 3), 'host_template_id'));
        self::assertSame(array(7, 7), array_column(array_slice($state['items'], 3), 'report_id'));
        self::assertStringContainsString('Skipped Report Graph Item', $state['message_details'][0][0]);
    }

    public function testLegacyGraphExecutionReportsActualFailedWritesWithoutSuccess(): void
    {
        $state = $this->runReport(array('operation' => 'legacy-execute', 'reject_write' => true));
        self::assertSame(array(70, 71, 80), array_column($state['items'], 'id'));
        self::assertStringContainsString('Failed Adding Report Graph Item', $state['message_details'][0][0]);
        self::assertStringNotContainsString('Created Report Graph Item', $state['message_details'][0][0]);
    }

    public function testLegacyEmptySelectionsAndUnrelatedActionsPreserveRowsAndReturnContracts(): void
    {
        foreach (array(array('operation' => 'legacy-execute', 'graphs' => array()), array('operation' => 'legacy-execute', 'action' => 'cancel')) as $scenario) {
            $state = $this->runReport($scenario);
            self::assertSame(array(70, 71, 80), array_column($state['items'], 'id'));
            self::assertSame(array(), $state['messages']);
            self::assertSame($scenario['action'] ?? null, $state['result']);
        }
        $state = $this->runReport(array('operation' => 'legacy-prepare', 'action' => 'cancel'));
        self::assertSame('cancel', $state['result']['drp_action']);
        self::assertSame('', $state['rendered']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('addCases')]
    public function testAddingReportItemsRespectsActualSqlOwnership(array $scenario, bool $accepted, int $itemType): void
    {
        $state = $this->runReport($scenario);
        self::assertSame($accepted, $state['result']);
        self::assertCount($accepted ? 4 : 3, $state['items']);
        self::assertSame(array('id' => 8, 'user_id' => 99, 'name' => 'Other owner', 'enabled' => 'on'), $state['reports'][1]);
        if ($accepted) {
            $item = $state['items'][3];
            self::assertSame(7, $item['report_id']);
            self::assertSame($itemType, $item['item_type']);
            self::assertSame(100, $item['host_id']);
            self::assertSame(3, $item['host_template_id']);
            self::assertSame(4, $item['timespan']);
            self::assertSame(2, $item['align']);
            self::assertSame(3, $item['sequence']);
        } else {
            self::assertContains('reports_not_owner', $state['messages']);
        }
    }

    public static function addCases(): array
    {
        return array(
            'owner device' => array(array('operation' => 'add-device'), true, 5),
            'owner graph' => array(array('operation' => 'add-graph'), true, 1),
            'other user device rejected' => array(array('operation' => 'add-device', 'user' => 55), false, 5),
            'other user graph rejected' => array(array('operation' => 'add-graph', 'user' => 55), false, 1),
            'report realm grants device management' => array(array('operation' => 'add-device', 'user' => 55, 'realm' => 21), true, 5),
            'admin realm grants graph management' => array(array('operation' => 'add-graph', 'user' => 55, 'realm' => 1), true, 1),
            'enabled report group grants management' => array(array('operation' => 'add-device', 'user' => 55, 'group' => 'on'), true, 5),
            'disabled report group rejected' => array(array('operation' => 'add-device', 'user' => 55, 'group' => ''), false, 5),
        );
    }

    public function testDuplicateDeviceAndGraphRequestsDoNotCreateExtraRows(): void
    {
        $device = $this->runReport(array('operation' => 'duplicate-device'));
        self::assertFalse($device['result']);
        self::assertCount(4, $device['items']);
        self::assertContains('reports_no_add_device_100', $device['messages']);
        $graph = $this->runReport(array('operation' => 'duplicate-graph'));
        self::assertTrue($graph['result']);
        self::assertCount(4, $graph['items']);
    }

    public function testUnknownDeviceIsRejectedWithoutWriting(): void
    {
        $state = $this->runReport(array('operation' => 'add-device', 'devices' => array(999)));
        self::assertFalse($state['result']);
        self::assertCount(3, $state['items']);
        self::assertContains('reports_device_not_found', $state['messages']);
    }

    public function testReportCopyRetainsItemsAndBelongsToCurrentUserDisabled(): void
    {
        $state = $this->runReport(array('operation' => 'copy-report', 'user' => 55));
        self::assertSame(array('id' => 9, 'user_id' => 55, 'name' => 'Copy of Weekly network', 'enabled' => ''), $state['reports'][2]);
        self::assertCount(5, $state['items']);
        foreach (array(0 => 3, 1 => 4) as $source => $copy) {
            $original = $state['items'][$source];
            $duplicated = $state['items'][$copy];
            unset($original['id'], $original['report_id'], $duplicated['id'], $duplicated['report_id']);
            self::assertSame($original, $duplicated);
            self::assertSame(9, $state['items'][$copy]['report_id']);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reorderCases')]
    public function testReorderingCannotWriteOutsideTheAuthorizedReport(int $user, bool $accepted): void
    {
        $state = $this->runReport(array('operation' => 'reorder', 'user' => $user));
        self::assertSame($accepted ? array(2, 1, 1) : array(1, 2, 1), array_column($state['items'], 'sequence'));
        self::assertSame(array(7, 7, 8), array_column($state['items'], 'report_id'));
    }

    public static function reorderCases(): array
    {
        return array('owner' => array(42, true), 'other user' => array(55, false));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('removeCases')]
    public function testRemovalUsesThePersistedParentOwner(array $scenario, array $remaining): void
    {
        $state = $this->runReport(array_merge(array('operation' => 'remove'), $scenario));
        self::assertSame($remaining, array_column($state['items'], 'id'));
    }

    public static function removeCases(): array
    {
        return array(
            'owned item' => array(array(), array(71, 80)),
            'foreign parent' => array(array('item' => 80), array(70, 71, 80)),
            'missing item' => array(array('item' => 999), array(70, 71, 80)),
            'other user' => array(array('user' => 55), array(70, 71, 80)),
            'report admin' => array(array('user' => 55, 'realm' => 21, 'item' => 80), array(70, 71)),
        );
    }

    private function runReport(array $scenario): array
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/report-persistence-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/report-persistence-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
        if ($coverage !== null) {
            $command[] = 'coverage';
        }
        try {
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
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
                $sources = array('tests/Unit/Security/Auth/ReportPersistenceNativeCoverageTest.php', 'composer.lock', 'tests/composer.lock', 'tests/Fixtures/report-persistence-native.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
                    'lib/auth.php', 'lib/reports.php', 'lib/html_reports.php', 'include/global_constants.php', 'include/global_arrays.php', 'lib/time.php', 'lib/html.php', 'lib/html_form.php', 'lib/data_query.php', 'lib/sort.php', 'lib/html_tree.php', 'lib/html_utility.php',
                    'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
                $arguments = array($reports[0], $root, 'tests/Fixtures/report-persistence-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $sources, array('report-persisted-state-readback'), array('lib/reports.php'));
                $measured = NativeChildCoverageEvidence::load(...$arguments);
                static $verifiedOmissions = false;
                if (!$verifiedOmissions) {
                    self::assertSame(count($sources) + 11, NativeChildCoverageEvidence::verifyRejections(...array_merge($arguments, array('lib/boost.php'))));
                    $verifiedOmissions = true;
                }
                $coverage->merge($measured);
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*') as $report) {
                unlink($report);
            }
            rmdir($directory);
        }
    }
}
