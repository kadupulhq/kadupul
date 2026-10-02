<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class ReportPersistenceNativeCoverageTest extends TestCase
{
    /** @dataProvider addCases */
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

    /** @dataProvider reorderCases */
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

    /** @dataProvider removeCases */
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
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($directory . '/*.coverage') as $report) {
                unlink($report);
            }
            rmdir($directory);
        }
    }
}
