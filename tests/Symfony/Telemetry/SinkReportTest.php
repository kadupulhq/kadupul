<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Telemetry\Contract\InvalidTelemetryValue;
use Kadupul\Telemetry\Contract\RejectionReason;
use Kadupul\Telemetry\Contract\SampleOutcome;
use Kadupul\Telemetry\Contract\SinkReport;
use PHPUnit\Framework\TestCase;

final class SinkReportTest extends TestCase
{
    public function testEmptyInputGivesAnEmptyReport(): void
    {
        $report = SinkReport::forOutcomes([]);

        self::assertCount(0, $report);
        self::assertTrue($report->allAccepted());
        self::assertSame([], $report->rejected());
        self::assertSame([], $report->outcomes());
    }

    public function testOutcomesKeepInputOrderAndCount(): void
    {
        $outcomes = [
            SampleOutcome::accepted(),
            SampleOutcome::rejected(RejectionReason::NoBinding, 'no binding for interface.in_octets'),
            SampleOutcome::accepted(),
            SampleOutcome::rejected(RejectionReason::OutOfOrder, 'older than the last update'),
        ];

        $report = SinkReport::forOutcomes($outcomes);

        self::assertCount(4, $report);
        self::assertSame($outcomes, $report->outcomes());
        self::assertFalse($report->allAccepted());
        self::assertSame([1 => $outcomes[1], 3 => $outcomes[3]], $report->rejected());
    }

    public function testAllAcceptedWhenNothingIsRejected(): void
    {
        self::assertTrue(SinkReport::forOutcomes([SampleOutcome::accepted(), SampleOutcome::accepted()])->allAccepted());
    }

    public function testReasonCodesAreStable(): void
    {
        self::assertSame(
            ['NoBinding', 'AmbiguousBinding', 'WritesDisabled', 'OwnershipConflict', 'NotOwnedByModelDriven', 'TargetMissing', 'TypeMismatch', 'InvalidTimestamp', 'InvalidValue', 'DuplicateSample', 'OutOfOrder', 'StorageFailure'],
            array_map(static fn(RejectionReason $r): string => $r->name, RejectionReason::cases()),
        );
    }

    public function testAcceptedOutcomeHasNoReason(): void
    {
        $outcome = SampleOutcome::accepted();

        self::assertTrue($outcome->isAccepted());
        self::assertNull($outcome->reason);
        self::assertSame('', $outcome->detail);
    }

    public function testRejectionNeedsADetail(): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        SampleOutcome::rejected(RejectionReason::StorageFailure, '');
    }

    public function testDetailMustNotLeakPathsOrSql(): void
    {
        foreach (['failed writing /var/lib/cacti/rra/x.rrd', 'C:\\rra\\x.rrd', 'SELECT * FROM data_local', 'UPDATE t SET a = 1', 'DELETE FROM t', 'INSERT INTO t VALUES (1)'] as $detail) {
            try {
                SampleOutcome::rejected(RejectionReason::StorageFailure, $detail);
                self::fail('Detail was accepted: ' . $detail);
            } catch (InvalidTelemetryValue) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectedOutcomeCarriesReasonAndDetail(): void
    {
        $outcome = SampleOutcome::rejected(RejectionReason::TypeMismatch, 'gauge sample for a counter data source');

        self::assertFalse($outcome->isAccepted());
        self::assertSame(RejectionReason::TypeMismatch, $outcome->reason);
        self::assertSame('gauge sample for a counter data source', $outcome->detail);
    }
}
