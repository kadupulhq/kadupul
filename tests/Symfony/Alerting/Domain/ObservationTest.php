<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Domain;

use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\InvalidRule;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use PHPUnit\Framework\TestCase;

final class ObservationTest extends TestCase
{
    public function testFreshForTwoPollingIntervalsOfItsDataSource(): void
    {
        $observation = new Observation(1.5, 1000, 60);

        self::assertTrue($observation->isFreshAt(1000));
        self::assertTrue($observation->isFreshAt(1120));
        self::assertFalse($observation->isFreshAt(1121));
    }

    public function testRefusesUnusableValues(): void
    {
        foreach ([[NAN, 1, 60], [INF, 1, 60], [1.0, 1, 0], [1.0, -1, 60]] as [$rate, $at, $step]) {
            try {
                new Observation($rate, $at, $step);
                self::fail('Accepted an unusable observation.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSeriesKeyMatchesTheDsstatsKey(): void
    {
        self::assertSame('42:traffic_in', (new SeriesKey(42, 'traffic_in'))->id());

        foreach ([[0, 'a'], [1, ''], [1, str_repeat('a', 20)]] as [$id, $name]) {
            try {
                new SeriesKey($id, $name);
                self::fail('Accepted series ' . $id . ':' . $name);
            } catch (InvalidRule) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRuleIdsArePositive(): void
    {
        $this->expectException(InvalidRule::class);
        new AlertRule(0, new SeriesKey(1, 'a'), new ThresholdRule(Comparator::GreaterThan, 1.0, 1.0, 1));
    }
}
