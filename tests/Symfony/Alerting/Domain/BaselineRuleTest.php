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
use Kadupul\Alerting\Domain\Rule\BaselineDirection;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\InvalidRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BaselineRuleTest extends TestCase
{
    /** Four weeks of the same hour of the week: mean 100, sample standard deviation 10. */
    private const array FOUR_WEEKS = [90.0, 110.0, 90.0, 110.0];

    public function test_baseline_unknown_until_min_samples(): void
    {
        $rule = new BaselineRule(3.0, 3.0, 1.0);
        self::assertSame(4, $rule->minSamples, 'The default is four weeks of hour-of-week points.');
        self::assertSame(BaselineRule::DEFAULT_MIN_SAMPLES, $rule->minSamples);

        // Three weeks of history is not enough, however far the rate is from them.
        $three = [100.0, 100.0, 100.0];
        self::assertNull($rule->band($three));
        self::assertSame(Verdict::Unknown, $rule->evaluate(1_000_000.0, $rule->band($three), false));
        self::assertSame(Verdict::Unknown, $rule->evaluate(100.0, $rule->band($three), true));
        self::assertSame(Verdict::Unknown, $rule->evaluate(100.0, $rule->band([]), false));

        // A non-finite stored mean is not a sample.
        self::assertNull($rule->band([...$three, \NAN]));
        self::assertNull($rule->band([...$three, \INF]));

        // The fourth week makes the band usable.
        self::assertNotNull($rule->band([...$three, 100.0]));
        self::assertSame(Verdict::Breach, $rule->evaluate(1_000_000.0, $rule->band([...$three, 100.0]), false));

        // A rule may ask for more weeks, up to the history kept.
        $eight = new BaselineRule(3.0, 3.0, 1.0, minSamples: BaselineRule::HISTORY_WEEKS);
        self::assertNull($eight->band(array_fill(0, 7, 100.0)));
        self::assertNotNull($eight->band(array_fill(0, 8, 100.0)));

        // A missing rate is unknown even with a band.
        self::assertSame(Verdict::Unknown, $rule->evaluate(null, $rule->band(self::FOUR_WEEKS), false));
        self::assertSame(Verdict::Unknown, $rule->evaluate(\NAN, $rule->band(self::FOUR_WEEKS), false));
    }

    public function test_band_is_mean_plus_k_stddev_and_clamps_zero_variance(): void
    {
        $rule = new BaselineRule(3.0, 2.0, 0.5);

        $band = $rule->band(self::FOUR_WEEKS);
        self::assertNotNull($band);
        self::assertEqualsWithDelta(100.0, $band->mean, 1e-9);
        // Sample standard deviation (n - 1): sqrt(4 * 100 / 3).
        self::assertEqualsWithDelta(sqrt(400.0 / 3.0), $band->deviation, 1e-9);
        self::assertEqualsWithDelta(100.0 + 3.0 * sqrt(400.0 / 3.0), $band->upper(3.0), 1e-9);
        self::assertEqualsWithDelta(100.0 - 3.0 * sqrt(400.0 / 3.0), $band->lower(3.0), 1e-9);

        // A constant series has no variance: the floor keeps the band open.
        $flat = $rule->band([100.0, 100.0, 100.0, 100.0]);
        self::assertNotNull($flat);
        self::assertSame(100.0, $flat->mean);
        self::assertSame(0.5, $flat->deviation);
        self::assertSame(98.5, $flat->lower(3.0));
        self::assertSame(101.5, $flat->upper(3.0));
        self::assertSame(Verdict::Ok, $rule->evaluate(101.4, $flat, false), 'Within k times the floor is ok.');
        self::assertSame(Verdict::Ok, $rule->evaluate(101.5, $flat, false), 'The edge itself is inside.');
        self::assertSame(Verdict::Breach, $rule->evaluate(101.6, $flat, false));

        // A constant zero, such as an error counter, is the same: not every non-zero rate breaches by itself.
        $zero = $rule->band([0.0, 0.0, 0.0, 0.0]);
        self::assertNotNull($zero);
        self::assertSame(1.5, $zero->upper(3.0));
        self::assertSame(Verdict::Ok, $rule->evaluate(1.0, $zero, false));
        self::assertSame(Verdict::Breach, $rule->evaluate(2.0, $zero, false));

        // A tiny but non-zero deviation is also raised to the floor.
        $nearlyFlat = $rule->band([100.0, 100.0, 100.0, 100.000001]);
        self::assertNotNull($nearlyFlat);
        self::assertSame(0.5, $nearlyFlat->deviation);

        // A larger deviation is kept as measured.
        self::assertGreaterThan(0.5, $band->deviation);
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

        self::assertSame($expected, $rule->evaluate($value, $rule->band(self::FOUR_WEEKS), false));
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
        $band = $rule->band([100.0, 100.0, 100.0, 100.0]);
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
        $band = $rule->band([100.0, 100.0, 100.0, 100.0]);
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
        $band = $rule->band([100.0, 100.0, 100.0, 100.0]);
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
        $band = $rule->band([100.0, 100.0]);
        self::assertNotNull($band);

        self::assertSame(Verdict::Hold, $rule->evaluate(102.0, $band, true));
        self::assertSame(Verdict::Ok, $rule->evaluate(101.9, $band, true));
        self::assertSame(Transition::None, (new RuleState())->advanceAt(Verdict::Ok, $rule, 1));
    }
}
