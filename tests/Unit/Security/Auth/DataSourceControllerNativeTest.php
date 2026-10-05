<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/DataSourceControllerNativeHarness.php';
require_once dirname(__DIR__, 3) . '/Helpers/DataSourceControllerCoverageRegistration.php';
require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';
require_once dirname(__DIR__, 3) . '/Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataSourceControllerNativeTest extends TestCase
{
    use PestCodeCoverageCompatibility;

    private function request(array $fields): array
    {
        $state = DataSourceControllerNativeHarness::run(['fields' => $fields, 'method' => in_array($fields['action'], ['data_edit', 'ds_edit'], true) ? 'GET' : 'POST'], $this->getTestResultObject()->getCodeCoverage());
        self::assertNull($state['fatal']);
        self::assertDoesNotMatchRegularExpression('/(?:PHP (?:Warning|Fatal|Notice)|Uncaught)/', $state['stderr']);
        // The controller registers its action menu before per-record authorization.
        self::assertSame(['hook', 'data_source_action_array', [1 => 'Delete', 3 => 'Change Device', 8 => 'Reapply Suggested Names', 6 => 'Enable', 7 => 'Disable']], array_shift($state['events']));
        return $state;
    }

    #[DataProvider('sourceActions')]
    public function testExistingSourceActionsAdmitOwnerAndPreserveStateOnRefusal(string $action, int $id, bool $allowed): void
    {
        $state = $this->request(['action' => $action, 'id' => $id, 'host_id' => 12]);
        $expected = $state['initial'];
        if (!$allowed) {
            self::assertSame($expected, $state['persisted']);
            self::assertSame([], $state['events']);
            self::assertContains('Location: data_sources.php', $state['headers']);
            self::assertSame('AUTH', $state['logs'][0][1]);
            self::assertStringNotContainsString('<form', $state['html']);
            return;
        }
        if ($action === 'rrd_add') {
            $expected['data_template_rrd'][] = ['id' => 34, 'local_data_id' => 21, 'data_template_id' => 0, 'rrd_maximum' => '100', 'rrd_minimum' => '0', 'rrd_heartbeat' => 600, 'data_source_type_id' => 1, 'data_source_name' => 'ds'];
            self::assertContains('Location: data_sources.php?header=false&action=ds_edit&id=21&view_rrd=34', $state['headers']);
        } elseif (in_array($action, ['ds_enable', 'ds_disable'], true)) {
            $expected['data_template_data'][0]['active'] = $action === 'ds_enable' ? 'on' : '';
            self::assertContains('Location: data_sources.php?header=false&action=ds_edit&id=21', $state['headers']);
        } else {
            self::assertStringContainsString('<form', $state['html']);
            if ($action === 'data_edit') self::assertStringContainsString('name="local_data_id" value="21"', $state['html']);
            else self::assertStringContainsString('Allowed source', $state['html']);
        }
        self::assertSame($expected, $state['persisted']);
    }

    public static function sourceActions(): iterable
    {
        foreach (['rrd_add', 'ds_enable', 'ds_disable', 'data_edit', 'ds_edit'] as $action) {
            foreach ([21 => true, 22 => false, 99 => false] as $id => $allowed) yield $action . ' source ' . $id => [$action, $id, $allowed];
        }
    }

    #[DataProvider('rrdRemovalCases')]
    public function testRrdRemovalUsesItemOwnershipBeforeBothWrites(int $item, int $source, bool $allowed): void
    {
        $state = $this->request(['action' => 'rrd_remove', 'id' => $item, 'local_data_id' => $source]);
        $expected = $state['initial'];
        if ($allowed) {
            array_shift($expected['data_template_rrd']);
            $expected['graph_templates_item'][0]['task_item_id'] = 0;
            self::assertContains('Location: data_sources.php?header=false&action=ds_edit&id=21', $state['headers']);
        } else {
            self::assertSame([], $state['events']);
            self::assertContains('Location: data_sources.php', $state['headers']);
            self::assertSame('AUTH', $state['logs'][0][1]);
        }
        self::assertSame($expected, $state['persisted']);
    }

    public static function rrdRemovalCases(): iterable
    {
        yield 'admitted item and source' => [31, 21, true];
        yield 'denied owner' => [32, 22, false];
        yield 'item whose source is missing' => [33, 99, false];
        yield 'missing item' => [99, 21, false];
        yield 'denied item paired with admitted source' => [32, 21, false];
    }

    #[DataProvider('newDeviceCases')]
    public function testNewSourceSelectionChecksPositiveDeviceAndPreservesRedirectOnlySave(string $action, int $host, bool $allowed): void
    {
        $state = $this->request(['action' => $action, 'id' => 0, 'host_id' => $host, 'local_data_id' => 0, 'data_template_id' => 0, 'save_component_data_source_new' => 1]);
        self::assertSame($state['initial'], $state['persisted']);
        if (!$allowed) {
            self::assertSame([], $state['events']);
            self::assertContains('Location: data_sources.php', $state['headers']);
            self::assertSame('AUTH', $state['logs'][0][1]);
        } elseif ($action === 'ds_edit') {
            self::assertStringContainsString('<form', $state['html']);
            self::assertStringContainsString('Data Template Selection [new]', $state['html']);
        } else {
            self::assertContains('Location: data_sources.php?header=false&action=ds_edit&host_id=12&new=1', $state['headers']);
        }
    }

    public static function newDeviceCases(): iterable
    {
        foreach (['ds_edit', 'save'] as $action) foreach ([12 => true, 13 => false, 99 => false] as $host => $allowed) yield $action . ' device ' . $host => [$action, $host, $allowed];
    }
}
