<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Application\Port\RrdTarget;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Domain\RrdCommand;
use Kadupul\Graphing\Infrastructure\Rrd\ConfiguredRootTargetLocator;
use Kadupul\Graphing\Infrastructure\Rrd\InvalidRrdConfiguration;
use Kadupul\Graphing\Infrastructure\Rrd\RrdStorageFactory;
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolInspector;
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolUpdater;
use Kadupul\Graphing\Infrastructure\Rrd\UnconfiguredRrdStorage;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Telemetry\Contract\RejectionReason;
use PHPUnit\Framework\TestCase;

final class RrdStorageFactoryTest extends TestCase
{
    public function testEmptySettingsGiveTheRefusingStandIn(): void
    {
        $factory = new RrdStorageFactory();

        self::assertInstanceOf(UnconfiguredRrdStorage::class, $factory->locator('', []));
        self::assertInstanceOf(UnconfiguredRrdStorage::class, $factory->inspector(''));
        self::assertInstanceOf(UnconfiguredRrdStorage::class, $factory->updater(''));
    }

    public function testTheStandInRefusesEveryStorageCall(): void
    {
        $storage = new UnconfiguredRrdStorage();
        $target = new RrdTarget('/nowhere/x.rrd');
        $calls = [
            static fn() => $storage->locate(new StorageReference('local-data:7')),
            static fn() => $storage->inspect($target),
            static fn() => $storage->update($target, new RrdCommand('update', ['--template', 'a', '1:2'])),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Unconfigured storage must refuse.');
            } catch (StorageRejected $e) {
                self::assertSame(RejectionReason::StorageFailure, $e->reason);
                self::assertStringContainsString('not configured', $e->getMessage());
                self::assertStringNotContainsString('/', $e->getMessage());
            }
        }
    }

    public function testConfiguredSettingsGiveTheRealAdapters(): void
    {
        $root = sys_get_temp_dir();
        $binary = PHP_BINARY;
        $factory = new RrdStorageFactory();

        self::assertInstanceOf(ConfiguredRootTargetLocator::class, $factory->locator($root, []));
        self::assertInstanceOf(RrdtoolInspector::class, $factory->inspector($binary));
        self::assertInstanceOf(RrdtoolUpdater::class, $factory->updater($binary));
    }

    public function testAnInvalidNonEmptySettingFailsLoudlyInsteadOfDisablingStorage(): void
    {
        $this->expectException(InvalidRrdConfiguration::class);
        (new RrdStorageFactory())->inspector('rrdtool');
    }
}
