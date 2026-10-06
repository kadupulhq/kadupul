<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/DeviceGraphCallerNativeHarness.php';
require_once dirname(__DIR__, 3) . '/Helpers/DeviceGraphCallerCoverageRegistration.php';
require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';
require_once dirname(__DIR__, 3) . '/Helpers/PestCodeCoverageCompatibility.php';

final class GraphSaveDependentCallerNativeTest extends PHPUnit\Framework\TestCase
{
    use PestCodeCoverageCompatibility;
    private function request(array $scenario): array
    {
        $state = DeviceGraphCallerNativeHarness::run(['page' => 'graphs.php','graph_handoff' => true] + $scenario, $this->getTestResultObject()->getCodeCoverage());
        self::assertNull($state['fatal']);
        self::assertDoesNotMatchRegularExpression('/PHP (?:Warning|Fatal|Notice)|Uncaught/', $state['stderr']);
        return $state;
    }
    private function saveFields(int $destination, int $previous): array
    {
        return ['action' => 'save','save_component_graph' => 1,'local_graph_id' => 101,'graph_template_graph_id' => 201,'local_graph_template_graph_id' => 0,'graph_template_id' => 0,'graph_template_id_prev' => 0,'host_id' => $destination,'host_id_prev' => $previous,'image_format_id' => 1,'title' => 'After','height' => 100,'width' => 300,'upper_limit' => '','lower_limit' => '','vertical_label' => '','auto_scale_opts' => 0,'base_value' => 1000,'unit_value' => '','unit_exponent_value' => 0];
    }
    #[PHPUnit\Framework\Attributes\DataProvider('saveCases')]
    public function testActualGraphSavePreflightsLinkedOwnersBeforeGraphAndTitleWrites(array $scenario, int $destination, int $previous, bool $allowed): void
    {
        $state = $this->request($scenario + ['fields' => $this->saveFields($destination, $previous)]);
        self::assertSame($allowed ? $destination : 12, $state['graphs'][0]['host_id']);
        self::assertSame($allowed ? 'After' : 'Before', $state['metadata'][0]['title']);
        self::assertSame($allowed ? 'After' : 'Before', $state['metadata'][0]['title_cache']);
        if (!$allowed) {
            self::assertSame([], $state['events']);
            self::assertSame([], $state['writes']);
            self::assertSame(($scenario['foreign_child'] ?? false) || ($scenario['foreign_poller'] ?? false) ? 13 : 12, $state['poller'][0]['host_id']);
            self::assertSame('graph_access_denied', $state['messages'][0][0]);
            self::assertSame(($scenario['foreign_child'] ?? false) ? 13 : 12, $state['data'][0]['host_id']);
        } else {
            self::assertSame($destination, $state['data'][0]['host_id']);
            self::assertSame($destination, $state['poller'][0]['host_id']);
        }
    }
    public static function saveCases(): iterable
    {
        yield 'foreign linked source' => [['foreign_child' => true],0,12,false];
        yield 'foreign linked source forged previous' => [['foreign_child' => true],0,0,false];
        yield 'foreign poller owner' => [['foreign_poller' => true],0,12,false];
        yield 'admitted non-device move' => [[],0,12,true];
        yield 'admitted forged previous still moves children' => [[],0,0,true];
        yield 'unchanged SNMP host' => [['snmp_graph' => true],12,12,true];
        yield 'unchanged SNMP host forged previous' => [['snmp_graph' => true],12,0,true];
        yield 'denied destination' => [[],13,12,false];
    }
    #[PHPUnit\Framework\Attributes\DataProvider('bulkCases')]
    public function testActualBulkDeviceChangePreservesSupportedOwnershipAndRefusal(array $scenario, ?int $destination, bool $allowed): void
    {
        $fields = ['action' => 'actions','drp_action' => 5,'selected_items' => serialize([101])];
        if ($destination !== null) $fields['host_id'] = $destination;
        $state = $this->request($scenario + ['fields' => $fields]);
        self::assertSame($allowed ? $destination : 12, $state['graphs'][0]['host_id']);
        self::assertSame('Before', $state['metadata'][0]['title']);
        self::assertSame('Before', $state['metadata'][0]['title_cache']);
        if ($allowed) {
            self::assertSame(['graph-title','snmpagent-bottom','hook'], array_column($state['events'], 0));
            self::assertSame($destination, $state['data'][0]['host_id']);
            self::assertSame($destination, $state['poller'][0]['host_id']);
        } else {
            self::assertSame([], $state['writes']);
            if ($scenario['deny_graph'] ?? false) self::assertSame([], $state['events']);
            else {
                self::assertSame(['snmpagent-bottom','hook'], array_column($state['events'], 0));
                self::assertSame('graphs_action_bottom', $state['events'][1][1]);
            }
            self::assertSame(($scenario['foreign_child'] ?? false) ? 13 : 12, $state['data'][0]['host_id']);
            self::assertSame(($scenario['foreign_child'] ?? false) || ($scenario['foreign_poller'] ?? false) ? 13 : 12, $state['poller'][0]['host_id']);
            self::assertNotEmpty($state['messages']);
        }
        self::assertContains('Location: graphs.php?header=false', $state['headers']);
    }
    public static function bulkCases(): iterable
    {
        yield 'persisted graph policy refused' => [['deny_graph' => true,'graph_auth_method' => 2],0,false];
        yield 'admitted None' => [[],0,true];
        yield 'admitted positive destination' => [[],12,true];
        yield 'denied positive destination' => [[],13,false];
        yield 'missing destination' => [[],null,false];
        yield 'foreign linked source' => [['foreign_child' => true],0,false];
        yield 'foreign poller owner' => [['foreign_poller' => true],0,false];
        yield 'SNMP association preserved' => [['snmp_graph' => true],0,false];
    }

}
