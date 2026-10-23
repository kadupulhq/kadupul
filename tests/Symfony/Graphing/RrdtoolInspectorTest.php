<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Application\Port\RrdTarget;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolInspector;
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolProcess;
use Kadupul\Telemetry\Contract\RejectionReason;
use PHPUnit\Framework\TestCase;

final class RrdtoolInspectorTest extends TestCase
{
    private TemporaryRrdRoot $root;
    private string $rrdtool;

    protected function setUp(): void
    {
        $this->rrdtool = TemporaryRrdRoot::rrdtoolOrSkip($this);
        $this->root = TemporaryRrdRoot::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->root->remove();
        }
    }

    public function testItReportsEachDataSourceTypeAndTheLastUpdate(): void
    {
        $file = $this->root->createRrd($this->rrdtool, 'mixed.rrd', [
            'DS:traffic_in:COUNTER:600:0:U',
            'DS:temp:GAUGE:600:U:U',
            'DS:delta:DERIVE:600:U:U',
            'DS:events:ABSOLUTE:600:0:U',
        ]);

        $info = $this->inspector()->inspect(new RrdTarget($file));

        self::assertSame(GraphingSamples::EPOCH, $info->lastUpdate);
        self::assertSame(['traffic_in' => 'COUNTER', 'temp' => 'GAUGE', 'delta' => 'DERIVE', 'events' => 'ABSOLUTE'], $info->dataSourceTypes);
    }

    public function testAMissingFileIsTargetMissing(): void
    {
        $this->assertRejected(RejectionReason::TargetMissing, $this->root->path . '/absent.rrd');
    }

    public function testAFileRrdtoolCannotReadIsAStorageFailureWithoutItsPath(): void
    {
        $this->assertRejected(RejectionReason::StorageFailure, $this->root->write('garbage.rrd', 'not an rrd file'));
    }

    private function inspector(): RrdtoolInspector
    {
        return new RrdtoolInspector(new RrdtoolProcess($this->rrdtool));
    }

    private function assertRejected(RejectionReason $reason, string $file): void
    {
        try {
            $this->inspector()->inspect(new RrdTarget($file));
            self::fail('The file was inspected.');
        } catch (StorageRejected $e) {
            self::assertSame($reason, $e->reason);
            self::assertStringNotContainsString('/', $e->getMessage());
        }
    }
}
