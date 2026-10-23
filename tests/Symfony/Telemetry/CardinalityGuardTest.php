<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Telemetry\Domain\CardinalityGuard;
use Kadupul\Telemetry\Domain\CardinalityLimitExceeded;
use PHPUnit\Framework\TestCase;

final class CardinalityGuardTest extends TestCase
{
    public function testAdmitsSeriesUpToTheLimit(): void
    {
        $guard = new CardinalityGuard(2);

        $guard->admit('interface.in_octets', 'a');
        $guard->admit('interface.in_octets', 'b');

        self::assertSame(2, $guard->count('interface.in_octets'));
    }

    public function testRejectsTheSeriesBeyondTheLimitWithAnExplicitError(): void
    {
        $guard = new CardinalityGuard(1);
        $guard->admit('interface.in_octets', 'a');

        try {
            $guard->admit('interface.in_octets', 'b');
            self::fail('Expected the limit to be enforced.');
        } catch (CardinalityLimitExceeded $error) {
            self::assertStringContainsString('interface.in_octets', $error->getMessage());
            self::assertStringContainsString('1', $error->getMessage());
        }
        self::assertSame(1, $guard->count('interface.in_octets'));
    }

    public function testAnAlreadyAdmittedSeriesIsStillAcceptedAtTheLimit(): void
    {
        $guard = new CardinalityGuard(1);
        $guard->admit('interface.in_octets', 'a');

        $guard->admit('interface.in_octets', 'a');

        self::assertSame(1, $guard->count('interface.in_octets'));
    }

    public function testTheLimitAppliesPerMetric(): void
    {
        $guard = new CardinalityGuard(1);
        $guard->admit('interface.in_octets', 'a');

        $guard->admit('interface.out_octets', 'a');

        self::assertSame(1, $guard->count('interface.out_octets'));
        self::assertSame(0, $guard->count('system.uptime'));
    }

    public function testALimitBelowOneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CardinalityGuard(0);
    }
}
