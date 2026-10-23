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
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolProcess;
use Kadupul\Graphing\Infrastructure\Rrd\RrdtoolUpdater;
use Kadupul\Telemetry\Contract\RejectionReason;
use PHPUnit\Framework\TestCase;

final class RrdtoolUpdaterTest extends TestCase
{
    private const T = GraphingSamples::EPOCH;

    private TemporaryRrdRoot $root;
    private string $rrdtool;
    private string $file;

    protected function setUp(): void
    {
        $this->rrdtool = TemporaryRrdRoot::rrdtoolOrSkip($this);
        $this->root = TemporaryRrdRoot::create();
        $this->file = $this->root->createRrd($this->rrdtool, 'traffic.rrd', ['DS:traffic_in:COUNTER:600:0:U', 'DS:traffic_out:COUNTER:600:0:U']);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->root->remove();
        }
    }

    public function testAnUpdateWritesTheTemplatedRows(): void
    {
        $this->update(['--template', 'traffic_out:traffic_in', '1000000300:20:10', '1000000600:40:U']);

        self::assertSame([self::T + 600, ['traffic_in' => 'U', 'traffic_out' => '40']], $this->root->lastUpdate($this->rrdtool, $this->file));
    }

    public function testARowAtOrBeforeTheLastUpdateIsOutOfOrderWithoutThePath(): void
    {
        $this->update(['--template', 'traffic_in', '1000000600:1']);

        $e = $this->failure(['--template', 'traffic_in', '1000000600:2']);

        self::assertSame(RejectionReason::OutOfOrder, $e->reason);
        self::assertSame('RRDtool refused second 1000000600 because the file already holds second 1000000600.', $e->getMessage());
    }

    public function testAnyOtherRrdtoolErrorIsAStorageFailureWithoutThePath(): void
    {
        $e = $this->failure(['--template', 'missing_ds', '1000000300:1']);

        self::assertSame(RejectionReason::StorageFailure, $e->reason);
        self::assertStringContainsString('exited with status 1', $e->getMessage());
        self::assertStringContainsString("unknown DS name 'missing_ds'", $e->getMessage());
        self::assertStringNotContainsString('/', $e->getMessage());
    }

    public function testAFileRrdtoolCannotOpenIsAStorageFailureWithoutThePath(): void
    {
        $garbage = $this->root->write('garbage.rrd', 'not an rrd file');

        try {
            (new RrdtoolUpdater(new RrdtoolProcess($this->rrdtool)))->update(new RrdTarget($garbage), new RrdCommand('update', ['--template', 'x', '1000000300:1']));
            self::fail('The update succeeded.');
        } catch (StorageRejected $e) {
            self::assertSame(RejectionReason::StorageFailure, $e->reason);
            self::assertStringNotContainsString($this->root->path, $e->getMessage());
            self::assertStringNotContainsString('/', $e->getMessage());
        }
    }

    public function testOnlyAnUpdateWithOptionFreeRowsIsRun(): void
    {
        $updater = new RrdtoolUpdater(new RrdtoolProcess($this->rrdtool));
        foreach ([new RrdCommand('create', ['--template', 'x', '1:1']), new RrdCommand('update', ['--template', 'traffic_in', '--daemon', '1000000300:1']), new RrdCommand('update', ['1000000300:1:2'])] as $command) {
            try {
                $updater->update(new RrdTarget($this->file), $command);
                self::fail('Ran ' . json_encode($command->arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(self::T, $this->root->lastUpdate($this->rrdtool, $this->file)[0]);
    }

    /** @param list<string> $arguments */
    private function update(array $arguments): void
    {
        (new RrdtoolUpdater(new RrdtoolProcess($this->rrdtool)))->update(new RrdTarget($this->file), new RrdCommand('update', $arguments));
    }

    /** @param list<string> $arguments */
    private function failure(array $arguments): StorageRejected
    {
        try {
            $this->update($arguments);
        } catch (StorageRejected $e) {
            return $e;
        }
        self::fail('The update succeeded.');
    }
}
