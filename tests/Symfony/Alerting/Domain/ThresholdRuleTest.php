<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Domain;

use Kadupul\Alerting\Domain\Evaluation\Verdict;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\InvalidRule;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThresholdRuleTest extends TestCase
{
    /** @return iterable<string, array{0: Comparator, 1: float, 2: float, 3: float, 4: bool, 5: Verdict, 6: Verdict}> */
    public static function boundaries(): iterable
    {
        // [comparator, trigger, clear, value, open, verdict when closed, verdict when open]
        yield 'gt at trigger does not breach' => [Comparator::GreaterThan, 90.0, 80.0, 90.0, false, Verdict::Ok, Verdict::Hold];
        yield 'ge at trigger breaches' => [Comparator::GreaterOrEqual, 90.0, 80.0, 90.0, false, Verdict::Breach, Verdict::Breach];
        yield 'lt at trigger does not breach' => [Comparator::LessThan, 10.0, 20.0, 10.0, false, Verdict::Ok, Verdict::Hold];
        yield 'le at trigger breaches' => [Comparator::LessOrEqual, 10.0, 20.0, 10.0, false, Verdict::Breach, Verdict::Breach];
        yield 'gt past trigger' => [Comparator::GreaterThan, 90.0, 80.0, 90.5, false, Verdict::Breach, Verdict::Breach];
        yield 'lt past trigger' => [Comparator::LessThan, 10.0, 20.0, 9.5, false, Verdict::Breach, Verdict::Breach];
    }

    #[DataProvider('boundaries')]
    public function testTriggerSideUsesTheComparatorExactly(Comparator $c, float $trigger, float $clear, float $value, bool $open, Verdict $closedVerdict, Verdict $openVerdict): void
    {
        $rule = new ThresholdRule($c, $trigger, $clear, 1);

        self::assertSame($closedVerdict, $rule->evaluate($value, false));
        self::assertSame($openVerdict, $rule->evaluate($value, true));
    }

    public function testClearSideIsStrict(): void
    {
        $gt = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1);
        self::assertSame(Verdict::Hold, $gt->evaluate(80.0, true), 'value == clear holds an open incident');
        self::assertSame(Verdict::Ok, $gt->evaluate(79.9, true));

        $lt = new ThresholdRule(Comparator::LessThan, 10.0, 20.0, 1);
        self::assertSame(Verdict::Hold, $lt->evaluate(20.0, true));
        self::assertSame(Verdict::Ok, $lt->evaluate(20.1, true));
    }

    public function testBetweenThresholdsIsOkWhenNoIncidentIsOpen(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1);

        self::assertSame(Verdict::Ok, $rule->evaluate(85.0, false));
        self::assertSame(Verdict::Hold, $rule->evaluate(85.0, true));
    }

    public function testMissingAndNonFiniteValuesAreUnknown(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1);

        foreach ([null, NAN, INF, -INF] as $value) {
            self::assertSame(Verdict::Unknown, $rule->evaluate($value), var_export($value, true));
            self::assertSame(Verdict::Unknown, $rule->evaluate($value, true));
        }
    }

    public function testClearMayEqualTrigger(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterOrEqual, 90.0, 90.0, 1);

        self::assertSame(Verdict::Breach, $rule->evaluate(90.0, true));
        self::assertSame(Verdict::Ok, $rule->evaluate(89.0, true));
    }

    /** @return iterable<string, array{0: Comparator, 1: float, 2: float, 3: int}> */
    public static function invalidRules(): iterable
    {
        yield 'gt clear above trigger' => [Comparator::GreaterThan, 90.0, 95.0, 1];
        yield 'ge clear above trigger' => [Comparator::GreaterOrEqual, 90.0, 90.5, 1];
        yield 'lt clear below trigger' => [Comparator::LessThan, 10.0, 5.0, 1];
        yield 'le clear below trigger' => [Comparator::LessOrEqual, 10.0, 9.0, 1];
        yield 'zero for_cycles' => [Comparator::GreaterThan, 90.0, 80.0, 0];
        yield 'negative for_cycles' => [Comparator::GreaterThan, 90.0, 80.0, -1];
        yield 'NaN trigger' => [Comparator::GreaterThan, NAN, 80.0, 1];
        yield 'INF clear' => [Comparator::GreaterThan, 90.0, -INF, 1];
    }

    #[DataProvider('invalidRules')]
    public function testConstructorRejectsClearBeyondTriggerAndBadCycles(Comparator $c, float $trigger, float $clear, int $cycles): void
    {
        $this->expectException(InvalidRule::class);

        new ThresholdRule($c, $trigger, $clear, $cycles);
    }

    public function testComparatorsParseFromTheirStoredNames(): void
    {
        self::assertSame(Comparator::GreaterThan, Comparator::from('gt'));
        self::assertSame(Comparator::GreaterOrEqual, Comparator::from('ge'));
        self::assertSame(Comparator::LessThan, Comparator::from('lt'));
        self::assertSame(Comparator::LessOrEqual, Comparator::from('le'));
    }
}
