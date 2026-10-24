<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use Kadupul\Graphing\Application\Port\Clock;
use Kadupul\Graphing\Infrastructure\Clock\SymfonyClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SymfonyClockTest extends TestCase
{
    public function testImplementsTheModulePort(): void
    {
        self::assertInstanceOf(Clock::class, new SymfonyClock(new MockClock()));
    }

    public function testReturnsTheInstantTheSymfonyClockReports(): void
    {
        $clock = new SymfonyClock(new MockClock('2026-10-23 12:34:56', 'UTC'));

        self::assertSame('2026-10-23T12:34:56+00:00', $clock->now()->format(\DATE_ATOM));
    }

    public function testNormalisesAnotherTimeZoneToUtcWithoutMovingTheInstant(): void
    {
        $clock = new SymfonyClock(new MockClock(new \DateTimeImmutable('2026-10-23 14:34:56', new \DateTimeZone('Europe/Stockholm'))));

        $now = $clock->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertSame('2026-10-23T12:34:56+00:00', $now->format(\DATE_ATOM));
    }

    public function testKeepsMicroseconds(): void
    {
        $clock = new SymfonyClock(new MockClock('2026-10-23 12:34:56.123456', 'America/New_York'));

        self::assertSame('16:34:56.123456', $clock->now()->format('H:i:s.u'));
    }

    public function testFollowsTheSymfonyClockWhenItAdvances(): void
    {
        $mock = new MockClock('2026-10-23 12:34:56.000000', 'UTC');
        $clock = new SymfonyClock($mock);
        $before = $clock->now();

        $mock->sleep(1.5);

        self::assertSame('12:34:56.000000', $before->format('H:i:s.u'));
        self::assertSame('12:34:57.500000', $clock->now()->format('H:i:s.u'));
    }
}
