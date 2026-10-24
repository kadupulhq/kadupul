<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Telemetry\Contract\RejectionReason;
use Kadupul\Telemetry\Contract\SampleOutcome;
use Kadupul\Telemetry\Contract\SampleSink;
use PHPUnit\Framework\TestCase;

final class RecordingSampleSinkTest extends TestCase
{
    public function testRecordsBatchesAndAcceptsEverythingByDefault(): void
    {
        $sink = new RecordingSampleSink();
        $a = SampleTest::sample();
        $b = SampleTest::sample(resource: new \Kadupul\Telemetry\Contract\ResourceId('interface', 'eth1'));

        $report = $sink->accept([$a, $b]);

        self::assertInstanceOf(SampleSink::class, $sink);
        self::assertTrue($report->allAccepted());
        self::assertCount(2, $report);
        self::assertSame([[$a, $b]], $sink->batches);
        self::assertSame([$a, $b], $sink->samples());
    }

    public function testScriptedRejectionsApplyByPositionInTheBatch(): void
    {
        $sink = new RecordingSampleSink([1 => SampleOutcome::rejected(RejectionReason::WritesDisabled, 'writes are disabled')]);

        $report = $sink->accept([SampleTest::sample(), SampleTest::sample()]);

        self::assertSame([1], array_keys($report->rejected()));
        self::assertSame(RejectionReason::WritesDisabled, $report->outcomes()[1]->reason);
    }

    public function testEmptyBatchIsRecordedAndReportedEmpty(): void
    {
        $sink = new RecordingSampleSink();

        self::assertCount(0, $sink->accept([]));
        self::assertSame([[]], $sink->batches);
    }
}
