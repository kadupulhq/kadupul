<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../Helpers/PhpSource.php';
require_once __DIR__ . '/../../../Helpers/PestCodeCoverageCompatibility.php';
require_once __DIR__ . '/../../../Helpers/GraphDataRemovalCoverageRegistration.php';

final class GraphDataRemovalNativeTest extends TestCase
{
    use PestCodeCoverageCompatibility;
    private static bool $evidenceChecked = false;

    private function runCascade(array $scenario): array
    {
        $scenario += array('operation' => 'graph-data-removal', 'config' => array('graph_auth_method' => 1));
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/graph-data-removal-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create native evidence directory.');
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/auth-policy-native.php', $json, $directory);
        if ($coverage !== null) $command[] = 'coverage';
        try {
            ['out' => $output, 'err' => $error, 'status' => $status] = test_php_run($command);
            self::assertSame(0, $status, $error . $output);
            self::assertSame('', $error);
            if ($coverage !== null) {
                require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $child = NativeChildCoverageEvidence::load(
                    $reports[0],
                    $root,
                    'tests/Fixtures/auth-policy-native.php',
                    $json,
                    GraphDataRemovalCoverageRegistration::SOURCES,
                    GraphDataRemovalCoverageRegistration::MARKERS,
                    GraphDataRemovalCoverageRegistration::HITS
                );
                if (!self::$evidenceChecked) {
                    self::assertSame(40, NativeChildCoverageEvidence::verifyRejections(
                        $reports[0],
                        $root,
                        'tests/Fixtures/auth-policy-native.php',
                        $json,
                        GraphDataRemovalCoverageRegistration::SOURCES,
                        GraphDataRemovalCoverageRegistration::MARKERS,
                        GraphDataRemovalCoverageRegistration::HITS,
                        'lib/rrd.php'
                    ));
                    self::$evidenceChecked = true;
                }
                $coverage->merge($child);
            }
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR)['result'];
        } finally {
            foreach (glob($directory . '/*') as $file) unlink($file);
            rmdir($directory);
        }
    }

    #[DataProvider('admitted')]
    public function testAdmittedControllerModesRetainTheirMutationAndHookContracts(array $scenario, array $graphs, array $sources, array $items, array $hooks): void
    {
        $state = $this->runCascade($scenario);
        self::assertSame(array(), $state['messages']);
        self::assertSame($graphs, $state['graphs']);
        self::assertSame($sources, $state['sources']);
        self::assertSame($items, $state['items']);
        self::assertSame($hooks, array_column($state['hooks'], 0));
        self::assertSame(array(), $state['protected_queries']);
        self::assertFalse($state['transaction']);
    }

    public static function admitted(): iterable
    {
        yield 'graph only' => [array('resource' => 'graph','mode' => 1), [201,202,203], [300,301,302,303], [601,602], ['graphs_remove','snmp-graphs','graphs_action_bottom']];
        yield 'graph with source' => [array('resource' => 'graph','mode' => 2), [201,202,203], [301,302,303], [601,602], ['data_source_remove','graphs_remove','snmp-graphs','graphs_action_bottom']];
        yield 'source only' => [array('resource' => 'data','mode' => 1), [200,201,202,203], [301,302,303], [600,601,602], ['data_source_remove','snmp-data','data_source_action_bottom']];
        yield 'source and items' => [array('resource' => 'data','mode' => 2), [200,201,202,203], [301,302,303], [601,602], ['graph_items_remove','data_source_remove','snmp-data','data_source_action_bottom']];
        yield 'source and graphs' => [array('resource' => 'data','mode' => 3), [201,202,203], [301,302,303], [601,602], ['graphs_remove','data_source_remove','snmp-data','data_source_action_bottom']];
        yield 'host zero source' => [array('resource' => 'data','mode' => 1,'ids' => [302]), [200,201,202,203], [300,301,303], [600,601,602], ['data_source_remove','snmp-data','data_source_action_bottom']];
        yield 'duplicate source selection' => [array('resource' => 'data','mode' => 1,'ids' => [300,300]), [200,201,202,203], [301,302,303], [600,601,602], ['data_source_remove','snmp-data','data_source_action_bottom']];
    }

    #[DataProvider('denied')]
    public function testDeniedWholeSelectionStopsBeforeProtectedReadsHooksAndWrites(array $scenario): void
    {
        $state = $this->runCascade($scenario);
        self::assertSame([200,201,202,203], $state['graphs']);
        self::assertSame([300,301,302,303], $state['sources']);
        self::assertSame([600,601,602], $state['items']);
        self::assertSame(array(), $state['hooks']);
        self::assertSame(array(), $state['writes']);
        self::assertSame(array(), $state['reads']);
        self::assertSame(array(), $state['protected_queries']);
        self::assertSame(array(), $state['remote_calls']);
        self::assertCount(1, $state['messages']);
        self::assertStringNotContainsString('secret', $state['html']);
    }

    public static function denied(): iterable
    {
        foreach ([false,true] as $confirm) {
            if (!$confirm) {
                yield 'graph foreign source' => [array('resource' => 'graph','mode' => 2,'denied_source' => true)];
                foreach ([2,3] as $mode) yield 'source foreign graph ' . $mode => [array('resource' => 'data','mode' => $mode,'denied_graph' => true)];
            }
            yield 'mixed source selection ' . (int) $confirm => [array('resource' => 'data','mode' => 1,'ids' => [300,301],'confirm' => $confirm)];
            yield 'aggregate parent graph only ' . (int) $confirm => [array('resource' => 'graph','mode' => 1,'aggregate' => true,'denied_aggregate' => true,'confirm' => $confirm)];
        }
        yield 'graph cascade missing source realm' => [array('resource' => 'graph','mode' => 2,'granted_realms' => [5])];
        yield 'source cascade missing graph realm' => [array('resource' => 'data','mode' => 2,'granted_realms' => [3])];
        foreach ([0,-1,'1e2','1.5',[],null,999] as $id) yield 'invalid id ' . json_encode($id) => [array('resource' => 'data','mode' => 1,'ids' => [300,$id])];
        foreach ([0,4,'1e0',[],null] as $mode) yield 'invalid mode ' . json_encode($mode) => [array('resource' => 'data','mode' => $mode)];
    }

    #[DataProvider('hookChanges')]
    public function testHookScopeChangesRollBackLocalWorkBeforeFurtherMutations(array $scenario, string $hook): void
    {
        $state = $this->runCascade($scenario);
        self::assertSame([200,201,202,203], $state['graphs']);
        self::assertSame([300,301,302,303], $state['sources']);
        self::assertSame([600,601,602], $state['items']);
        self::assertContains($hook, array_column($state['hooks'], 0));
        self::assertNotContains('data_source_action_bottom', array_column($state['hooks'], 0));
        self::assertNotContains('graphs_action_bottom', array_column($state['hooks'], 0));
        self::assertCount(1, $state['messages']);
    }

    public static function hookChanges(): iterable
    {
        foreach ([['resource' => 'data','mode' => 2,'hook' => 'graph_items_remove'],['resource' => 'data','mode' => 3,'hook' => 'graphs_remove'],['resource' => 'graph','mode' => 2,'hook' => 'data_source_remove']] as $case) {
            $hook = $case['hook'];
            unset($case['hook']);
            foreach (['revoke_hook','link_hook','owner_hook'] as $change) yield $hook . ' ' . $change => [$case + [$change => $hook], $hook];
        }
    }

    #[DataProvider('statementFailures')]
    public function testLocalFailurePreservesCallerOwnedTransactionAndChildState(string $resource, int $mode, string $statement): void
    {
        $state = $this->runCascade(['resource' => $resource,'mode' => $mode,'write_failure' => $statement,'caller_transaction' => true]);
        self::assertSame([200,201,202,203], $state['graphs']);
        self::assertSame([300,301,302,303], $state['sources']);
        self::assertSame([600,601,602], $state['items']);
        self::assertSame(1, $state['caller']);
        self::assertTrue($state['transaction']);
        self::assertCount(1, $state['messages']);
        self::assertSame([['data_template_data_id' => 400,'value' => 'owned'],['data_template_data_id' => 401,'value' => 'foreign']], $state['inputs']);
    }

    public static function statementFailures(): iterable
    {
        yield ['graph',1,'DELETE FROM graph_local'];
        yield ['graph',2,'DELETE FROM data_local'];
        yield ['data',2,'DELETE FROM data_source_stats_yearly'];
        yield ['data',3,'DELETE FROM data_local'];
    }

    public function testCollectorHandoffUsesActualPollerIdAndOneConnection(): void
    {
        $state = $this->runCascade(['resource' => 'data','mode' => 2,'remote' => true]);
        self::assertSame([[3,true]], $state['remote_calls']);
        self::assertSame([301,302,303], $state['sources']);
        self::assertSame([301,302,303], $state['remote_sources']);
        self::assertSame([601,602], $state['remote_items']);
        self::assertSame([], $state['messages']);
    }

    public function testCollectorFailureReportsPartialStateAndDoesNotPublishSuccess(): void
    {
        $state = $this->runCascade(['resource' => 'data','mode' => 2,'remote' => true,'remote_write_failure_at' => 2]);
        self::assertSame([300,301,302,303], $state['sources']);
        self::assertSame([600,601,602], $state['items']);
        self::assertSame([601,602], $state['remote_items']);
        self::assertCount(1, $state['messages']);
        self::assertStringContainsString('collector changes may be partial', $state['messages'][0][1]);
        self::assertNotContains('data_source_action_bottom', array_column($state['hooks'], 0));
    }

    public function testConfirmationReadsNamesOnlyAfterFullScopeAdmission(): void
    {
        foreach (['graph','data'] as $resource) {
            $state = $this->runCascade(['resource' => $resource,'confirm' => true]);
            self::assertSame([], $state['messages']);
            self::assertSame([], $state['writes']);
            self::assertStringContainsString('selected_items', $state['html']);
            self::assertNotEmpty($state['protected_queries']);
        }
    }

    public function testExclusiveForeignSourceIsDeniedBeforeGraphOrSourceMutation(): void
    {
        $state = $this->runCascade(['resource' => 'graph','mode' => 2,'denied_source' => true,'remove_foreign_item' => true]);
        self::assertSame([200,201,202,203], $state['graphs']);
        self::assertSame([300,301,302,303], $state['sources']);
        self::assertSame([600,602], $state['items']);
        self::assertSame([], $state['writes']);
        self::assertSame([], $state['hooks']);
        self::assertCount(1, $state['messages']);
    }

    public function testSafeDefaultConfirmationRemainsReachableWithoutAdjacentPermission(): void
    {
        foreach (['graph' => 'denied_source', 'data' => 'denied_graph'] as $resource => $denial) {
            $state = $this->runCascade(['resource' => $resource,'confirm' => true,$denial => true]);
            self::assertSame([], $state['messages']);
            self::assertStringNotContainsString('foreign', $state['html']);
            $document = new DOMDocument();
            $previousErrors = libxml_use_internal_errors(true);
            try {
                $document->loadHTML($state['html']);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
            }
            $xpath = new DOMXPath($document);
            $selected = $xpath->query('//input[@name="selected_items"]')->item(0);
            self::assertNotNull($selected);
            $ids = unserialize($selected->getAttribute('value'), ['allowed_classes' => false]);
            self::assertSame([$resource === 'graph' ? '200' : '300'], $ids);
            self::assertSame(0, $xpath->query('//input[@name="delete_type" and (@value="2" or @value="3")]')->length);
            $executed = $this->runCascade(['resource' => $resource,'ids' => $ids,'omit_mode' => true,$denial => true]);
            self::assertSame([], $executed['messages']);
            self::assertSame($resource === 'graph' ? [201,202,203] : [200,201,202,203], $executed['graphs']);
            self::assertSame($resource === 'data' ? [301,302,303] : [300,301,302,303], $executed['sources']);
        }
    }

    public function testItemOnlyChoiceRemainsReachableWhenAggregateParentIsDenied(): void
    {
        $state = $this->runCascade(['resource' => 'data','confirm' => true,'aggregate' => true,'denied_aggregate' => true]);
        self::assertSame([], $state['messages']);
        self::assertStringNotContainsString('aggregate parent secret', $state['html']);
        $document = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($state['html']);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new DOMXPath($document);
        self::assertSame(1, $xpath->query('//input[@name="delete_type" and @value="1" and @checked]')->length);
        self::assertSame(1, $xpath->query('//input[@name="delete_type" and @value="2"]')->length);
        self::assertSame(0, $xpath->query('//input[@name="delete_type" and @value="3"]')->length);
        $executed = $this->runCascade(['resource' => 'data','mode' => 2,'aggregate' => true,'denied_aggregate' => true]);
        self::assertSame([], $executed['messages']);
        self::assertSame([200,201,202,203], $executed['graphs']);
        self::assertSame([601,602], $executed['items']);
    }

    public function testMaximumSupportedSelectionUsesBoundedQueriesAndOneRemovalHook(): void
    {
        $small = $this->runCascade(['resource' => 'data','mode' => 1,'batch_size' => 1000]);
        $large = $this->runCascade(['resource' => 'data','mode' => 1,'batch_size' => 10000]);
        self::assertSame([], $large['messages']);
        self::assertSame([300,301,302,303], $large['sources']);
        self::assertSame(1, count(array_filter($large['hooks'], static fn($hook) => $hook[0] === 'data_source_remove')));
        self::assertLessThanOrEqual($small['native_queries'] + 500, $large['native_queries']);
        self::assertLessThan(750, $large['native_queries']);
    }

    public function testDependencyCeilingRefusesOversizedMaterializationBeforeTitlesHooksOrWrites(): void
    {
        $admitted = $this->runCascade(['resource' => 'data','mode' => 2,'dependent_items' => 9999]);
        $small = $this->runCascade(['resource' => 'data','mode' => 2,'dependent_items' => 999]);
        self::assertSame([], $admitted['messages']);
        self::assertSame([601,602], $admitted['items']);
        self::assertSame($small['native_queries'], $admitted['native_queries']);
        $denied = $this->runCascade(['resource' => 'data','mode' => 2,'dependent_items' => 10000]);
        self::assertCount(1, $denied['messages']);
        self::assertStringContainsString('Select fewer records', $denied['messages'][0][1]);
        self::assertSame([], $denied['hooks']);
        self::assertSame([], $denied['writes']);
        self::assertSame([], $denied['protected_queries']);
        self::assertSame([300,301,302,303], $denied['sources']);
        self::assertCount(10003, $denied['items']);
        self::assertLessThan(30, $denied['native_queries']);
        $preview = $this->runCascade(['resource' => 'data','confirm' => true,'dependent_items' => 10000]);
        self::assertSame([], $preview['messages']);
        self::assertStringNotContainsString('name=\'delete_type\'', $preview['html']);
        self::assertStringContainsString('selected_items', $preview['html']);
        self::assertSame([], $preview['writes']);
    }
}
