<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/DeviceGraphCallerNativeHarness.php';
require_once dirname(__DIR__, 3) . '/Helpers/DeviceGraphCallerCoverageRegistration.php';
require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

require_once dirname(__DIR__, 3) . '/Helpers/PestCodeCoverageCompatibility.php';
use PHPUnit\Framework\TestCase;

final class DeviceGraphCallerNativeTest extends TestCase
{
    use \PestCodeCoverageCompatibility;
    private function runCaller(array $scenario): array
    {
        $state = DeviceGraphCallerNativeHarness::run($scenario, $this->getTestResultObject()->getCodeCoverage());
        self::assertNull($state['fatal']);
        self::assertDoesNotMatchRegularExpression('/(?:PHP (?:Warning|Fatal|Notice)|Uncaught)/', $state['stderr']);
        return $state;
    }

    public function testNativeNewGraphPromptAndAutosaveUseCurrentDevicePolicy(): void
    {
        $state = $this->runCaller(['fields' => ['action' => 'save','host_id' => 13,'save_component_graph' => 1,'cg_5' => 'on']]);
        self::assertSame([], $state['events']);
        self::assertSame([], $state['graphs']);
        self::assertSame('new_graph_access_denied', $state['messages'][0][0]);
        self::assertContains('Location: graphs_new.php?host_id=12&header=false', $state['headers']);
        $state = $this->runCaller(['fields' => ['action' => 'save','host_id' => 12,'save_component_graph' => 1,'cg_5' => 'on'],'prompt' => false]);
        self::assertSame(12, $state['graphs'][0]['host_id']);
        self::assertSame(12, $state['data'][0]['host_id']);
        self::assertContains('Location: graphs_new.php?host_id=12&header=false', $state['headers']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('autosaveCases')]
    public function testNoPromptWrapperRevalidatesDeviceBeforeCreation(int $host, bool $allowed): void
    {
        $state = $this->runCaller(['autosave_wrapper' => true,'autosave_host' => $host,'prompt' => false,'fields' => ['action' => 'save']]);
        self::assertCount($allowed ? 1 : 0, $state['graphs']);
        self::assertCount($allowed ? 1 : 0, $state['data']);
        if (!$allowed) {
            self::assertSame(['graph-detail'], array_column($state['events'], 0));
            self::assertSame('AUTH', $state['logs'][0][1]);
        } else self::assertSame($host, $state['graphs'][0]['host_id']);
        self::assertContains('Location: graphs_new.php?host_id=' . $host . '&header=false', $state['headers']);
    }
    public static function autosaveCases(): iterable
    {
        yield 'permitted' => [12,true];
        yield 'foreign' => [13,false];
        yield 'None' => [0,true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('graphSaveCases')]
    public function testBothNewGraphSavePhasesRefuseBeforePersistedStateAndPreserveAdmittedHandoff(array $scenario, bool $allowed, int $host): void
    {
        $state = $this->runCaller($scenario);
        self::assertCount($allowed ? 1 : 0, $state['graphs']);
        self::assertCount($allowed ? 1 : 0, $state['data']);
        if ($allowed) {
            self::assertSame($host, $state['graphs'][0]['host_id']);
            self::assertContains('Location: graphs_new.php?host_id=' . $host . '&header=false', $state['headers']);
        } else {
            self::assertSame([], $state['events']);
            self::assertSame('new_graph_access_denied', $state['messages'][0][0]);
            self::assertContains('Location: graphs_new.php?host_id=12&header=false', $state['headers']);
        }
    }

    public static function graphSaveCases(): iterable
    {
        foreach (['save_component_graph','save_component_new_graphs'] as $phase) {
            foreach ([12 => true,13 => false,0 => true,-1 => false] as $host => $allowed) {
                yield $phase . ' host' . $host => [['prompt' => false,'fields' => ['action' => 'save','host_id' => $host,$phase => 1,'cg_5' => 'on','selected_graphs_array' => serialize(['cg' => [5 => []]])]],$allowed,$host];
            }
            yield $phase . ' omitted host' => [['prompt' => false,'fields' => ['action' => 'save',$phase => 1,'cg_5' => 'on','selected_graphs_array' => serialize(['cg' => [5 => []]])]],false,0];
        }
        foreach ([['group' => true],['policy' => 1],['auth_method' => 0]] as $index => $policy) yield 'actual policy ' . $index => [$policy + ['prompt' => false,'fields' => ['action' => 'save','host_id' => 12,'save_component_new_graphs' => 1,'selected_graphs_array' => serialize(['cg' => [5 => []]])]],true,12];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('queryCases')]
    public function testNativeQueryReloadPreservesForeignCacheAndRunsAdmittedDevice(int $host, bool $allowed): void
    {
        $state = $this->runCaller(['fields' => ['action' => 'query_reload','host_id' => $host,'id' => 7]]);
        self::assertSame($allowed ? ['query'] : [], array_column($state['events'], 0));
        self::assertSame($allowed ? 2 : 1, $state['query'][0]['last_run']);
        self::assertSame($allowed ? 'rerun' : 'unchanged', $state['cache'][0]['field_value']);
        self::assertSame(1, $state['query'][1]['last_run']);
        self::assertSame('unchanged', $state['cache'][1]['field_value']);
        if (!$allowed) self::assertSame('new_graph_access_denied', $state['messages'][0][0]);
    }

    public static function queryCases(): iterable
    {
        yield 'allowed' => [12,true];
        yield 'foreign' => [13,false];
        yield 'None' => [0,false];
        yield 'negative' => [-1,false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pageCases')]
    public function testActualNewGraphPageUsesAllowedDefaultAndRendersOnlyFallbackName(array $scenario, int $host, string $label): void
    {
        $state = $this->runCaller($scenario + ['method' => 'GET']);
        self::assertStringContainsString($label, $state['html']);
        if ($host === 12) self::assertStringNotContainsString('C denied device', $state['html']);
        $dom = new DOMDocument();
        @$dom->loadHTML($state['html']);
        $xpath = new DOMXPath($dom);
        self::assertSame((string) $host, $xpath->evaluate('string(//input[@name="host_id"]/@value)'));
    }

    public static function pageCases(): iterable
    {
        yield 'alphabetically first denied' => [[],12,'B allowed disabled device'];
        yield 'denied request falls back' => [['fields' => ['host_id' => 13]],12,'B allowed disabled device'];
        yield 'denied remembered selection falls back' => [['session' => ['sess_grn_host_id' => 13]],12,'B allowed disabled device'];
        yield 'no permitted devices' => [['no_devices' => true],0,'New Graphs for None Host Type'];
        yield 'no authentication keeps first device' => [['auth_method' => 0],11,'A denied device'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostActionCases')]
    public function testIndividualHostControllersRefuseBeforeTheirActualSink(string $action, string $sink, ?int $host, bool $allowed): void
    {
        $field = in_array($action, ['edit','ping_host'], true) ? 'id' : 'host_id';
        $state = $this->runCaller(['page' => 'host.php','method' => in_array($action, ['edit','ping_host'], true) ? 'GET' : 'POST','fields' => ['action' => $action,$field => $host,'id' => in_array($action, ['edit','ping_host'], true) ? $host : ($action === 'gt_remove' ? 5 : 7),'snmp_query_id' => 7,'data_query_id' => 7,'graph_template_id' => 5,'reindex_method' => 1]]);
        if ($allowed) self::assertContains($sink, array_column($state['events'], 0));
        else {
            self::assertSame([], $state['events']);
            if ($action === 'repopulate' && ($host === 0 || $host === null)) self::assertSame('repopulate_error', $state['messages'][0][0]);
            else {
                self::assertContains('Location: permission_denied.php', $state['headers']);
                self::assertSame('AUTH', $state['logs'][0][1]);
            }
        }
    }

    public static function hostActionCases(): iterable
    {
        foreach (['ping_host' => 'ping','enable_debug' => 'debug-enable','disable_debug' => 'debug-disable','repopulate' => 'repopulate','query_add' => 'query-add','query_reload' => 'query','query_verbose' => 'query','query_remove' => 'query-remove','query_change' => 'query-change','gt_add' => 'gt-add','gt_remove' => 'gt-remove'] as $action => $sink) {
            foreach ([12 => true,13 => false,0 => false] as $host => $allowed) yield $action . ' ' . $host => [$action,$sink,$host,$allowed];
            yield $action . ' omitted' => [$action,$sink,null,false];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('editCases')]
    public function testActualEditControllerAdmitsBlankCreateAndRefusesForeignDetails(?int $id, bool $allowed): void
    {
        $fields = ['action' => 'edit'];
        if ($id !== null) $fields['id'] = $id;
        $state = $this->runCaller(['page' => 'host.php','method' => 'GET','fields' => $fields]);
        if ($allowed) {
            self::assertStringContainsString('<form', $state['html']);
            self::assertStringContainsString($id ? 'B allowed disabled device' : 'Device [new]', $state['html']);
        } else {
            self::assertSame([], $state['events']);
            self::assertStringNotContainsString('<form', $state['html']);
            self::assertContains('Location: permission_denied.php', $state['headers']);
        }
    }

    public static function editCases(): iterable
    {
        yield 'allowed' => [12,true];
        yield 'foreign' => [13,false];
        yield 'zero create' => [0,true];
        yield 'omitted create' => [null,true];
    }

    public function testHostFormSaveSkipsHookAndRetainsOriginalRedirectOnApiFailure(): void
    {
        $state = $this->runCaller(['page' => 'host.php','api' => true,'api_form' => true,'api_save_failure' => true,'fields' => ['action' => 'save','id' => 12,'host_template_id' => 0,'save_component_host' => 1,'description' => 'Changed','hostname' => 'localhost']]);
        self::assertSame(['hook'], array_column($state['events'], 0));
        self::assertSame('api_device_save', $state['events'][0][1]);
        self::assertSame(12, (int) $state['events'][0][2]['id']);
        self::assertNull($state['api_error']);
        self::assertSame('B allowed disabled device', $state['hosts'][1]['description']);
        self::assertContains('Location: host.php?header=false&action=edit&id=12', $state['headers']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bulkCases')]
    public function testWholeBulkControllerGuardsEveryModeBeforeBatchHooks(string $mode, string $sink, bool $allowed): void
    {
        $selection = $allowed ? [12] : [12,13];
        $state = $this->runCaller(['page' => 'host.php','fields' => ['action' => 'actions','drp_action' => $mode,'selected_items' => serialize($selection),'tree_id' => 20,'tree_item_id' => 0,'delete_type' => 2,'report_id' => 1,'timespan' => 1,'align' => 1]]);
        if ($allowed) {
            self::assertContains($sink, array_column($state['events'], 0));
            self::assertContains('Location: host.php?header=false', $state['headers']);
        } else {
            self::assertSame([], $state['events']);
            self::assertContains('Location: permission_denied.php', $state['headers']);
            self::assertSame('AUTH', $state['logs'][0][1]);
        }
    }
    public static function bulkCases(): iterable
    {
        foreach (['1' => 'bulk-delete','2' => 'bulk-enable','3' => 'bulk-disable','4' => 'bulk-change','5' => 'bulk-clear','6' => 'bulk-automation','7' => 'bulk-sync','8' => 'bulk-report','tr_20' => 'bulk-tree','plugin' => 'hook'] as $mode => $sink) foreach ([true,false] as $allowed) yield $mode . ' ' . ($allowed ? 'admitted' : 'mixed refused') => [(string) $mode,$sink,$allowed];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('directCreateCases')]
    public function testDirectGraphCreateControllerAuthorizesDeviceBeforeTemplateReadAndCreation(int $host, bool $allowed): void
    {
        $state = $this->runCaller(['page' => 'graphs.php','fields' => ['action' => 'save','save_component_graph_new' => 1,'graph_template_id' => 5,'host_id' => $host]]);
        self::assertCount($allowed ? 1 : 0, $state['graphs']);
        self::assertCount($allowed ? 1 : 0, $state['data']);
        if ($allowed) {
            self::assertSame($host, $state['graphs'][0]['host_id']);
            self::assertContains('Location: graphs.php?action=graph_edit&header=false&id=1', $state['headers']);
        } else {
            self::assertSame([], $state['events']);
            self::assertSame('graph_access_denied', $state['messages'][0][0]);
            self::assertSame([], array_filter($state['reads'], static fn($row) => str_contains($row[0], 'FROM graph_template_input')));
        }
    }
    public static function directCreateCases(): iterable
    {
        yield 'permitted disabled device' => [12,true];
        yield 'foreign device' => [13,false];
        yield 'None' => [0,true];
        yield 'negative' => [-1,false];
    }

    public function testPromptConfirmationCarriesActualSelectedTemplateAndHost(): void
    {
        $state = $this->runCaller(['fields' => ['action' => 'save','host_id' => 12,'save_component_graph' => 1,'cg_5' => 'on']]);
        self::assertSame([], $state['graphs']);
        self::assertSame([], $state['data']);
        $dom = new DOMDocument();
        @$dom->loadHTML($state['html']);
        $xpath = new DOMXPath($dom);
        self::assertSame('12', $xpath->evaluate('string(//input[@name="host_id"]/@value)'));
        self::assertSame(['cg' => [5 => [5 => true]]], unserialize($xpath->evaluate('string(//input[@name="selected_graphs_array"]/@value)'), ['allowed_classes' => false]));
        self::assertSame('1', $xpath->evaluate('string(//input[@name="save_component_new_graphs"]/@value)'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostSaveCases')]
    public function testActualHostSaveRefusesForeignAndAdmitsCreateBeforeSaveHook(int $id, bool $allowed): void
    {
        $state = $this->runCaller(['page' => 'host.php','fields' => ['action' => 'save','id' => $id,'host_template_id' => 0,'save_component_host' => 1,'description' => 'Changed','hostname' => 'localhost']]);
        if ($allowed) {
            self::assertSame('device-save', $state['events'][0][0]);
            self::assertSame('host_save', $state['events'][1][1]);
            self::assertSame('Changed', $state['hosts'][$id === 0 ? 3 : 1]['description']);
        } else {
            self::assertSame([], $state['events']);
            self::assertSame('C denied device', $state['hosts'][2]['description']);
            self::assertContains('Location: permission_denied.php', $state['headers']);
        }
    }
    public static function hostSaveCases(): iterable
    {
        yield 'existing' => [12,true];
        yield 'denied' => [13,false];
        yield 'create' => [0,true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiCases')]
    public function testActualWebApiGuardAndWriterPersistAdmittedDevicesAndRefuseBeforeMutation(int|string $id, bool $allowed, bool $failure): void
    {
        $state = $this->runCaller(['api' => true,'id' => $id,'api_save_failure' => $failure]);
        self::assertSame('cli-server', $state['sapi']);
        self::assertNull($state['api_error']);
        if (!$allowed) {
            self::assertFalse($state['api_result']);
            self::assertSame('AUTH', $state['logs'][0][1]);
            self::assertSame([], $state['events']);
        } elseif ($failure) {
            self::assertFalse($state['api_result']);
            self::assertSame('B allowed disabled device', $state['hosts'][1]['description']);
        } else {
            self::assertGreaterThan(0, $state['api_result']);
            self::assertSame('API changed', $state['hosts'][$id === 0 ? 3 : 1]['description']);
        }
    }
    public static function apiCases(): iterable
    {
        yield 'permitted web save' => [12,true,false];
        yield 'denied web save' => [13,false,false];
        yield 'new device' => [0,true,false];
        yield 'failed persistence' => [12,true,true];
        yield 'invalid SQL-shaped identifier' => ['12 OR 1=1',false,false];
    }

    public function testActualCliApiRetainsTrustedForeignDeviceAndNewDeviceCompatibility(): void
    {
        foreach ([13,0] as $id) {
            $state = DeviceGraphCallerNativeHarness::runCliApi(['id' => $id], $this->getTestResultObject()->getCodeCoverage());
            self::assertSame('cli', $state['sapi']);
            self::assertNull($state['fatal']);
            self::assertNull($state['api_error']);
            self::assertSame('', $state['stderr']);
            self::assertSame('', $state['stdout']);
            self::assertSame([], $state['logs']);
            self::assertGreaterThan(0, $state['api_result']);
            self::assertSame('API changed', $state['hosts'][$id === 0 ? 3 : 2]['description']);
        }
    }

}
