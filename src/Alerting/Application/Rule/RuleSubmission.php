<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Rule;

use Kadupul\Alerting\Domain\Rule\BaselineDirection;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\InvalidRule;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;

/**
 * Turns the rule form's text fields into a RuleDefinition. This checks only
 * the grammar of each field and the ranges of its storage column; the
 * relationship between the fields (the clear threshold's side, the clear
 * multiplier's bound, for_cycles >= 1, the sample range) is the domain's,
 * through the ThresholdRule and BaselineRule constructors. Fields of the
 * kind not selected are ignored, as the form hides them.
 */
final class RuleSubmission
{
    /** alert_rule.local_data_id is int(10) unsigned. */
    public const int MAX_DATA_SOURCE_ID = 4294967295;
    /** alert_rule.for_cycles is smallint(5) unsigned. */
    public const int MAX_FOR_CYCLES = 65535;
    public const string INVALID_KIND = 'Choose a threshold or baseline rule.';
    public const string INVALID_DATA_SOURCE = 'Enter the data source ID as a whole number from 1 to 4294967295.';
    public const string INVALID_RRD_NAME = 'Enter the RRD data source name: 1 to 19 letters, digits or underscores.';
    public const string INVALID_FOR_CYCLES = 'Enter the number of cycles as a whole number from 1 to 65535.';
    public const string INVALID_COMPARATOR = 'Choose a comparison.';
    public const string INVALID_DIRECTION = 'Choose the side of the band that breaches.';
    public const string INVALID_NUMBER = 'Enter each threshold and multiplier as a finite decimal number.';
    public const string INVALID_MIN_SAMPLES = 'Enter the minimum sample weeks as a whole number.';

    private const string INTEGER = '/\A[1-9][0-9]{0,9}\z/D';
    private const string DECIMAL = '/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]{1,3})?\z/D';
    /** RRDtool data source names: 1 to 19 of [a-zA-Z0-9_]. */
    private const string RRD_NAME = '/\A[A-Za-z0-9_]{1,19}\z/D';

    /**
     * @param array<string, mixed> $fields the submitted values, each a string or null
     *
     * @throws RuleSubmissionRefused for a field of the wrong grammar
     * @throws InvalidRule           for fields the domain refuses together
     */
    public static function definition(array $fields): RuleDefinition
    {
        $series = new SeriesKey(
            self::integer($fields['local_data_id'] ?? null, self::MAX_DATA_SOURCE_ID, self::INVALID_DATA_SOURCE),
            self::rrdName($fields['rrd_name'] ?? null),
        );
        $forCycles = self::integer($fields['for_cycles'] ?? null, self::MAX_FOR_CYCLES, self::INVALID_FOR_CYCLES);

        return new RuleDefinition($series, match ($fields['kind'] ?? null) {
            RuleDefinition::THRESHOLD => new ThresholdRule(
                self::enum(Comparator::class, $fields['comparator'] ?? null, self::INVALID_COMPARATOR),
                self::decimal($fields['trigger_threshold'] ?? null),
                self::decimal($fields['clear_threshold'] ?? null),
                $forCycles,
            ),
            RuleDefinition::BASELINE => new BaselineRule(
                self::decimal($fields['baseline_deviations'] ?? null),
                self::decimal($fields['baseline_clear_deviations'] ?? null),
                self::decimal($fields['baseline_floor'] ?? null),
                $forCycles,
                // Left blank, the direction and the sample count take the
                // domain's defaults, so a baseline rule always stores both.
                self::blank($fields['baseline_direction'] ?? null)
                    ? BaselineRule::DEFAULT_DIRECTION
                    : self::enum(BaselineDirection::class, $fields['baseline_direction'], self::INVALID_DIRECTION),
                // The domain refuses a count outside its range; the column is tinyint unsigned.
                self::blank($fields['baseline_min_samples'] ?? null)
                    ? BaselineRule::DEFAULT_MIN_SAMPLES
                    : self::integer($fields['baseline_min_samples'], 255, self::INVALID_MIN_SAMPLES),
            ),
            default => throw new RuleSubmissionRefused(self::INVALID_KIND),
        });
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private static function integer(mixed $value, int $max, string $refusal): int
    {
        if (!is_string($value) || preg_match(self::INTEGER, $value) !== 1 || (int) $value > $max) {
            throw new RuleSubmissionRefused($refusal);
        }

        return (int) $value;
    }

    private static function rrdName(mixed $value): string
    {
        if (!is_string($value) || preg_match(self::RRD_NAME, $value) !== 1) {
            throw new RuleSubmissionRefused(self::INVALID_RRD_NAME);
        }

        return $value;
    }

    private static function decimal(mixed $value): float
    {
        if (!is_string($value) || strlen($value) > 64 || preg_match(self::DECIMAL, $value) !== 1 || !is_finite((float) $value)) {
            throw new RuleSubmissionRefused(self::INVALID_NUMBER);
        }

        return (float) $value;
    }

    /**
     * @template T of Comparator|BaselineDirection
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function enum(string $enum, mixed $value, string $refusal): Comparator|BaselineDirection
    {
        $case = is_string($value) ? $enum::tryFrom($value) : null;
        if ($case === null) {
            throw new RuleSubmissionRefused($refusal);
        }

        return $case;
    }
}
