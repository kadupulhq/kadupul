<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Domain;

use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Evaluation\Transition;
use Kadupul\Alerting\Domain\Evaluation\Verdict;
use Kadupul\Alerting\Domain\Rule\BaselineBand;
use Kadupul\Alerting\Domain\Rule\BaselineDirection;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\HourBucket;
use Kadupul\Alerting\Domain\Rule\InvalidRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BaselineRuleTest extends TestCase
{
    /** Four weeks of the same hour of the week, one observation each: mean 100, variance 400 / 3. */
    private const array FOUR_WEEKS = [90.0, 110.0, 90.0, 110.0];

    /**
     * One bucket per week: a float is a week with that one observation, a
     * list is a week with those observations.
     *
     * @param list<float|list<float>> $weeks
     *
     * @return list<HourBucket>
     */
    private static function weeks(array $weeks): array
    {
        return array_map(static function (float|array $week): HourBucket {
            $values = is_array($week) ? $week : [$week];

            return new HourBucket(count($values), array_sum($values), array_sum(array_map(static fn(float $v): float => $v * $v, $values)));
        }, $weeks);
    }

    public function test_baseline_unknown_until_min_samples(): void
    {
        $rule = new BaselineRule(3.0, 3.0, 1.0);
        self::assertSame(4, $rule->minSamples, 'The default is four weeks of hour-of-week points.');
        self::assertSame(BaselineRule::DEFAULT_MIN_SAMPLES, $rule->minSamples);

        // Three weeks of history is not enough, however far the rate is from them.
        $three = [100.0, 100.0, 100.0];
        self::assertNull($rule->band(self::weeks($three)));
        self::assertSame(Verdict::Unknown, $rule->evaluate(1_000_000.0, $rule->band(self::weeks($three)), false));
        self::assertSame(Verdict::Unknown, $rule->evaluate(100.0, $rule->band(self::weeks($three)), true));
        self::assertSame(Verdict::Unknown, $rule->evaluate(100.0, $rule->band(self::weeks([])), false));

        // A non-finite stored mean is not a sample.
        self::assertNull($rule->band(self::weeks([...$three, \NAN])));
        self::assertNull($rule->band(self::weeks([...$three, \INF])));

        // The fourth week makes the band usable.
        self::assertNotNull($rule->band(self::weeks([...$three, 100.0])));
        self::assertSame(Verdict::Breach, $rule->evaluate(1_000_000.0, $rule->band(self::weeks([...$three, 100.0])), false));

        // A rule may ask for more weeks, up to the history kept.
        $eight = new BaselineRule(3.0, 3.0, 1.0, minSamples: BaselineRule::HISTORY_WEEKS);
        self::assertNull($eight->band(self::weeks(array_fill(0, 7, 100.0))));
        self::assertNotNull($eight->band(self::weeks(array_fill(0, 8, 100.0))));

        // A missing rate is unknown even with a band.
        self::assertSame(Verdict::Unknown, $rule->evaluate(null, $rule->band(self::weeks(self::FOUR_WEEKS)), false));
        self::assertSame(Verdict::Unknown, $rule->evaluate(\NAN, $rule->band(self::weeks(self::FOUR_WEEKS)), false));
    }

    public function test_band_is_mean_plus_k_stddev_and_clamps_zero_variance(): void
    {
        $rule = new BaselineRule(3.0, 2.0, 0.5);

        // Pooled over every observation: n = 4, sum = 400, sum of squares = 40400,
        // so mean = 100 and variance = (40400 - 400^2 / 4) / 3 = 400 / 3.
        $band = $rule->band(self::weeks(self::FOUR_WEEKS));
        self::assertNotNull($band);
        self::assertSame(100.0, $band->mean);
        self::assertSame(sqrt(400.0 / 3.0), $band->deviation);
        self::assertSame(100.0 + 3.0 * sqrt(400.0 / 3.0), $band->upper(3.0));
        self::assertSame(100.0 - 3.0 * sqrt(400.0 / 3.0), $band->lower(3.0));

        // Weeks of different sizes weigh by their observations: n = 6, sum = 600,
        // sum of squares = 2 x 8100 + 2 x 12100 + 2 x 10000 = 60400, variance = 400 / 5 = 80.
        $uneven = $rule->band(self::weeks([[90.0, 110.0], [90.0, 110.0], [100.0], [100.0]]));
        self::assertNotNull($uneven);
        self::assertSame(100.0, $uneven->mean);
        self::assertSame(sqrt(80.0), $uneven->deviation);

        // A constant series has no variance: the floor keeps the band open.
        $flat = $rule->band(self::weeks([100.0, 100.0, 100.0, 100.0]));
        self::assertNotNull($flat);
        self::assertSame(100.0, $flat->mean);
        self::assertSame(0.5, $flat->deviation);
        self::assertSame(98.5, $flat->lower(3.0));
        self::assertSame(101.5, $flat->upper(3.0));
        self::assertSame(Verdict::Ok, $rule->evaluate(101.4, $flat, false), 'Within k times the floor is ok.');
        self::assertSame(Verdict::Ok, $rule->evaluate(101.5, $flat, false), 'The edge itself is inside.');
        self::assertSame(Verdict::Breach, $rule->evaluate(101.6, $flat, false));

        // A constant zero, such as an error counter, is the same: not every non-zero rate breaches by itself.
        $zero = $rule->band(self::weeks([0.0, 0.0, 0.0, 0.0]));
        self::assertNotNull($zero);
        self::assertSame(1.5, $zero->upper(3.0));
        self::assertSame(Verdict::Ok, $rule->evaluate(1.0, $zero, false));
        self::assertSame(Verdict::Breach, $rule->evaluate(2.0, $zero, false));

        // A tiny but non-zero deviation is also raised to the floor.
        $nearlyFlat = $rule->band(self::weeks([100.0, 100.0, 100.0, 100.000001]));
        self::assertNotNull($nearlyFlat);
        self::assertSame(0.5, $nearlyFlat->deviation);
    }

    public function testTheBandDescribesSingleObservationsNotHourlyMeans(): void
    {
        $rule = new BaselineRule(3.0, 3.0, 0.5);
        // Every week's hourly mean is exactly 100, but within each hour the rate swings 80 to 120.
        $bursty = self::weeks(array_fill(0, 4, [80.0, 120.0, 80.0, 120.0]));

        $band = $rule->band($bursty);

        self::assertNotNull($band);
        self::assertSame(100.0, $band->mean);
        // n = 16, sum of squares = 8 x 6400 + 8 x 14400 = 166400: variance = (166400 - 160000) / 15.
        self::assertSame(sqrt(6400.0 / 15.0), $band->deviation, 'Week-to-week spread of the means would be 0, leaving only the floor.');
        self::assertSame(Verdict::Ok, $rule->evaluate(120.0, $band, false), 'An ordinary burst stays inside.');
        self::assertSame(Verdict::Breach, $rule->evaluate(170.0, $band, false));
    }

    public function testFloatCancellationOnALargeNearlyConstantSeriesClampsToTheFloor(): void
    {
        $rule = new BaselineRule(3.0, 3.0, 0.25);
        // Twelve observations a week around 987654321.5, differing by 1e-7.
        $week = [];
        for ($i = 0; $i < 12; ++$i) {
            $week[] = 987654321.5 + ($i % 2) * 1e-7;
        }
        $buckets = self::weeks(array_fill(0, 4, $week));
        $n = 48;
        $sum = array_sum(array_map(static fn(HourBucket $b): float => $b->sum, $buckets));
        $squares = array_sum(array_map(static fn(HourBucket $b): float => $b->sumSquares, $buckets));
        self::assertLessThan(0.0, ($squares - $sum * $sum / $n) / ($n - 1), 'The textbook formula cancels to a negative variance here.');

        $band = $rule->band($buckets);

        self::assertNotNull($band);
        self::assertEqualsWithDelta(987654321.5, $band->mean, 1e-6);
        self::assertSame(0.25, $band->deviation, 'A negative variance is 0, and the floor applies.');
        self::assertNull($rule->band(self::weeks(array_fill(0, 4, [\INF]))), 'A non-finite week is not a sample.');
    }

    public function testWeeksWhosePooledSumOverflowsGiveNoBand(): void
    {
        $rule = new BaselineRule(3.0, 3.0, 1.0);
        // Each week is finite and usable; their sum is not.
        $week = new HourBucket(1, 1.0e308, 1.0e308);
        self::assertTrue($week->isUsable());

        self::assertNull($rule->band(array_fill(0, 4, $week)));
        self::assertSame(Verdict::Unknown, $rule->evaluate(1.0e308, $rule->band(array_fill(0, 4, $week)), false));
    }

    /** @return iterable<string, array{float, float}> */
    public static function bandsWithoutWidth(): iterable
    {
        yield 'zero deviation' => [100.0, 0.0];
        yield 'negative deviation' => [100.0, -1.0];
        yield 'infinite deviation' => [100.0, \INF];
        yield 'not-a-number mean' => [\NAN, 1.0];
        yield 'infinite mean' => [-\INF, 1.0];
    }

    #[DataProvider('bandsWithoutWidth')]
    public function testABandNeedsAFiniteMeanAndAPositiveFiniteDeviation(float $mean, float $deviation): void
    {
        $this->expectException(InvalidRule::class);
        $this->expectExceptionMessage('A baseline band needs a finite mean and a finite deviation greater than zero.');

        new BaselineBand($mean, $deviation);
    }

    public function testMinimumSamplesCountWeeksNotObservations(): void
    {
        $rule = new BaselineRule(3.0, 3.0, 1.0);

        // Three weeks of 12 observations each is still three weeks.
        self::assertNull($rule->band(self::weeks(array_fill(0, 3, array_fill(0, 12, 100.0)))));
        // An empty bucket is no week.
        self::assertNull($rule->band([...self::weeks([100.0, 100.0, 100.0]), new HourBucket(0, 0.0, 0.0)]));
        // n must reach 2 too, which any two usable weeks give.
        self::assertNotNull((new BaselineRule(3.0, 3.0, 1.0, minSamples: 2))->band(self::weeks([100.0, 100.0])));
    }

    /** @return iterable<string, array{BaselineDirection, float, Verdict}> */
    public static function directions(): iterable
    {
        // Band from FOUR_WEEKS: mean 100, deviation about 11.547, k = 2: about 76.9 to 123.1.
        yield 'both: far above' => [BaselineDirection::Both, 130.0, Verdict::Breach];
        yield 'both: far below' => [BaselineDirection::Both, 70.0, Verdict::Breach];
        yield 'both: inside' => [BaselineDirection::Both, 100.0, Verdict::Ok];
        yield 'above: far above' => [BaselineDirection::Above, 130.0, Verdict::Breach];
        yield 'above: far below is ok' => [BaselineDirection::Above, 70.0, Verdict::Ok];
        yield 'above: zero is ok' => [BaselineDirection::Above, 0.0, Verdict::Ok];
        yield 'below: far below' => [BaselineDirection::Below, 70.0, Verdict::Breach];
        yield 'below: far above is ok' => [BaselineDirection::Below, 130.0, Verdict::Ok];
    }

    #[DataProvider('directions')]
    public function testDirectionChoosesTheBreachingSides(BaselineDirection $direction, float $value, Verdict $expected): void
    {
        $rule = new BaselineRule(2.0, 2.0, 0.5, direction: $direction);

        self::assertSame($expected, $rule->evaluate($value, $rule->band(self::weeks(self::FOUR_WEEKS)), false));
    }

    public function testTwoSidedIsTheDefault(): void
    {
        self::assertSame(BaselineDirection::Both, (new BaselineRule(2.0, 2.0, 0.5))->direction);
        self::assertSame(BaselineDirection::Both, BaselineRule::DEFAULT_DIRECTION);
        self::assertSame(['above', 'below', 'both'], array_map(static fn(BaselineDirection $d): string => $d->value, BaselineDirection::cases()));
    }

    public function testAnOpenIncidentClearsOnlyInsideTheClearBand(): void
    {
        // Trigger at 3 deviations, clear at 1: with the floor of 1 on a flat 100, breach above 103, clear below 101.
        $rule = new BaselineRule(3.0, 1.0, 1.0);
        $band = $rule->band(self::weeks([100.0, 100.0, 100.0, 100.0]));
        self::assertNotNull($band);

        self::assertSame(Verdict::Breach, $rule->evaluate(103.5, $band, true));
        self::assertSame(Verdict::Hold, $rule->evaluate(102.0, $band, true), 'Between the bands an open incident holds.');
        self::assertSame(Verdict::Hold, $rule->evaluate(101.0, $band, true), 'The clear edge itself holds.');
        self::assertSame(Verdict::Ok, $rule->evaluate(100.5, $band, true));
        self::assertSame(Verdict::Hold, $rule->evaluate(98.0, $band, true), 'Two-sided: the lower clear edge holds too.');
        self::assertSame(Verdict::Ok, $rule->evaluate(102.0, $band, false), 'With no incident, anything inside the trigger band is ok.');

        // One-sided rules clear on their own side only.
        $above = new BaselineRule(3.0, 1.0, 1.0, direction: BaselineDirection::Above);
        self::assertSame(Verdict::Ok, $above->evaluate(50.0, $band, true));
        self::assertSame(Verdict::Hold, $above->evaluate(102.0, $band, true));
        $below = new BaselineRule(3.0, 1.0, 1.0, direction: BaselineDirection::Below);
        self::assertSame(Verdict::Ok, $below->evaluate(150.0, $band, true));
        self::assertSame(Verdict::Hold, $below->evaluate(98.0, $band, true));
    }

    public function testHysteresisAndForCyclesUseTheSharedStateMachine(): void
    {
        // A rate oscillating between 102 and 104 around the 103 trigger edge opens one incident and never recovers.
        $rule = new BaselineRule(3.0, 1.0, 1.0, forCycles: 2);
        $band = $rule->band(self::weeks([100.0, 100.0, 100.0, 100.0]));
        self::assertNotNull($band);
        $state = new RuleState();
        $transitions = [];
        foreach ([104.0, 102.0, 104.0, 104.0, 102.0, 104.0, 102.0, 100.0, 100.0] as $i => $value) {
            $transitions[] = $state->advanceAt($rule->evaluate($value, $band, $state->isOpen()), $rule, 1000 + $i)->name;
        }

        self::assertSame(['None', 'None', 'None', 'Opened', 'None', 'None', 'None', 'None', 'Recovered'], $transitions);
    }

    public function testTheDecidingEdgeIsTheSideTheRateIsOn(): void
    {
        $rule = new BaselineRule(3.0, 1.0, 1.0);
        $band = $rule->band(self::weeks([100.0, 100.0, 100.0, 100.0]));
        self::assertNotNull($band);

        self::assertSame(103.0, $rule->triggerEdge(110.0, $band));
        self::assertSame(97.0, $rule->triggerEdge(90.0, $band));
        self::assertSame(101.0, $rule->clearEdge(100.5, $band));
        self::assertSame(99.0, $rule->clearEdge(99.5, $band));
        $above = new BaselineRule(3.0, 1.0, 1.0, direction: BaselineDirection::Above);
        self::assertSame(103.0, $above->triggerEdge(50.0, $band));
        self::assertSame(101.0, $above->clearEdge(50.0, $band));
        $below = new BaselineRule(3.0, 1.0, 1.0, direction: BaselineDirection::Below);
        self::assertSame(97.0, $below->triggerEdge(150.0, $band));
        self::assertSame(99.0, $below->clearEdge(150.0, $band));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRules(): iterable
    {
        yield 'zero multiplier' => [['deviations' => 0.0]];
        yield 'negative multiplier' => [['deviations' => -1.0]];
        yield 'infinite multiplier' => [['deviations' => \INF]];
        yield 'clear wider than trigger' => [['clearDeviations' => 3.5]];
        yield 'zero clear' => [['clearDeviations' => 0.0]];
        yield 'NaN clear' => [['clearDeviations' => \NAN]];
        yield 'zero floor' => [['deviationFloor' => 0.0]];
        yield 'negative floor' => [['deviationFloor' => -0.1]];
        yield 'infinite floor' => [['deviationFloor' => \INF]];
        yield 'one sample' => [['minSamples' => 1]];
        yield 'more samples than history' => [['minSamples' => BaselineRule::HISTORY_WEEKS + 1]];
        yield 'zero for_cycles' => [['forCycles' => 0]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidRules')]
    public function testARuleTheFormCannotWriteIsRefused(array $override): void
    {
        $arguments = ['deviations' => 3.0, 'clearDeviations' => 3.0, 'deviationFloor' => 1.0, ...$override];

        $this->expectException(InvalidRule::class);
        new BaselineRule(...$arguments);
    }

    public function testTheClearMultiplierMayEqualTheTrigger(): void
    {
        // No gap: like a threshold rule whose clear threshold equals its trigger.
        $rule = new BaselineRule(2.0, 2.0, 1.0, minSamples: 2);
        $band = $rule->band(self::weeks([100.0, 100.0]));
        self::assertNotNull($band);

        self::assertSame(Verdict::Hold, $rule->evaluate(102.0, $band, true));
        self::assertSame(Verdict::Ok, $rule->evaluate(101.9, $band, true));
        self::assertSame(Transition::None, (new RuleState())->advanceAt(Verdict::Ok, $rule, 1));
    }
}
