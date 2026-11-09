<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Domain;

use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Domain\Incident\InvalidIncident;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IncidentTest extends TestCase
{
    public function testOnlyOpenAndAcknowledgedIncidentsAreActive(): void
    {
        self::assertTrue((new Incident(1, 7, IncidentStatus::Open, 100))->isActive());
        self::assertTrue((new Incident(1, 7, IncidentStatus::Acknowledged, 100, acknowledgedAt: 110, acknowledgedBy: 3))->isActive());
        self::assertFalse((new Incident(1, 7, IncidentStatus::Closed, 100, endedAt: 200))->isActive());
        self::assertFalse((new Incident(1, 7, IncidentStatus::Orphaned, 100, endedAt: 200))->isActive());
        self::assertSame(['open', 'acknowledged', 'closed', 'orphaned'], array_map(static fn(IncidentStatus $s): string => $s->value, IncidentStatus::cases()));
    }

    /** @return iterable<string, array{int, int, IncidentStatus, int, ?int, ?int, ?int}> */
    public static function impossibleIncidents(): iterable
    {
        yield 'no id' => [0, 7, IncidentStatus::Open, 100, null, null, null];
        yield 'no rule' => [1, 0, IncidentStatus::Open, 100, null, null, null];
        yield 'negative open time' => [1, 7, IncidentStatus::Open, -1, null, null, null];
        yield 'open with an end' => [1, 7, IncidentStatus::Open, 100, 200, null, null];
        yield 'open with an acknowledgement' => [1, 7, IncidentStatus::Open, 100, null, 110, 3];
        yield 'acknowledged by nobody' => [1, 7, IncidentStatus::Acknowledged, 100, null, 110, null];
        yield 'acknowledged at no time' => [1, 7, IncidentStatus::Acknowledged, 100, null, null, 3];
        yield 'acknowledged and ended' => [1, 7, IncidentStatus::Acknowledged, 100, 200, 110, 3];
        yield 'closed with no end' => [1, 7, IncidentStatus::Closed, 100, null, null, null];
        yield 'orphaned with no end' => [1, 7, IncidentStatus::Orphaned, 100, null, null, null];
        yield 'half an acknowledgement' => [1, 7, IncidentStatus::Closed, 100, 200, 110, null];
    }

    #[DataProvider('impossibleIncidents')]
    public function testRefusesAnIncidentTheLifecycleCannotReach(int $id, int $ruleId, IncidentStatus $status, int $openedAt, ?int $endedAt, ?int $acknowledgedAt, ?int $acknowledgedBy): void
    {
        $this->expectException(InvalidIncident::class);
        new Incident($id, $ruleId, $status, $openedAt, $endedAt, $acknowledgedAt, $acknowledgedBy);
    }

    public function testAClosedIncidentKeepsItsAcknowledgement(): void
    {
        $incident = new Incident(1, 7, IncidentStatus::Closed, 100, 200, 110, 3);

        self::assertSame([110, 3, 200], [$incident->acknowledgedAt, $incident->acknowledgedBy, $incident->endedAt]);
    }
}
