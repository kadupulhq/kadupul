<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DiscoveryContinuationTest extends TestCase
{
    public function testProductionDiscoveryProcessesTheNextDeviceAfterAutomationReturnsFalse(): void
    {
        foreach (['failed-first-device', 'success'] as $mode) {
            $process = new Process([PHP_BINARY, __DIR__ . '/../Fixtures/discovery-continuation-native.php', $mode]);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            self::assertTrue($result['result']);
            self::assertTrue($result['finished']);
            self::assertSame([[7, 5], [8, 5]], $result['templates']);
            self::assertSame($mode === 'success' ? [7, 8] : [8], $result['trees']);
            self::assertSame([7, 8], $result['hosts']);
            self::assertSame($mode === 'success' ? [7, 8] : [8], $result['graphs']);
            self::assertSame([1, 1], $result['done']);
        }
    }
}
