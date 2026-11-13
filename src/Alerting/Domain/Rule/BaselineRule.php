<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

use Kadupul\Alerting\Domain\Evaluation\Verdict;

/**
 * An hour-of-week baseline with hysteresis (ADR 0011 decision 3).
 *
 * The band for an observation comes from the same wall-clock hour of the
 * week in each of the previous HISTORY_WEEKS weeks (BaselineCalendar): each
 * week that has a bucket for that hour is one sample week. With fewer than
 * $minSamples sample weeks the verdict is unknown. Otherwise the band is
 * mean +/- $deviations x deviation over every observation in those buckets
 * pooled, where deviation is their sample standard deviation (n - 1) raised
 * to $deviationFloor, an absolute amount in the series' own unit, so a
 * series that never varied still has a band of width
 * 2 x $deviations x $deviationFloor.
 *
 * Like a threshold rule, the trigger side is strict outward: a rate exactly
 * on the trigger edge is inside. While an incident is open it clears only
 * strictly inside the narrower clear band, mean +/- $clearDeviations x
 * deviation; between the two edges it holds. $direction picks the sides that
 * breach and clear: one-sided rules ignore the other side entirely.
 */
final readonly class BaselineRule
{
    public const int DEFAULT_MIN_SAMPLES = 4;
    public const int LOWEST_MIN_SAMPLES = 2;
    /** Weeks of hour buckets a band reads, and so the history kept. */
    public const int HISTORY_WEEKS = 8;
    public const BaselineDirection DEFAULT_DIRECTION = BaselineDirection::Both;

    public function __construct(
        public float $deviations,
        public float $clearDeviations,
        public float $deviationFloor,
        public int $forCycles = 1,
        public BaselineDirection $direction = self::DEFAULT_DIRECTION,
        public int $minSamples = self::DEFAULT_MIN_SAMPLES,
    ) {
        if (!is_finite($deviations) || $deviations <= 0.0) {
            throw new InvalidRule('The deviation multiplier must be a finite number greater than zero.');
        }
        if (!is_finite($clearDeviations) || $clearDeviations <= 0.0 || $clearDeviations > $deviations) {
            throw new InvalidRule('The clear multiplier must be greater than zero and no larger than the deviation multiplier.');
        }
        if (!is_finite($deviationFloor) || $deviationFloor <= 0.0) {
            throw new InvalidRule('The deviation floor must be a finite number greater than zero.');
        }
        if ($minSamples < self::LOWEST_MIN_SAMPLES || $minSamples > self::HISTORY_WEEKS) {
            throw new InvalidRule('The minimum sample count must be between ' . self::LOWEST_MIN_SAMPLES . ' and ' . self::HISTORY_WEEKS . '.');
        }
        if ($forCycles < 1) {
            throw new InvalidRule('for_cycles must be at least 1.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->deviations === $other->deviations
            && $this->clearDeviations === $other->clearDeviations
            && $this->deviationFloor === $other->deviationFloor
            && $this->forCycles === $other->forCycles
            && $this->direction === $other->direction
            && $this->minSamples === $other->minSamples;
    }

    /**
     * The band from the earlier weeks' buckets for this hour of the week, or
     * null with fewer than $minSamples usable buckets (weeks) or fewer than
     * two observations. Merge centered moments (Chan's parallel variance)
     * rather than subtracting large squared sums, which loses the variance
     * of high-valued series with small fluctuations.
     *
     * @param list<HourBucket> $buckets
     */
    public function band(array $buckets): ?BaselineBand
    {
        $weeks = 0;
        $count = 0;
        $mean = 0.0;
        $m2 = 0.0;
        foreach ($buckets as $bucket) {
            if (!$bucket->isUsable()) {
                continue;
            }
            ++$weeks;
            $total = $count + $bucket->count;
            $delta = $bucket->mean - $mean;
            if ($count === 0) {
                $mean = $bucket->mean;
                $m2 = $bucket->m2;
            } else {
                $weight = $bucket->count / $total;
                $mean += $delta * $weight;
                $m2 += $bucket->m2 + $delta * $delta * ($count * $weight);
            }
            $count = $total;
        }
        if ($weeks < $this->minSamples || $count < 2) {
            return null;
        }
        if (!is_finite($mean) || !is_finite($m2)) {
            return null;
        }
        $deviation = sqrt($m2 / ($count - 1));

        return new BaselineBand($mean, max($deviation, $this->deviationFloor));
    }

    public function evaluate(?float $value, ?BaselineBand $band, bool $incidentOpen = false): Verdict
    {
        if ($value === null || !is_finite($value) || $band === null) {
            return Verdict::Unknown;
        }
        if (($this->direction->watchesAbove() && $value > $band->upper($this->deviations))
            || ($this->direction->watchesBelow() && $value < $band->lower($this->deviations))) {
            return Verdict::Breach;
        }
        if (!$incidentOpen) {
            return Verdict::Ok;
        }
        $clearAbove = !$this->direction->watchesAbove() || $value < $band->upper($this->clearDeviations);
        $clearBelow = !$this->direction->watchesBelow() || $value > $band->lower($this->clearDeviations);

        return $clearAbove && $clearBelow ? Verdict::Ok : Verdict::Hold;
    }

    /** The trigger edge on the rate's side of the mean, as a notification reports it. */
    public function triggerEdge(float $value, BaselineBand $band): float
    {
        return $this->upperSide($value, $band) ? $band->upper($this->deviations) : $band->lower($this->deviations);
    }

    /** The clear edge on the rate's side of the mean. */
    public function clearEdge(float $value, BaselineBand $band): float
    {
        return $this->upperSide($value, $band) ? $band->upper($this->clearDeviations) : $band->lower($this->clearDeviations);
    }

    private function upperSide(float $value, BaselineBand $band): bool
    {
        return match ($this->direction) {
            BaselineDirection::Above => true,
            BaselineDirection::Below => false,
            BaselineDirection::Both => $value >= $band->mean,
        };
    }
}
