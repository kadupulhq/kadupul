<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Infrastructure;

use Kadupul\Alerting\Application\Port\Clock;
use Kadupul\Alerting\Infrastructure\Clock\SymfonyClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SymfonyClockTest extends TestCase
{
    public function testReturnsTheSymfonyInstantInUtcWithMicroseconds(): void
    {
        $mock = new MockClock(new \DateTimeImmutable('2027-01-15 09:00:00.250000', new \DateTimeZone('Europe/Stockholm')));
        $clock = new SymfonyClock($mock);

        self::assertInstanceOf(Clock::class, $clock);
        self::assertSame('2027-01-15T08:00:00.250000+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(1_800_000_000, $clock->now()->getTimestamp());

        $mock->sleep(1.5);
        self::assertSame('08:00:01.750000', $clock->now()->format('H:i:s.u'));
    }
}
