<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Domain;

use Kadupul\Alerting\Domain\Rule\BaselineCalendar;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use PHPUnit\Framework\TestCase;

final class BaselineCalendarTest extends TestCase
{
    private static function at(string $local, string $zone): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone($zone)))->getTimestamp();
    }

    public function testTheSameHourOfTheWeekIsTheSameWallClockHourAcrossADaylightSavingChange(): void
    {
        $zone = 'America/New_York';
        $calendar = new BaselineCalendar(new \DateTimeZone($zone));
        // Clocks went forward at 02:00 on Sunday 8 March 2026.
        $before = self::at('2026-03-02 09:30:00', $zone); // Monday, EST (UTC-5)
        $after = self::at('2026-03-09 09:10:00', $zone);  // Monday, EDT (UTC-4)

        // Only 167 real hours apart, yet the same hour of the week.
        self::assertSame(167 * 3600 - 20 * 60, $after - $before);
        self::assertSame($calendar->wallHour($before), $calendar->wallHour($after) - 168);
        self::assertSame($calendar->wallHour($before), $calendar->priorWeeks($calendar->wallHour($after))[0]);
        self::assertSame(9, $calendar->hourOfWeek($after), 'Monday 09:00 is hour 9 of the week.');
        self::assertSame($calendar->hourOfWeek($before), $calendar->hourOfWeek($after));

        // In UTC the two are different hours of the week: UTC would mix 09:00 and 10:00 local.
        $utc = new BaselineCalendar(new \DateTimeZone('UTC'));
        self::assertNotSame($utc->hourOfWeek($before), $utc->hourOfWeek($after));
    }

    public function testTheSkippedAndRepeatedHoursOfADaylightSavingChange(): void
    {
        $zone = 'America/New_York';
        $calendar = new BaselineCalendar(new \DateTimeZone($zone));

        // Spring forward: 01:59 EST is followed by 03:00 EDT, so wall hour 02 has no instant.
        $lastEst = self::at('2026-03-08 01:59:00', $zone);
        self::assertSame($calendar->wallHour($lastEst) + 2, $calendar->wallHour($lastEst + 60));

        // Fall back on 1 November 2026: both 01:xx hours are the same wall-clock hour and share one bucket.
        $firstOne = self::at('2026-11-01 01:30:00 EDT', $zone);
        $secondOne = $firstOne + 3600;
        self::assertSame('01:30 EST', (new \DateTimeImmutable('@' . $secondOne))->setTimezone(new \DateTimeZone($zone))->format('H:i T'));
        self::assertSame($calendar->wallHour($firstOne), $calendar->wallHour($secondOne));
    }

    public function testHourOfWeekStartsOnMonday(): void
    {
        $calendar = new BaselineCalendar(new \DateTimeZone('Europe/Berlin'));

        self::assertSame(0, $calendar->hourOfWeek(self::at('2026-11-09 00:15:00', 'Europe/Berlin')));
        self::assertSame(167, $calendar->hourOfWeek(self::at('2026-11-15 23:59:59', 'Europe/Berlin')));
        self::assertSame(24 * 3 + 14, $calendar->hourOfWeek(self::at('2026-11-12 14:00:00', 'Europe/Berlin')));
        self::assertSame('Europe/Berlin', $calendar->timeZone->getName());
    }

    public function testPriorWeeksAreTheHistoryKeptNewestFirst(): void
    {
        $calendar = new BaselineCalendar(new \DateTimeZone('UTC'));

        $weeks = $calendar->priorWeeks(10_000);
        self::assertCount(BaselineRule::HISTORY_WEEKS, $weeks);
        self::assertSame(8, BaselineRule::HISTORY_WEEKS);
        self::assertSame([9832, 9664, 9496, 9328, 9160, 8992, 8824, 8656], $weeks);
        self::assertSame(10_000 - 168 * 8, $calendar->oldestKept(10_000));
    }
}
