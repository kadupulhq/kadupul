<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceSiteWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DeviceDisableFailureTest extends TestCase
{
    public static function failures(): iterable
    {
        foreach ([false, true] as $callerOwnsTransaction) {
            foreach (['primary', 'deadlock', 'remote', 'lost', 'none'] as $failure) {
                yield [$callerOwnsTransaction, $failure];
            }
        }
    }

    #[DataProvider('failures')]
    public function testFailedDisableCannotSaveOutsideTheTransaction(bool $callerOwnsTransaction, string $failure): void
    {
        require __DIR__ . '/device_disable_failure_fixture.php';
        require dirname(__DIR__, 2) . '/lib/api_device.php';
        $GLOBALS['disableDb'] = $db = new \DisableFailureConnection($callerOwnsTransaction, $failure);
        $result = LegacyDeviceSiteWriter::save($db, ['id' => 42, 'site_id' => 0, 'disabled' => 'on']);
        self::assertSame($failure === 'none' ? 42 : false, $result);
        self::assertSame($failure === 'none' ? 1 : 0, $db->saves);
        self::assertSame($failure === 'remote' ? 2 : 1, $db->updates);
        self::assertSame(in_array($failure, ['primary', 'deadlock'], true) ? 0 : 1, $db->reads);
        self::assertSame($callerOwnsTransaction && !in_array($failure, ['deadlock', 'lost'], true), $db->inTransaction());
    }
}
