<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorTransfer;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeviceCollectorCleanupTest extends TestCase
{
    public function testPrimaryAndUnchangedOwnersHaveNoCleanupTransaction(): void
    {
        $primary = $this->createMock(PDO::class);
        $primary->expects(self::never())->method('beginTransaction');
        $primary->expects(self::never())->method('prepare');
        $primary->expects(self::never())->method('commit');
        (new DeviceCollectorTransfer(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->finish($primary, 42, [], [7 => 1, 8 => 3], 3);
    }

    public function testCleanupNeverTakesOverACallerTransaction(): void
    {
        $primary = $this->createMock(PDO::class);
        $primary->method('inTransaction')->willReturn(true);
        $primary->expects(self::never())->method('beginTransaction');
        $primary->expects(self::never())->method('rollBack');
        $primary->expects(self::never())->method('commit');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Collector cleanup transaction unavailable');
        (new DeviceCollectorTransfer(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->finish($primary, 42, [], [7 => 2], 1);
    }

    public function testRejectedTransactionCannotReadOrPurge(): void
    {
        $primary = $this->createMock(PDO::class);
        $primary->method('inTransaction')->willReturn(false);
        $primary->expects(self::once())->method('beginTransaction')->willReturn(false);
        $primary->expects(self::never())->method('prepare');
        $this->expectException(RuntimeException::class);
        (new DeviceCollectorTransfer(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->finish($primary, 42, [], [7 => 2], 1);
    }
}
