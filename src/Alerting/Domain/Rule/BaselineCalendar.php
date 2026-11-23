<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/**
 * Hours of the week in one named time zone (ADR 0011 decision 3). A wall
 * hour counts the zone's wall-clock hours since 1970-01-01 00:00, so the same
 * hour of an earlier week is exactly 168 wall hours back even when a daylight
 * saving change lies between them, where the real gap is 167 or 169 hours.
 * The hour clocks skip in spring has no instant and so no bucket; both
 * instants of the hour repeated in autumn fall in one bucket.
 */
final readonly class BaselineCalendar
{
    public const int HOURS_PER_WEEK = 168;

    public function __construct(public \DateTimeZone $timeZone) {}

    /** @param int $instant Unix seconds */
    public function wallHour(int $instant): int
    {
        $offset = $this->timeZone->getOffset(new \DateTimeImmutable('@' . $instant));

        return self::floorDiv($instant + $offset, 3600);
    }

    /** 0 is Monday 00:00 to 00:59 and 167 is Sunday 23:00 to 23:59, in the calendar's zone. */
    public function hourOfWeek(int $instant): int
    {
        $hour = $this->wallHour($instant);
        // 1970-01-01 was a Thursday, day 3 of a week that starts on Monday.
        $day = self::floorDiv($hour, 24) + 3;

        return ($day - 7 * self::floorDiv($day, 7)) * 24 + ($hour - 24 * self::floorDiv($hour, 24));
    }

    /**
     * The same wall hour in each kept earlier week, newest first.
     *
     * @return list<int>
     */
    public function priorWeeks(int $wallHour): array
    {
        $hours = [];
        for ($week = 1; $week <= BaselineRule::HISTORY_WEEKS; ++$week) {
            $hours[] = $wallHour - $week * self::HOURS_PER_WEEK;
        }

        return $hours;
    }

    /** The oldest wall hour a band at $wallHour can read; anything earlier may be removed. */
    public function oldestKept(int $wallHour): int
    {
        return $wallHour - BaselineRule::HISTORY_WEEKS * self::HOURS_PER_WEEK;
    }

    private static function floorDiv(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);

        return $dividend % $divisor !== 0 && $dividend < 0 ? $quotient - 1 : $quotient;
    }
}
