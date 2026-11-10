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
 * week that has a recorded hourly mean is one sample. With fewer than
 * $minSamples samples the verdict is unknown. Otherwise the band is
 * mean +/- $deviations x deviation, where deviation is the samples' standard
 * deviation (n - 1) raised to $deviationFloor, an absolute amount in the
 * series' own unit, so a series that never varied still has a band of
 * width 2 x $deviations x $deviationFloor.
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
    /** Weeks of hourly means a band reads, and so the history kept. */
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

    /**
     * The band from the previous weeks' hourly means for this hour of the
     * week, or null with fewer than $minSamples finite ones.
     *
     * @param list<float> $hourlyMeans
     */
    public function band(array $hourlyMeans): ?BaselineBand
    {
        $samples = array_values(array_filter($hourlyMeans, is_finite(...)));
        $count = count($samples);
        if ($count < $this->minSamples) {
            return null;
        }
        $mean = array_sum($samples) / $count;
        $squares = 0.0;
        foreach ($samples as $sample) {
            $squares += ($sample - $mean) ** 2;
        }
        $deviation = sqrt($squares / ($count - 1));

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
